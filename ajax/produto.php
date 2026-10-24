<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
header('Content-Type: text/html; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$loja = trim((string)($_GET['loja'] ?? ''));
$ordenar = (string)($_GET['ord'] ?? 'recente');
$page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
$perPage = max(6, min(48, (int)($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;
$where = ['p.ativo = 1', 'l.ativo = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(p.nome LIKE :q_nome OR p.descricao LIKE :q_descricao)';
    $params[':q_nome'] = '%' . $q . '%';
    $params[':q_descricao'] = '%' . $q . '%';
}
if ($loja !== '') {
    $where[] = '(l.slug = :loja_slug OR l.nome LIKE :loja_nome)';
    $params[':loja_slug'] = $loja;
    $params[':loja_nome'] = '%' . $loja . '%';
}

$order = match ($ordenar) {
    'preco_asc' => 'p.preco ASC, p.id DESC',
    'preco_desc' => 'p.preco DESC, p.id DESC',
    default => 'p.created_at DESC, p.id DESC',
};

$sql = 'SELECT p.id, p.nome, p.descricao, p.preco, p.imagens, l.nome AS loja_nome
    FROM produtos p JOIN lojas l ON l.id = p.loja_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY ' . $order . ' LIMIT :limite OFFSET :deslocamento';

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limite', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':deslocamento', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('[ajax/produto.php] ' . $exception->getMessage());
    http_response_code(500);
    exit('<div class="alert alert-danger">Não foi possível carregar os produtos.</div>');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-4">
<?php foreach ($produtos as $produto):
    $imagens = json_decode((string)($produto['imagens'] ?? '[]'), true);
    $imagem = is_array($imagens) && isset($imagens[0]) && is_string($imagens[0]) ? $imagens[0] : '';
    if ($imagem !== '' && (!filter_var($imagem, FILTER_VALIDATE_URL) || parse_url($imagem, PHP_URL_SCHEME) !== 'https')) {
        $imagem = '';
    }
?>
    <div class="col"><article class="card h-100 shadow-sm">
        <?php if ($imagem !== ''): ?>
        <img src="<?= $escape($imagem) ?>" alt="<?= $escape($produto['nome']) ?>" class="card-img-top" loading="lazy">
        <?php endif; ?>
        <div class="card-body d-flex flex-column">
            <p class="text-muted small mb-2"><?= $escape($produto['loja_nome']) ?></p>
            <h2 class="h6"><a href="oferta.php?id=<?= (int)$produto['id'] ?>" class="text-decoration-none"><?= $escape($produto['nome']) ?></a></h2>
            <p class="text-success fw-bold">R$ <?= $escape(number_format((float)$produto['preco'], 2, ',', '.')) ?></p>
            <a href="go.php?tipo=produto&amp;id=<?= (int)$produto['id'] ?>" class="btn btn-primary mt-auto" rel="nofollow sponsored">ir para pagamento</a>
        </div>
    </article></div>
<?php endforeach; ?>
<?php if ($produtos === []): ?>
    <div class="col-12"><div class="alert alert-info">Nenhum produto encontrado.</div></div>
<?php endif; ?>
</div>
