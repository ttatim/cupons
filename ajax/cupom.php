<?php
// ajax/cupom.php — retorna HTML parcial com lista de cupons
header('Content-Type: text/html; charset=utf-8');


// Helpers de caminho
$root = dirname(__DIR__);


// Includes básicos
@require_once $root . '/includes/config.php';
@require_once $root . '/includes/database.php';
@require_once $root . '/includes/security.php';
@require_once $root . '/includes/functions.php';


// Log simples
function log_api($msg) {
$file = dirname(__DIR__) . '/logs/api.log';
if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
@file_put_contents($file, '['.date('c')."] CUPOM " . $msg . "\n", FILE_APPEND);
}


// Obter PDO de forma resiliente
$pdo = $pdo ?? (function_exists('get_db') ? get_db() : (function_exists('db') ? db() : null));
if (!$pdo) {
// Tenta via constantes usuais
if (defined('DB_DSN')) {
$pdo = new PDO(DB_DSN, DB_USER ?? null, DB_PASS ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} elseif (defined('DB_HOST')) {
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . (DB_NAME ?? '') . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER ?? null, DB_PASS ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
}


$q = trim($_GET['q'] ?? '');
$loja = trim($_GET['loja'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(5, min(50, (int)($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;


$where = [];
$params = [];
if ($q !== '') { $where[] = '(c.codigo LIKE :q OR c.descricao LIKE :q)'; $params[':q'] = "%$q%"; }
if ($loja !== '') { $where[] = '(l.slug = :loja OR l.nome LIKE :loja_like)'; $params[':loja'] = $loja; $params[':loja_like'] = "%$loja%"; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';


$sql = "SELECT c.*, l.nome AS loja_nome, l.slug AS loja_slug
FROM cupons c
JOIN lojas l ON l.id = c.loja_id
$wsql
ORDER BY c.valido_ate DESC
LIMIT :limit OFFSET :offset";


try {
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
log_api('lista de cupons OK (' . count($rows) . ' itens)');
} catch (Throwable $e) {
log_api('ERRO: ' . $e->getMessage());
echo '<div class="alert alert-error">Erro ao carregar cupons.</div>';
</div>