<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

header('Content-Type: application/json');

// Simular verificação de atualizações
// Em produção, isso verificaria um repositório oficial
$current_version = '1.0.0';
$latest_version = '1.0.0'; // Em produção, isso viria de uma API

$update_available = version_compare($latest_version, $current_version, '>');

echo json_encode([
    'current_version' => $current_version,
    'latest_version' => $latest_version,
    'update_available' => $update_available,
    'last_checked' => date('Y-m-d H:i:s')
]);
?>