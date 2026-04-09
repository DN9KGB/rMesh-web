<?php
// OTA-Endpunkt für Nodes: validiert Parameter, lädt das Asset von GitHub
// und streamt es an den Client. KEIN Redirect – ESP32 HTTPUpdate kommt mit
// Cross-Origin-Redirects (github.com → release-assets.githubusercontent.com)
// nicht zuverlässig zurecht.

$file   = $_GET['file']   ?? '';
$call   = isset($_GET['call'])   ? strtoupper(substr(preg_replace('/[^A-Z0-9\/\-]/', '', strtoupper($_GET['call'])), 0, 16)) : '';
$device = isset($_GET['device']) ? substr($_GET['device'], 0, 64) : '';
$tag    = isset($_GET['tag'])    ? substr($_GET['tag'], 0, 64) : '';

// Strikte Allowlist
if (!preg_match('/^[a-zA-Z0-9_\-]+\.bin$/', $file)) {
    http_response_code(400);
    exit('Invalid file');
}
if ($tag !== '' && !preg_match('/^([Vv]\d+\.\d+\.\d+[a-zA-Z0-9\-]*|nightly-\d{4}-\d{2}-\d{2})$/', $tag)) {
    http_response_code(400);
    exit('Invalid tag');
}

if ($tag !== '') {
    $url = "https://github.com/DN9KGB/rMesh/releases/download/$tag/$file";
} else {
    $url = "https://github.com/DN9KGB/rMesh/releases/latest/download/$file";
}

// DB-Logging des Update-Starts (vor dem Stream, damit es auch bei Abbruch erscheint)
require_once __DIR__ . '/ota_log_helper.php';
logOtaEvent($call, $device, 'update_start', '', $tag, $file);

$ctx = stream_context_create(['http' => [
    'header'          => "User-Agent: rMesh-Website\r\n",
    'timeout'         => 60,
    'follow_location' => 1,
    'max_redirects'   => 10,
]]);

$fh = @fopen($url, 'rb', false, $ctx);
if (!$fh) {
    http_response_code(502);
    exit('Could not fetch firmware from GitHub');
}

// HTTP-Status aus Wrapper-Daten extrahieren
$meta   = stream_get_meta_data($fh);
$status = 200;
$contentLength = null;
foreach ($meta['wrapper_data'] as $line) {
    if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
        $status = (int)$m[1];
    } elseif (preg_match('#^Content-Length:\s*(\d+)#i', $line, $m)) {
        $contentLength = (int)$m[1];
    }
}
if ($status >= 400) {
    fclose($fh);
    http_response_code($status);
    exit("GitHub returned HTTP $status");
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file . '"');
if ($contentLength !== null) {
    header('Content-Length: ' . $contentLength);
}
// Pre-releases (Tag mit '-') können neu gepusht werden → kein Caching
$cacheControl = ($tag === '' || strpos($tag, '-') !== false) ? 'no-store' : 'public, max-age=86400';
header("Cache-Control: $cacheControl");

// Output-Buffering aus, damit ESP32 direkt den Body bekommt
while (ob_get_level()) {
    ob_end_clean();
}

fpassthru($fh);
fclose($fh);
