<?php
exit;
}


$stmt = $pdo->prepare('SELECT p.*, l.nome AS loja_nome, l.slug AS loja_slug FROM produtos p JOIN lojas l ON l.id = p.loja_id WHERE p.id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$p) {
http_response_code(404);
echo '<main class="container"><div class="alert alert-error">Oferta não encontrada.</div></main>';
@require_once $root . '/includes/footer.php';
exit;
}


$preco = $p['preco_promocional'] ?? $p['preco'];
?>
<main class="container oferta">
<article class="oferta-detalhe">
<header>
<h1><?= htmlspecialchars($p['titulo']) ?></h1>
<div class="loja">Loja: <a href="/loja.php?slug=<?= urlencode($p['loja_slug']) ?>"><?=
htmlspecialchars($p['loja_nome']) ?></a></div>
</header>


<div class="oferta-content">
<div class="galeria">
<?php if (!empty($p['imagem'])): ?>
<img src="/assets/uploads/produtos/<?= htmlspecialchars($p['imagem']) ?>" alt="<?= htmlspecialchars($p['titulo']) ?>">
<?php else: ?>
<img src="/assets/images/produtos/placeholder.png" alt="<?= htmlspecialchars($p['titulo']) ?>">
<?php endif; ?>
</div>
<div class="dados">
<div class="preco">
<?php if (!empty($p['preco_promocional'])): ?>
<span class="de">R$ <?= number_format($p['preco'], 2, ',', '.') ?></span>
<span class="por">R$ <?= number_format($p['preco_promocional'], 2, ',', '.') ?></span>
<?php else: ?>
<span class="por">R$ <?= number_format($p['preco'], 2, ',', '.') ?></span>
<?php endif; ?>
</div>
<?php if (!empty($p['descricao'])): ?>
<div class="descricao"><?= nl2br(htmlspecialchars($p['descricao'])) ?></div>
<?php endif; ?>
<?php if (!empty($p['url'])): ?>
<a class="btn btn-primaria" href="<?= htmlspecialchars($p['url']) ?>" target="_blank" rel="nofollow noopener">Ir para a oferta</a>
<?php endif; ?>
</div>
</div>
</article>
</main>


<?php @require_once $root . '/includes/footer.php'; ?>