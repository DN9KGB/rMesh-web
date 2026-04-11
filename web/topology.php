<?php
/**
 * rMesh Topology Query Endpoint
 * Gibt die bekannte Netz-Topologie als JSON zurück.
 *
 * GET Parameter:
 *   max_age  (Sekunden, default 7200)
 *   band     "433" oder "868" – filtert auf ein Frequenzband. Ohne Angabe: alle Nodes.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$maxAge = max(600, min(86400, (int)(isset($_GET['max_age']) ? $_GET['max_age'] : 7200)));
$cutoff = time() - $maxAge;

$bandFilter = null;
if (isset($_GET['band']) && in_array($_GET['band'], array('433', '868'))) {
    $bandFilter = $_GET['band'];
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $db  = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

    // Nodes
    $nodes = array();
    $nodeParams = array(':cutoff' => $cutoff);
    $bandWhere  = '';
    if ($bandFilter !== null) {
        $bandWhere = ' AND `band` = :band';
        $nodeParams[':band'] = $bandFilter;
    }

    $stmt = $db->prepare("SELECT n.`call`, n.`position`, n.`last_seen`, n.`band`, n.`chip_id`, n.`is_afu`,
            COALESCE(NULLIF(n.`device`, ''), ota.device) AS hw_model,
            COALESCE(NULLIF(n.`version`, ''), ota.version_to) AS fw_version
        FROM rmesh_nodes n
        LEFT JOIN rmesh_ota_log ota ON ota.id = (
            SELECT id FROM rmesh_ota_log
            WHERE `call` = n.`call` AND version_to IS NOT NULL AND version_to != ''
            ORDER BY timestamp DESC LIMIT 1
        )
        WHERE n.`last_seen` >= :cutoff" . ($bandFilter !== null ? ' AND n.`band` = :band' : '') . "
        ORDER BY n.`call`");
    $stmt->execute($nodeParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $node = array(
            'call'      => $row['call'],
            'position'  => $row['position'],
            'last_seen' => (int)$row['last_seen'],
            'band'      => $row['band'],
            'chip_id'   => $row['chip_id'],
            'is_afu'    => (bool)$row['is_afu'],
            'hw'        => $row['hw_model'] ?? '',
            'fw'        => $row['fw_version'] ?? '',
        );
        $pos = parsePosition($row['position']);
        if ($pos !== null) {
            $node['lat'] = $pos[0];
            $node['lon'] = $pos[1];
        }
        $nodes[] = $node;
    }

    // Bekannte Calls für Ghost-Node-Erkennung und Band-Filter bei Peers/Routen
    $knownCalls = array();
    foreach ($nodes as $n) { $knownCalls[$n['call']] = true; }

    // Edges: deduplizierte direkte Peer-Verbindungen
    // Beim Band-Filter: nur Peers von Nodes des gewählten Bands
    $edgeMap = array();
    $peerParams = array(':cutoff' => $cutoff);
    $peerJoin   = '';
    $peerWhere  = '';
    if ($bandFilter !== null) {
        $peerJoin  = " JOIN rmesh_nodes n ON n.`call` = p.`reporter_call` AND n.`band` = :band";
        $peerWhere = '';
        $peerParams[':band'] = $bandFilter;
    }

    $stmt = $db->prepare("
        SELECT p.`reporter_call`, p.`peer_call`, p.`rssi`, p.`snr`, p.`port`, p.`last_seen`
        FROM rmesh_peers p{$peerJoin}
        WHERE p.`last_seen` >= :cutoff AND p.`available` = 1
        ORDER BY p.`last_seen` DESC
    ");
    $stmt->execute($peerParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $a = $row['reporter_call'];
        $b = $row['peer_call'];
        $key = (strcmp($a, $b) <= 0 ? "$a|$b" : "$b|$a") . '|' . $row['port'];
        if (!isset($edgeMap[$key])) {
            $edgeMap[$key] = array(
                'from'      => $a,
                'to'        => $b,
                'rssi'      => (float)$row['rssi'],
                'snr'       => (float)$row['snr'],
                'port'      => (int)$row['port'],
                'last_seen' => (int)$row['last_seen'],
            );
        }
    }

    // Ghost-Nodes: Peers die gesehen wurden aber sich selbst nicht gemeldet haben
    $ghostParams = array(':cutoff' => $cutoff);
    $ghostJoin   = '';
    if ($bandFilter !== null) {
        $ghostJoin = " JOIN rmesh_nodes n ON n.`call` = p.`reporter_call` AND n.`band` = :band";
        $ghostParams[':band'] = $bandFilter;
    }

    $stmt = $db->prepare("
        SELECT p.peer_call, MAX(p.last_seen) AS last_seen
        FROM rmesh_peers p{$ghostJoin}
        WHERE p.last_seen >= :cutoff AND p.available = 1
        GROUP BY p.peer_call
    ");
    $stmt->execute($ghostParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($knownCalls[$row['peer_call']])) {
            $nodes[] = array(
                'call'      => $row['peer_call'],
                'position'  => '',
                'last_seen' => (int)$row['last_seen'],
                'band'      => $bandFilter ?? 'unknown',
                'chip_id'   => '',
                'is_afu'    => $bandFilter !== '868',
                'ghost'     => true,
            );
        }
    }

    // Route-Hints
    $routeEdges  = array();
    $routeParams = array(':cutoff' => $cutoff);
    $routeJoin   = '';
    if ($bandFilter !== null) {
        $routeJoin = " JOIN rmesh_nodes n ON n.`call` = r.`reporter_call` AND n.`band` = :band";
        $routeParams[':band'] = $bandFilter;
    }

    $stmt = $db->prepare("
        SELECT r.`reporter_call`, r.`src_call`, r.`via_call`, r.`hop_count`, r.`last_seen`
        FROM rmesh_routes r{$routeJoin}
        WHERE r.`last_seen` >= :cutoff
        ORDER BY r.`hop_count` ASC
    ");
    $stmt->execute($routeParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['reporter_call'] . '|' . $row['src_call'];
        $routeEdges[$key] = array(
            'reporter'  => $row['reporter_call'],
            'src'       => $row['src_call'],
            'via'       => $row['via_call'],
            'hops'      => (int)$row['hop_count'],
            'last_seen' => (int)$row['last_seen'],
        );
    }

    echo json_encode(array(
        'nodes'       => array_values($nodes),
        'edges'       => array_values($edgeMap),
        'route_hints' => array_values($routeEdges),
        'generated'   => time(),
        'max_age'     => $maxAge,
        'band'        => $bandFilter,
    ));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => 'Database error', 'detail' => $e->getMessage()));
    error_log('rMesh topology.php: ' . $e->getMessage());
}

function parsePosition($pos) {
    $pos = trim($pos);
    if ($pos === '') return null;

    // lat,lon Format
    if (strpos($pos, ',') !== false) {
        $parts = explode(',', $pos, 2);
        $lat = (float)trim($parts[0]);
        $lon = (float)trim($parts[1]);
        if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
            return array($lat, $lon);
        }
        return null;
    }

    // Maidenhead-Locator (JN48 oder JN48mw)
    if (preg_match('/^([A-R]{2})(\d{2})([A-X]{2})?$/i', $pos, $m)) {
        $lon = (ord(strtoupper($m[1][0])) - ord('A')) * 20 - 180;
        $lat = (ord(strtoupper($m[1][1])) - ord('A')) * 10 - 90;
        $lon += (int)$m[2][0] * 2;
        $lat += (int)$m[2][1];
        if (!empty($m[3])) {
            $lon += (ord(strtolower($m[3][0])) - ord('a')) * (2.0 / 24) + (1.0 / 24);
            $lat += (ord(strtolower($m[3][1])) - ord('a')) * (1.0 / 24) + (0.5 / 24);
        } else {
            $lon += 1.0;
            $lat += 0.5;
        }
        return array(round($lat, 5), round($lon, 5));
    }

    return null;
}
