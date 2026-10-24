<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/offer_metadata_importer.php';

$tipo = (string)($_GET['tipo'] ?? '');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!in_array($tipo, ['produto', 'cupom'], true) || !$id || $id <= 0) {
    http_response_code(400);
    exit('Link inválido.');
}
$table = $tipo === 'produto' ? 'produtos' : 'cupons';
$stmt = $pdo->prepare("SELECT link_afiliado FROM $table WHERE id = ? AND ativo = 1 LIMIT 1");
$stmt->execute([$id]);
$url = $stmt->fetchColumn();
if (!$url) {
    http_response_code(404);
    exit('Oferta indisponível.');
}
try {
    $url = OfferMetadataImporter::validatePublicUrl((string)$url);
} catch (InvalidArgumentException $e) {
    http_response_code(410);
    exit('O destino desta oferta precisa ser atualizado.');
}
try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE $table SET cliques = cliques + 1 WHERE id = ?")->execute([$id]);
    $pdo->prepare("INSERT INTO estatisticas (tipo, item_id, data_acesso, cliques) VALUES (?, ?, CURDATE(), 1)
        ON DUPLICATE KEY UPDATE cliques = cliques + 1")->execute([$tipo, $id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[go.php] Erro ao contabilizar clique: ' . $e->getMessage());
}
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Location: ' . $url, true, 302);
exit;
