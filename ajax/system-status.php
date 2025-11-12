<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

header('Content-Type: application/json');

// Verificar status do banco de dados
try {
    $pdo->query('SELECT 1');
    $db_status = 'online';
} catch (Exception $e) {
    $db_status = 'offline';
}

// Verificar modo manutenção
$stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'manutencao'");
$stmt->execute();
$manutencao = $stmt->fetchColumn();

$status = 'online';
if ($manutencao == '1') {
    $status = 'maintenance';
} elseif ($db_status === 'offline') {
    $status = 'offline';
}

echo json_encode([
    'status' => $status,
    'database' => $db_status,
    'maintenance_mode' => $manutencao == '1',
    'timestamp' => date('Y-m-d H:i:s')
]);
?>