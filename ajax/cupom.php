<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
header('Content-Type: text/html; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$loja = trim((string)($_GET['loja'] ?? ''));
$page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
$perPage = max(5, min(50, (int)($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;
$where = ['c.ativo = 1', 'l.ativo = 1', 'c.data_inicio <= CURDATE()', 'c.data_fim >= CURDATE()'];
$params = [];

if ($q !== '') {
    $where[] = '(c.codigo LIKE :q_codigo OR c.descricao LIKE :q_descricao)';
    $params[':q_codigo'] = '%' . $q . '%';
    $params[':q_descricao'] = '%' . $q . '%';
}
if ($loja !== '') {
    $where[] = '(l.slug = :loja_slug OR l.nome LIKE :loja_nome)';
    $params[':loja_slug'] = $loja;
    $params[':loja_nome'] = '%' . $loja . '%';
}

$sql = 'SELECT c.id, c.descricao, c.codigo, c.tipo, c.valor, c.data_fim, l.nome AS loja_nome
    FROM cupons c JOIN lojas l ON l.id = c.loja_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY c.data_fim ASC, c.id DESC LIMIT :limite OFFSET :deslocamento';

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limite', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':deslocamento', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $cupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('[ajax/cupom.php] ' . $exception->getMessage());
    http_response_code(500);
    exit('<div class="alert alert-danger">Não foi possível carregar os cupons.</div>');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="row row-cols-1 row-cols-md-2 g-4">
<?php foreach ($cupons as $cupom):
    $desconto = $cupom['tipo'] === 'percentual'
        ? number_format((float)$cupom['valor'], 0, ',', '.') . '%'
        : 'R$ ' . number_format((float)$cupom['valor'], 2, ',', '.');
?>
    <div class="col"><article class="card h-100 shadow-sm">
        <div class="card-body d-flex flex-column">
            <p class="text-muted small mb-2"><?= $escape($cupom['loja_nome']) ?></p>
            <h2 class="h6"><?= $escape($cupom['descricao']) ?></h2>
            <p class="text-success fw-bold"><?= $escape($desconto) ?> de desconto</p>
            <p class="small">Código: <code><?= $escape($cupom['codigo']) ?></code></p>
            <p class="text-muted small">Válido até <?= $escape(date('d/m/Y', strtotime((string)$cupom['data_fim']))) ?></p>
            <a href="go.php?tipo=cupom&amp;id=<?= (int)$cupom['id'] ?>" class="btn btn-primary mt-auto" rel="nofollow sponsored">Pegue seu cupom</a>
        </div>
    </article></div>
<?php endforeach; ?>
<?php if ($cupons === []): ?>
    <div class="col-12"><div class="alert alert-info">Nenhum cupom encontrado.</div></div>
<?php endif; ?>
</div>
