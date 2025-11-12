<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notificacao_id = $input['notificacao_id'] ?? null;
    
    if ($notificacao_id) {
        try {
            $stmt = $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$notificacao_id, $_SESSION['user_id']]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'ID da notificação não fornecido']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
}
?>