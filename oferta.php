<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Identificador de oferta inválido.');
}

$stmt = $pdo->prepare('SELECT p.id, p.nome, p.descricao, p.preco, p.preco_original, p.imagens, l.nome AS loja_nome
    FROM produtos p INNER JOIN lojas l ON l.id = p.loja_id
    WHERE p.id = ? AND p.ativo = 1 LIMIT 1');
$stmt->execute([$id]);
$produto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$produto) {
    http_response_code(404);
    exit('Oferta não encontrada.');
}

$imagens = json_decode((string)($produto['imagens'] ?? '[]'), true);
$imagem = is_array($imagens) && isset($imagens[0]) && is_string($imagens[0])
    ? $imagens[0] : '';
if ($imagem !== '' && (!filter_var($imagem, FILTER_VALIDATE_URL) || parse_url($imagem, PHP_URL_SCHEME) !== 'https')) {
    $imagem = '';
}
$escape = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$preco = number_format((float)$produto['preco'], 2, ',', '.');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($produto['nome']) ?> | Cupons</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<header class="bg-primary text-white py-3"><div class="container">
    <a href="index.php" class="text-white text-decoration-none fw-bold">Cupons</a>
</div></header>
<main class="container py-5">
    <article class="card shadow-sm overflow-hidden">
        <div class="row g-0">
            <?php if ($imagem !== ''): ?>
            <div class="col-md-5"><img src="<?= $escape($imagem) ?>" class="img-fluid w-100" alt="<?= $escape($produto['nome']) ?>" loading="lazy"></div>
            <?php endif; ?>
            <div class="<?= $imagem !== '' ? 'col-md-7' : 'col-12' ?> p-4">
                <p class="text-muted mb-2"><?= $escape($produto['loja_nome']) ?></p>
                <h1 class="h3"><?= $escape($produto['nome']) ?></h1>
                <p class="fs-3 fw-bold text-success">R$ <?= $escape($preco) ?></p>
                <p class="text-body-secondary"><?= nl2br($escape($produto['descricao'])) ?></p>
                <a class="btn btn-primary btn-lg" href="go.php?tipo=produto&amp;id=<?= (int)$produto['id'] ?>" rel="nofollow sponsored">ir para pagamento</a>
            </div>
        </div>
    </article>
</main>
</body>
</html>
