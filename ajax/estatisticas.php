<?php
// ajax/estatisticas.php — retorna HTML com cards de estatísticas simples
header('Content-Type: text/html; charset=utf-8');
$root = dirname(__DIR__);
@require_once $root . '/includes/config.php';
@require_once $root . '/includes/database.php';
@require_once $root . '/includes/security.php';
@require_once $root . '/includes/functions.php';


function log_api($msg) {
$file = dirname(__DIR__) . '/logs/api.log';
if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
@file_put_contents($file, '['.date('c')."] ESTATS " . $msg . "\n", FILE_APPEND);
}


$pdo = $pdo ?? (function_exists('get_db') ? get_db() : (function_exists('db') ? db() : null));
if (!$pdo && defined('DB_HOST')) {
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . (DB_NAME ?? '') . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER ?? null, DB_PASS ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}


$range = $_GET['range'] ?? '7d';
$start = new DateTime('-7 days', new DateTimeZone('America/Sao_Paulo'));
if ($range === '30d') $start = new DateTime('-30 days', new DateTimeZone('America/Sao_Paulo'));
if ($range === '24h') $start = new DateTime('-24 hours', new DateTimeZone('America/Sao_Paulo'));
$startStr = $start->format('Y-m-d H:i:s');


try {
$q1 = $pdo->prepare("SELECT COUNT(*) FROM estatisticas WHERE tipo = 'visita' AND criado_em >= :d");
$q1->execute([':d' => $startStr]);
$visitas = (int)$q1->fetchColumn();


$q2 = $pdo->prepare("SELECT COUNT(*) FROM estatisticas WHERE tipo = 'clique' AND criado_em >= :d");
$q2->execute([':d' => $startStr]);
$cliques = (int)$q2->fetchColumn();


$q3 = $pdo->prepare("SELECT COUNT(*) FROM estatisticas WHERE tipo = 'cupom' AND criado_em >= :d");
$q3->execute([':d' => $startStr]);
$cupons = (int)$q3->fetchColumn();


$q4 = $pdo->prepare("SELECT COUNT(*) FROM estatisticas WHERE tipo = 'compra' AND criado_em >= :d");
$q4->execute([':d' => $startStr]);
$compras = (int)$q4->fetchColumn();


log_api("range=$range OK");
} catch (Throwable $e) {
log_api('ERRO: ' . $e->getMessage());
echo '<div class="alert alert-error">Erro ao carregar estatísticas.</div>';
exit;
}
?>
<div class="cards">
<div class="card"><div class="card-title">Visitas</div><div class="card-value"><?=
number_format($visitas, 0, ',', '.') ?></div></div>
<div class="card"><div class="card-title">Cliques</div><div class="card-value"><?=
number_format($cliques, 0, ',', '.') ?></div></div>
<div class="card"><div class="card-title">Cupons usados</div><div class="card-value"><?=
number_format($cupons, 0, ',', '.') ?></div></div>
<div class="card"><div class="card-title">Compras</div><div class="card-value"><?=
number_format($compras, 0, ',', '.') ?></div></div>
</div>