<?php
// loja.php — página de loja por slug
$root = __DIR__;
@require_once $root . '/includes/config.php';
@require_once $root . '/includes/database.php';
@require_once $root . '/includes/functions.php';
@require_once $root . '/includes/header.php';


$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
http_response_code(400);
echo '<main class="container"><div class="alert alert-error">Loja não informada.</div></main>';
@require_once $root . '/includes/footer.php';
exit;
}


$pdo = $pdo ?? (function_exists('get_db') ? get_db() : (function_exists('db') ? db() : null));
if (!$pdo && defined('DB_HOST')) {
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . (DB_NAME ?? '') . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER ?? null, DB_PASS ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}


$stmt = $pdo->prepare('SELECT * FROM lojas WHERE slug = :slug LIMIT 1');
$stmt->execute([':slug' => $slug]);
$loja = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$loja) {
http_response_code(404);
echo '<main class="container"><div class="alert alert-error">Loja não encontrada.</div></main>';
@require_once $root . '/includes/footer.php';
exit;
}
?>
<main class="container loja">
<header class="page-header loja-header">
<?php if (!empty($loja['logo'])): ?>
<img class="loja-logo" src="/assets/images/lojas/<?= htmlspecialchars($loja['logo']) ?>" alt="<?= htmlspecialchars($loja['nome']) ?>">
<?php endif; ?>
<h1><?= htmlspecialchars($loja['nome']) ?></h1>
<?php if (!empty($loja['url'])): ?>
<a class="btn" href="<?= htmlspecialchars($loja['url']) ?>" rel="nofollow noopener" target="_blank">Ir para a loja</a>
<?php endif; ?>
</header>


<section class="sec-produtos">
<h2>Ofertas da loja</h2>
<div id="grid-produtos" data-endpoint="/ajax/produto.php" data-extra="loja=<?= urlencode($slug) ?>"></div>
</section>


<section class="sec-cupons">
<h2>Cupons da loja</h2>
<div id="lista-cupons" data-endpoint="/ajax/cupom.php" data-extra="loja=<?= urlencode($slug) ?>"></div>
</section>
</main>


<script>
(function(){
function loadInto(id){
var el = document.getElementById(id);
if(!el) return;
<?php @require_once $root . '/includes/footer.php'; ?>