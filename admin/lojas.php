<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }
$error = '';
$success = isset($_GET['success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Token inválido. Atualize a página.');
        }
        $nome = trim((string)($_POST['nome'] ?? ''));
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 100) {
            throw new InvalidArgumentException('O nome da loja deve ter de 2 a 100 caracteres.');
        }
        $slug = 'loja-' . bin2hex(random_bytes(6));
        $stmt = $pdo->prepare('INSERT INTO lojas (nome, slug, ativo) VALUES (?, ?, 1)');
        $stmt->execute([$nome, $slug]);
        header('Location: lojas.php?success=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$lojas = $pdo->query('SELECT id, nome, slug, ativo FROM lojas ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
$csrf = Security::generateCSRFToken();
function hLoja(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lojas | Cupons</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:980px">
<div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h3">Lojas</h1><a href="dashboard.php" class="btn btn-outline-secondary">Voltar ao painel</a></div>
<?php if($success):?><div class="alert alert-success">Loja cadastrada.</div><?php endif;?>
<?php if($error!==''):?><div class="alert alert-danger"><?= hLoja($error) ?></div><?php endif;?>
<div class="card mb-4"><div class="card-body"><h2 class="h5">Cadastrar loja</h2>
<form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= hLoja($csrf) ?>">
<div class="col-md-9"><label for="nome" class="form-label">Nome</label><input id="nome" name="nome" type="text" maxlength="100" class="form-control" required></div>
<div class="col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Cadastrar</button></div></form></div></div>
<div class="card"><div class="card-body"><h2 class="h5">Lojas cadastradas</h2><div class="table-responsive">
<table class="table table-striped"><thead><tr><th>ID</th><th>Nome</th><th>Identificador</th><th>Situação</th></tr></thead><tbody>
<?php foreach($lojas as $loja):?><tr><td><?= (int)$loja['id'] ?></td><td><?= hLoja($loja['nome']) ?></td><td><?= hLoja($loja['slug']) ?></td><td><?= $loja['ativo'] ? 'Ativa' : 'Inativa' ?></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<p class="mt-3"><a class="btn btn-primary" href="importar.php">Importar produto ou cupom</a></p>
</main></body></html>
