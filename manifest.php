<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Validate device name parameter
$device = $_GET['device'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $device)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid device name']);
    exit;
}

$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: rMesh-Website\r\n",
    'timeout' => 5,
]]);

// Optionaler ?tag= Parameter für ältere Versionen, sonst latest
// Optionaler ?channel=dev Parameter für Pre-Releases
$tag     = $_GET['tag']     ?? '';
$channel = isset($_GET['channel']) && $_GET['channel'] === 'dev' ? 'dev' : 'release';

if ($tag) {
    if (!preg_match('/^[Vv][a-zA-Z0-9._-]{1,30}$/', $tag)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid tag format']);
        exit;
    }
} elseif ($channel === 'dev') {
    $apiJson = @file_get_contents(
        'https://api.github.com/repos/DN9KGB/rMesh/releases',
        false, $ctx
    );
    if (!$apiJson) {
        http_response_code(503);
        echo json_encode(['error' => 'Could not fetch release info']);
        exit;
    }
    $tag = '';
    foreach (json_decode($apiJson) as $r) {
        if (!empty($r->prerelease)) { $tag = $r->tag_name; break; }
    }
    if (!$tag) {
        http_response_code(404);
        echo json_encode(['error' => 'No pre-release found']);
        exit;
    }
} else {
    $apiJson = @file_get_contents(
        'https://api.github.com/repos/DN9KGB/rMesh/releases/latest',
        false, $ctx
    );
    if (!$apiJson) {
        http_response_code(503);
        echo json_encode(['error' => 'Could not fetch release info']);
        exit;
    }
    $tag = json_decode($apiJson)->tag_name;
}

// Read device list from the exact tag being flashed
$devicesJson = @file_get_contents("https://raw.githubusercontent.com/DN9KGB/rMesh/$tag/devices.json", false, $ctx);

if (!$devicesJson) {
    http_response_code(503);
    echo json_encode(['error' => 'Could not load device list']);
    exit;
}

$devices = json_decode($devicesJson, true);
$dev = null;
foreach ($devices as $d) {
    if ($d['name'] === $device) {
        $dev = $d;
        break;
    }
}

if (!$dev) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown device']);
    exit;
}

$chip       = $dev['chip'];
$lfsOffset  = (int)$dev['lfs_offset'];
$isS3       = $chip === 'ESP32-S3';
$bootOffset = $isS3 ? 0x0000 : 0x1000;

// Route binary downloads through firmware.php so CORS is handled server-side
$proxyBase = "firmware.php?tag=$tag&file=";

$manifest = [
    'name'    => "rMesh \u{2013} $device",
    'version' => $tag,
    'builds'  => [[
        'chipFamily' => $chip,
        'parts'      => [
            ['path' => "{$proxyBase}{$device}_bootloader.bin", 'offset' => $bootOffset],
            ['path' => "{$proxyBase}{$device}_partitions.bin",  'offset' => 0x8000],
            ['path' => "{$proxyBase}{$device}_firmware.bin",    'offset' => 0x10000],
            ['path' => "{$proxyBase}{$device}_littlefs.bin",    'offset' => $lfsOffset],
        ],
    ]],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
