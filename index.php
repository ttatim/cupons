<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
$produtos = $pdo->query("SELECT p.id,p.nome,p.descricao,p.preco,p.preco_original,p.imagens,l.nome AS loja FROM produtos p JOIN lojas l ON l.id=p.loja_id WHERE p.ativo=1 ORDER BY p.created_at DESC LIMIT 16")->fetchAll(PDO::FETCH_ASSOC);
$cupons = $pdo->query("SELECT c.id,c.descricao,c.codigo,c.tipo,c.valor,c.data_fim,l.nome AS loja FROM cupons c JOIN lojas l ON l.id=c.loja_id WHERE c.ativo=1 AND c.data_fim>=CURDATE() ORDER BY c.created_at DESC LIMIT 16")->fetchAll(PDO::FETCH_ASSOC);
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cupons e ofertas</title><meta name="description" content="Produtos, promoções e cupons em um só lugar.">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f5f7fc}.site-header{background:#194aa6}.offer{border:0;border-radius:1rem;box-shadow:0 3px 20px #173b7018;height:100%}.offer img{height:180px;object-fit:contain;padding:12px}.offer .btn-primary{background:#1769df;border-color:#1769df}.summary{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;min-height:4em}</style></head>
<body><header class="site-header py-3"><div class="container d-flex justify-content-between align-items-center"><a href="index.php" class="text-white text-decoration-none h4 mb-0">Cupons & Ofertas</a><a class="btn btn-outline-light btn-sm" href="admin/login.php">Área administrativa</a></div></header>
<main class="container py-5"><h1 class="display-6 fw-bold">Ofertas selecionadas</h1><p class="text-secondary mb-5">Consulte preços, garantias e condições na loja de destino.</p>
<section class="mb-5"><h2 class="h3 mb-4">Produtos</h2><div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4 g-4">
<?php foreach($produtos as $p):
$images=json_decode((string)($p['imagens']??'[]'),true);
$img=is_array($images)?(string)($images[0]??''):'';
if($img!=='' && !str_starts_with($img,'https://')){$img='assets/images/produtos/'.basename($img);}
?>
<div class="col"><article class="card offer">
<?php if($img!==''):?><img src="<?= h($img) ?>" alt="<?= h($p['nome']) ?>" class="card-img-top" loading="lazy"><?php endif;?>
<div class="card-body d-flex flex-column"><small class="text-muted"><?= h($p['loja']) ?></small><h3 class="h6 mt-2"><?= h($p['nome']) ?></h3>
<p class="text-secondary small summary"><?= h(mb_substr((string)$p['descricao'],0,180)) ?></p>
<?php if($p['preco_original']!==null && (float)$p['preco_original']>(float)$p['preco']):?><small class="text-decoration-line-through text-secondary">R$ <?= number_format((float)$p['preco_original'],2,',','.') ?></small><?php endif;?>
<p class="text-primary fw-bold fs-5">R$ <?= number_format((float)$p['preco'],2,',','.') ?></p>
<a class="btn btn-primary w-100 mt-auto" href="go.php?tipo=produto&amp;id=<?= (int)$p['id'] ?>" rel="nofollow sponsored">ir para pagamento</a>
</div></article></div><?php endforeach;?>
<?php if(!$produtos):?><p class="text-secondary">Nenhum produto publicado.</p><?php endif;?>
</div></section>
<section><h2 class="h3 mb-4">Cupons</h2><div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4 g-4">
<?php foreach($cupons as $c):?><div class="col"><article class="card offer"><div class="card-body d-flex flex-column">
<small class="text-muted"><?= h($c['loja']) ?></small><h3 class="h6 mt-2"><?= h($c['descricao']) ?></h3>
<p class="text-success fw-bold"><?= h($c['tipo']==='percentual'?number_format((float)$c['valor'],0).'%':'R$ '.number_format((float)$c['valor'],2,',','.')) ?> de desconto</p>
<?php if($c['codigo']!==''):?><p class="small">Código: <strong><?= h($c['codigo']) ?></strong></p><?php endif;?>
<p class="small text-secondary">Válido até <?= date('d/m/Y',strtotime($c['data_fim'])) ?></p>
<a class="btn btn-primary w-100 mt-auto" href="go.php?tipo=cupom&amp;id=<?= (int)$c['id'] ?>" rel="nofollow sponsored">Pegue seu cupom</a>
</div></article></div><?php endforeach;?>
<?php if(!$cupons):?><p class="text-secondary">Nenhum cupom ativo.</p><?php endif;?>
</div></section></main><footer class="py-4 text-center text-secondary small border-top">Cupons & Ofertas — condições definidas pelos fornecedores.</footer></body></html>
