<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE lida = 0 AND usuario_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $total_nao_lidas = $stmt->fetchColumn();
    
    echo json_encode(['total_nao_lidas' => $total_nao_lidas]);
} catch (Exception $e) {
    echo json_encode(['total_nao_lidas' => 0]);
}
?>