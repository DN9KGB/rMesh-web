<?php
/**
 * rMesh Admin – Nodes
 */
require_once 'auth.php';
require_auth();

$db = get_db();

require_once dirname(__DIR__) . '/ota_log_helper.php';
_ensureLastVersionCheckColumn($db);

$maxAge = max(600, min(2592000, (int)($_GET['max_age'] ?? 86400)));
$cutoff = time() - $maxAge;
$search = trim($_GET['q'] ?? '');
$band   = in_array($_GET['band'] ?? '', ['433', '868']) ? $_GET['band'] : '';

$params = [':cutoff' => $cutoff];
$where  = 'WHERE n.last_seen >= :cutoff';
if ($search !== '') {
    $where .= ' AND n.call LIKE :q';
    $params[':q'] = '%' . $search . '%';
}
if ($band !== '') {
    $where .= ' AND n.band = :band';
    $params[':band'] = $band;
}

$nodes = $db->prepare("
    SELECT n.`call`, n.band, n.chip_id, n.position, n.last_seen, n.last_version_check,
           COUNT(DISTINCT p.peer_call) AS peer_count,
           COUNT(DISTINCT r.src_call)  AS route_count,
           ota.device, ota.version_to AS firmware
    FROM rmesh_nodes n
    LEFT JOIN rmesh_peers  p   ON p.reporter_call = n.`call` AND p.last_seen >= :cutoff AND p.available = 1
    LEFT JOIN rmesh_routes r   ON r.reporter_call = n.`call` AND r.last_seen >= :cutoff
    LEFT JOIN rmesh_ota_log ota ON ota.id = (
        SELECT id FROM rmesh_ota_log
        WHERE `call` = n.`call` AND version_to IS NOT NULL AND version_to != ''
        ORDER BY timestamp DESC LIMIT 1
    )
    $where
    GROUP BY n.`call`, n.band, n.chip_id, n.position, n.last_seen, ota.device, ota.version_to
    ORDER BY n.last_seen DESC
");
$nodes->execute($params);
$rows = $nodes->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rMesh – Admin Nodes</title>
    <?php include '_head_styles.php'; ?>
</head>
<body>
<?php include '_nav.php'; ?>
<div class="page">
    <h1 class="page-title">Nodes <span style="font-size:1rem;color:#888;font-weight:400;">(<?= count($rows) ?>)</span></h1>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Rufzeichen suchen…" value="<?= htmlspecialchars($search) ?>">
        <label>Zeitraum:
            <select name="max_age" onchange="this.form.submit()">
                <option value="3600"   <?= $maxAge===3600   ?'selected':'' ?>>1 Stunde</option>
                <option value="86400"  <?= $maxAge===86400  ?'selected':'' ?>>24 Stunden</option>
                <option value="604800" <?= $maxAge===604800 ?'selected':'' ?>>7 Tage</option>
                <option value="2592000"<?= $maxAge===2592000?'selected':'' ?>>30 Tage</option>
            </select>
        </label>
        <label>Band:
            <select name="band" onchange="this.form.submit()">
                <option value=""    <?= $band===''    ?'selected':'' ?>>Alle</option>
                <option value="433" <?= $band==='433' ?'selected':'' ?>>433 MHz</option>
                <option value="868" <?= $band==='868' ?'selected':'' ?>>868 MHz</option>
            </select>
        </label>
        <button type="submit" style="padding:7px 14px;background:#0f3460;color:#4ecca3;border:1px solid #4ecca3;border-radius:6px;cursor:pointer;font-size:0.875rem;">Suchen</button>
    </form>

    <div class="table-wrap">
        <table data-sortable>
            <thead>
                <tr>
                    <th>Rufzeichen</th>
                    <th>Band</th>
                    <th>Chip-ID</th>
                    <th>Gerät</th>
                    <th>Firmware</th>
                    <th>Position</th>
                    <th>Zuletzt gesehen</th>
                    <th>Alter</th>
                    <th>Version Check</th>
                    <th>Peers</th>
                    <th>Routen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="strong mono"><?= htmlspecialchars($r['call']) ?></td>
                    <td class="mono" data-sort="<?= htmlspecialchars($r['band'] ?? '') ?>"><?php
                        $bc = $r['band'] === '868' ? '#f59e0b' : '#4ecca3';
                        echo '<span style="color:' . $bc . ';font-weight:600;">' . htmlspecialchars($r['band'] ?? '—') . ' MHz</span>';
                    ?></td>
                    <td class="mono"><?= $r['chip_id'] !== '' ? htmlspecialchars($r['chip_id']) : '<span class="muted">—</span>' ?></td>
                    <td class="mono"><?= htmlspecialchars($r['device'] ?? '—') ?></td>
                    <td class="mono" data-sort="<?= htmlspecialchars($r['firmware'] ?? '') ?>"><?= $r['firmware'] ? '<span class="badge badge-info">' . htmlspecialchars($r['firmware']) . '</span>' : '<span class="muted">—</span>' ?></td>
                    <td class="mono"><?= htmlspecialchars($r['position'] ?? '—') ?></td>
                    <td class="mono" data-sort="<?= $r['last_seen'] ?>"><?= date('d.m.Y H:i:s', $r['last_seen']) ?></td>
                    <td class="muted" data-sort="<?= $r['last_seen'] ?>"><?= timeAgo($r['last_seen']) ?></td>
                    <td class="muted" data-sort="<?= $r['last_version_check'] ?? 0 ?>"><?= $r['last_version_check'] ? timeAgo((int)$r['last_version_check']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $r['peer_count'] ?></td>
                    <td><?= $r['route_count'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="11" style="text-align:center;color:#555;padding:20px;">Keine Nodes gefunden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
function timeAgo(int $ts): string {
    $d = time() - $ts;
    if ($d < 60)   return $d . 's';
    if ($d < 3600) return floor($d/60) . ' min';
    if ($d < 86400) return floor($d/3600) . ' h';
    return floor($d/86400) . ' Tage';
}
?>
</body>
</html>
