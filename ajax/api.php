<?php
// ajax/api.php — roteador simples para ações HTML
header('Content-Type: text/html; charset=utf-8');
$root = dirname(__DIR__);
@require_once $root . '/includes/security.php';


$action = $_GET['action'] ?? 'ping';


switch ($action) {
case 'ping':
echo '<div class="api-ping">pong</div>';
break;
case 'health':
echo '<ul class="api-health"><li>status: ok</li><li>time: ' . htmlspecialchars(date('c')) . '</li></ul>';
break;
default:
echo '<div class="alert alert-error">Ação inválida.</div>';
}