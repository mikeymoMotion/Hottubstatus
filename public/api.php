<?php
// Hot tub status API.
//   GET  api.php                                  -> { ready, updatedAt }
//   POST api.php  { password, action: "login" }   -> check admin password
//   POST api.php  { password, ready: true|false } -> set status

header('Content-Type: application/json');
header('Cache-Control: no-store');

$dataFile = __DIR__ . '/status.json';

function respond($code, $body) {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function loadStatus($dataFile) {
    $status = @json_decode(@file_get_contents($dataFile), true);
    return is_array($status) ? $status : ['ready' => false, 'updatedAt' => gmdate('c')];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, loadStatus($dataFile));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'method not allowed']);
}

$configFile = __DIR__ . '/config.php';
$config = file_exists($configFile) ? include $configFile : [];
$adminPassword = $config['admin_password'] ?? '';

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$given = (string)($body['password'] ?? '');

if ($adminPassword === '' || $adminPassword === 'change-me' || !hash_equals($adminPassword, $given)) {
    respond(401, ['error' => 'unauthorized']);
}

if (($body['action'] ?? '') === 'login') {
    respond(200, ['ok' => true]);
}

$status = ['ready' => !empty($body['ready']), 'updatedAt' => gmdate('c')];
if (file_put_contents($dataFile, json_encode($status), LOCK_EX) === false) {
    respond(500, ['error' => 'could not save status']);
}
respond(200, $status);
