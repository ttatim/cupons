<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get' && isset($_GET['id'])) {
    $cupom_id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("
        SELECT c.*, l.nome as loja_nome 
        FROM cupons c 
        JOIN lojas l ON c.loja_id = l.id 
        WHERE c.id = ?
    ");
    $stmt->execute([$cupom_id]);
    $cupom = $stmt->fetch();
    
    if ($cupom) {
        echo json_encode(['success' => true, 'cupom' => $cupom]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Cupom não encontrado']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
?>