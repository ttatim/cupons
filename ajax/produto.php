<?php
// ajax/produto.php — retorna HTML parcial com grid de produtos
header('Content-Type: text/html; charset=utf-8');
$root = dirname(__DIR__);
@require_once $root . '/includes/config.php';
@require_once $root . '/includes/database.php';
@require_once $root . '/includes/security.php';
@require_once $root . '/includes/functions.php';


function log_api($msg) {
$file = dirname(__DIR__) . '/logs/api.log';
if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
@file_put_contents($file, '['.date('c')."] PRODUTO " . $msg . "\n", FILE_APPEND);
}


$pdo = $pdo ?? (function_exists('get_db') ? get_db() : (function_exists('db') ? db() : null));
if (!$pdo && defined('DB_HOST')) {
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . (DB_NAME ?? '') . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER ?? null, DB_PASS ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}


$q = trim($_GET['q'] ?? '');
$loja = trim($_GET['loja'] ?? '');
$ordenar = $_GET['ord'] ?? 'recente'; // recente|preco_asc|preco_desc
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(6, min(48, (int)($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;


$where = [];
$params = [];
if ($q !== '') { $where[] = '(p.titulo LIKE :q OR p.descricao LIKE :q)'; $params[':q'] = "%$q%"; }
if ($loja !== '') { $where[] = '(l.slug = :loja OR l.nome LIKE :loja_like)'; $params[':loja'] = $loja; $params[':loja_like'] = "%$loja%"; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';


$orderSql = 'p.criado_em DESC';
if ($ordenar === 'preco_asc') $orderSql = 'p.preco_promocional IS NULL, COALESCE(p.preco_promocional, p.preco) ASC';
if ($ordenar === 'preco_desc') $orderSql = 'COALESCE(p.preco_promocional, p.preco) DESC';


$sql = "SELECT p.*, l.nome AS loja_nome, l.slug AS loja_slug
FROM produtos p JOIN lojas l ON l.id = p.loja_id
$wsql
ORDER BY $orderSql
LIMIT :limit OFFSET :offset";


try {
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
log_api('lista de produtos OK (' . count($rows) . ' itens)');
} catch (Throwable $e) {
log_api('ERRO: ' . $e->getMessage());
echo '<div class="alert alert-error">Erro ao carregar produtos.</div>';
exit;
}
?>
<div class="grid-produtos">
<?php if (!$rows): ?>
<div class="vazio">Nenhum produto encontrado.</div>
<?php else: ?>
<?php foreach ($rows as $p): $preco = $p['preco_promocional'] ?? $p['preco']; ?>
</div>