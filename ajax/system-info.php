<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

header('Content-Type: application/json');

$response = [
    'memory_usage' => number_format(memory_get_usage() / 1024 / 1024, 2),
    'peak_memory' => number_format(memory_get_peak_usage() / 1024 / 1024, 2),
    'load_time' => number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3),
    'system_status' => 'online',
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response);
?>