<?php
/**
 * rMesh Remote Command Log Endpoint
 * Empfängt Meldungen über eingehende Remote Commands.
 *
 * POST Body (JSON):
 * {
 *   "call":    "DN9KGB",
 *   "sender":  "OE3XYZ",
 *   "command": "version"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Method Not Allowed'));
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || empty($data['call']) || empty($data['command'])) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid payload'));
    exit;
}

$allowed_commands = array('version', 'reboot');

$call    = strtoupper(substr(preg_replace('/[^A-Z0-9\/\-]/', '', strtoupper($data['call'])), 0, 16));
$sender  = isset($data['sender']) ? strtoupper(substr(preg_replace('/[^A-Z0-9\/\-]/', '', strtoupper($data['sender'])), 0, 16)) : '';
$command = substr(preg_replace('/[^a-z_]/', '', strtolower($data['command'])), 0, 32);

if (empty($call) || !in_array($command, $allowed_commands)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid callsign or command'));
    exit;
}

require_once __DIR__ . '/command_log_helper.php';
logCommandEvent($call, $sender, $command);
echo json_encode(array('ok' => true));
