<?php
$call    = isset($_GET['call'])    ? strtoupper(substr(preg_replace('/[^A-Z0-9\/\-]/', '', strtoupper($_GET['call'])), 0, 16)) : '';
$device  = isset($_GET['device'])  ? substr($_GET['device'],  0, 64) : '';
$version = isset($_GET['version']) ? substr($_GET['version'], 0, 32) : '';
$channel = isset($_GET['channel']) && $_GET['channel'] === 'dev' ? 'dev' : 'release';

$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: rMesh-Website\r\n",
    'timeout' => 5,
]]);

if ($channel === 'dev') {
    $json = @file_get_contents('https://api.github.com/repos/DN9KGB/rMesh/releases', false, $ctx);
    $latest = '';
    if ($json) {
        $releases = json_decode($json);
        foreach ($releases as $r) {
            if (!empty($r->prerelease) && !str_starts_with($r->tag_name, 'nightly-')) { $latest = $r->tag_name; break; }
        }
    }
    if (!$latest) {
        // Fallback: kein Pre-release vorhanden → stable
        $json2 = @file_get_contents('https://api.github.com/repos/DN9KGB/rMesh/releases/latest', false, $ctx);
        $latest = $json2 ? json_decode($json2)->tag_name : '';
    }
} else {
    $json = @file_get_contents('https://api.github.com/repos/DN9KGB/rMesh/releases/latest', false, $ctx);
    $latest = $json ? json_decode($json)->tag_name : '';
}

// Dirty/nightly builds dürfen niemals per Auto-Update überschrieben werden
$isDirty = $version !== '' && (stripos($version, 'dirty') !== false || stripos($version, 'nightly') !== false || preg_match('/-b\d+$/i', trim($version)));
// Auf dem release-Channel niemals eine Pre-Release-/Dev-Version (Tag mit '-') auf einen
// älteren Stable-Tag "abwärts" updaten – sonst würde z.B. v1.0.31b-dev auf v1.0.29a
// downgegradet, sobald GitHub /releases/latest älter ist als die installierte Vorschau.
if (!$isDirty && $channel === 'release' && $version !== '' && strpos($version, '-') !== false) {
    $isDirty = true;
}
if ($isDirty) {
    $latest = $version;
}

// Gleiche Version (case-insensitive, getrimmt) → kein Update anbieten
if ($latest && $version && strcasecmp(trim($latest), trim($version)) === 0) {
    $latest = $version;
}

if ($latest) {
    $body     = json_encode(['version' => $latest]);
    $logEvent = 'version_check';
    $logError = '';
} else {
    $body     = json_encode(['version' => 'unknown']);
    $logEvent = 'version_check_failed';
    $logError = 'GitHub API nicht erreichbar';
    http_response_code(503);
}

// Antwort sofort und vollständig senden – DB-Arbeit darf die Firmware nicht blockieren
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level()) ob_end_flush();
    flush();
}

// DB-Logging nach Response
require_once __DIR__ . '/ota_log_helper.php';
if ($logEvent === 'version_check') {
    updateLastVersionCheck($call);
    if ($version && $version !== $latest) {
        logOtaEvent($call, $device, 'update_found', $version, $latest, '');
    }
} else {
    logOtaEvent($call, $device, $logEvent, $version, $latest, $logError);
}
