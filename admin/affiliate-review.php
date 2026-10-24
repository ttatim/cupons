<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/affiliate_staging_publisher.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }
function hReview(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$csrf = Security::generateCSRFToken();
$account = trim((string) getenv('KIWIFY_ACCOUNT_ID'));
$error = null;
$items = [];
$stores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new DomainException('Token CSRF inválido.');
        }
        if (($_POST['action'] ?? '') !== 'publish_kiwify' || $account === '') {
            throw new DomainException('Ação ou conta Kiwify inválida.');
        }
        $productId = AffiliateStagingPublisher::publishKiwify(
            $pdo, $account, (string) ($_POST['external_id'] ?? ''),
            (int) ($_POST['store_id'] ?? 0), (string) ($_POST['description'] ?? ''),
            (string) ($_POST['price'] ?? ''), (string) ($_POST['checkout_url'] ?? '')
        );
        header('Location: affiliate-review.php?published=' . $productId, true, 303);
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException || $exception instanceof DomainException
            ? $exception->getMessage() : 'Falha ao publicar. Verifique migrações e permissões do banco.';
    }
}
try {
    $stores = $pdo->query('SELECT id, nome FROM lojas WHERE ativo = 1 ORDER BY nome LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    if ($account !== '') { $items = AffiliateProductStaging::listKiwify($pdo, $account, 50); }
} catch (Throwable $exception) {
    $error = 'Não foi possível carregar a fila. Aplique as migrações de staging e publicação.';
}
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Revisão Kiwify | Cupons</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head><body><div class="container-fluid"><div class="row">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
<h1 class="h3">Revisar produtos Kiwify</h1>
<p>Confirme loja, descrição, preço em reais e checkout HTTPS antes de publicar. A API não fornece automaticamente uma URL de afiliado validada.</p>
<a class="btn btn-outline-secondary mb-3" href="integracoes-afiliados.php">Voltar às integrações</a>
<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= hReview($error) ?></div><?php endif; ?>
<?php if (isset($_GET['published']) && ctype_digit((string) $_GET['published'])): ?>
<div class="alert alert-success" role="status">Produto #<?= (int) $_GET['published'] ?> publicado.</div>
<?php endif; ?>
<?php if ($account === ''): ?><div class="alert alert-warning">Configure KIWIFY_ACCOUNT_ID no servidor.</div><?php endif; ?>
<?php if (!$stores): ?><div class="alert alert-warning">Cadastre uma loja ativa antes de publicar.</div><?php endif; ?>
<?php if (!$items): ?><p>Fila vazia. Importe produtos no painel de afiliados.</p><?php endif; ?>
<div class="row row-cols-1 row-cols-xl-2 g-3">
<?php foreach ($items as $item): ?><div class="col"><section class="card h-100"><div class="card-body">
<h2 class="h5"><?= hReview((string) $item['name']) ?></h2>
<p class="small text-muted">ID: <?= hReview((string) $item['external_id']) ?> · Status: <?= hReview((string) $item['status']) ?> · Moeda: <?= hReview((string) ($item['currency'] ?? '-')) ?></p>
<?php if ($item['published_product_id'] !== null): ?>
<span class="badge bg-success">Publicado #<?= (int) $item['published_product_id'] ?></span>
<?php else: ?>
<?php if (($item['currency'] ?? null) !== null && $item['currency'] !== 'BRL'): ?>
<div class="alert alert-warning">Moeda não BRL: revisão cambial necessária antes de publicar.</div>
<?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= hReview($csrf) ?>">
<input type="hidden" name="action" value="publish_kiwify">
<input type="hidden" name="external_id" value="<?= hReview((string) $item['external_id']) ?>">
<div class="mb-2"><label class="form-label">Loja</label><select name="store_id" class="form-select" required>
<option value="">Selecione</option>
<?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= hReview((string) $store['nome']) ?></option><?php endforeach; ?>
</select></div>
<div class="mb-2"><label class="form-label">Descrição revisada (20 a 1500 caracteres)</label>
<textarea name="description" class="form-control" minlength="20" maxlength="1500" rows="3" required></textarea></div>
<div class="mb-2"><label class="form-label">Preço em reais (ex.: 79.90)</label>
<input name="price" class="form-control" inputmode="decimal" required></div>
<div class="mb-3"><label class="form-label">URL HTTPS do checkout</label>
<input name="checkout_url" type="url" class="form-control" maxlength="2048" required></div>
<button type="submit" class="btn btn-primary" <?= (!$stores || (($item['currency'] ?? null) !== null && $item['currency'] !== 'BRL')) ? 'disabled' : '' ?>>Publicar oferta revisada</button>
</form>
<?php endif; ?></div></section></div><?php endforeach; ?>
</div><p class="small text-muted mt-3">Exibe até 50 produtos da conta configurada. Isolamento por vendedor permanece pendente (item 10).</p>
</main></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
