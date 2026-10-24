<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/affiliate_integrations.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
$statuses = AffiliateIntegrations::status();
$csrfToken = Security::generateCSRFToken();
$summary = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Token CSRF inválido.');
        }
        if (($_POST['action'] ?? '') !== 'hotmart_summary') {
            throw new RuntimeException('Ação não reconhecida.');
        }
        $summary = (new AffiliateIntegrations())->hotmartSalesSummary(10);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
function hAffiliate(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Integrações de afiliados | Cupons</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <h1 class="h3 mb-3">Integrações de afiliados</h1>
            <p class="text-muted">Credenciais permanecem no ambiente do servidor. O status configurada não confirma autenticação até realizar uma consulta real.</p>
            <?php if ($error !== null): ?>
                <div class="alert alert-danger" role="alert"><?= hAffiliate($error) ?></div>
            <?php endif; ?>
            <div class="row g-3 mb-4">
                <?php foreach ($statuses as $status): ?>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h2 class="h5"><?= hAffiliate($status['label']) ?></h2>
                                <span class="badge <?= $status['ready'] ? 'bg-primary' : 'bg-secondary' ?>"><?= hAffiliate($status['state']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <section class="card">
                <div class="card-body">
                    <h2 class="h5">Hotmart — Sumário de vendas</h2>
                    <p>Consulta de leitura, limitada a 10 resultados por requisição. Não importa compradores nem grava comissões automaticamente.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                        <input type="hidden" name="action" value="hotmart_summary">
                        <button class="btn btn-primary" type="submit" <?= $statuses['hotmart']['ready'] ? '' : 'disabled' ?>>Consultar sumário</button>
                    </form>
                    <?php if ($summary !== null): ?>
                        <div class="alert alert-success mt-3">Consulta concluída: <?= count($summary['items']) ?> resultado(s) nesta página.</div>
                        <div class="table-responsive"><table class="table table-sm">
                            <thead><tr><th>Transações</th><th>Valor</th><th>Moeda</th></tr></thead><tbody>
                            <?php foreach ($summary['items'] as $row):
                                if (!is_array($row)) { continue; }
                                $total = is_array($row['total_value'] ?? null) ? $row['total_value'] : [];
                            ?>
                                <tr><td><?= (int) ($row['total_items'] ?? 0) ?></td>
                                <td><?= hAffiliate((string) ($total['value'] ?? '-')) ?></td>
                                <td><?= hAffiliate((string) ($total['currency_code'] ?? '-')) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                    <?php endif; ?>
                </div>
            </section>
            <p class="small text-muted mt-3">Kiwify e Monetizze: conectores específicos ainda pendentes; não são exibidos como conectados.</p>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
