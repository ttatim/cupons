<?php
// admin/logout.php — encerra sessão e redireciona para login
session_start();


// Proteção opcional: somente se estiver logado
if (!empty($_SESSION)) {
// Limpa todas as variáveis de sessão
$_SESSION = [];
}


// Remove cookie de sessão
if (ini_get('session.use_cookies')) {
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000,
$params['path'], $params['domain'], $params['secure'], $params['httponly']
);
}


session_destroy();


header('Location: /admin/login.php');
exit;