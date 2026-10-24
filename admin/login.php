<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!Security::validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Sessão expirada. Atualize a página.';
    } elseif (!Security::checkRateLimit('login', 5, 300)) {
        http_response_code(429);
        $error = 'Muitas tentativas. Aguarde alguns minutos.';
    } elseif ($username !== '' && $password !== '' && login($username, $password, $pdo)) {
        session_regenerate_id(true);
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Usuário ou senha inválidos.';
    }
}
$token = Security::generateCSRFToken();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrar | Cupons</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-5" style="max-width:440px">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><h1 class="h5 mb-0">Área administrativa</h1></div>
<div class="card-body p-4"><?php if($error!==''):?><div class="alert alert-danger"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token,ENT_QUOTES,'UTF-8') ?>">
<div class="mb-3"><label class="form-label" for="username">Usuário</label><input id="username" name="username" type="text" class="form-control" autocomplete="username" maxlength="50" required></div>
<div class="mb-3"><label class="form-label" for="password">Senha</label><input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required></div>
<button class="btn btn-primary w-100" type="submit">Entrar</button></form></div></div>
<p class="text-secondary small mt-3">A primeira conta deve ser criada pelo administrador do servidor usando o script CLI de instalação.</p>
</main></body></html>
