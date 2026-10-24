<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/affiliate_integrations.php';
require_once __DIR__ . '/../includes/affiliate_product_staging.php';
require_once __DIR__ . '/../includes/affiliate_import_page.php';
require_once __DIR__ . '/../includes/affiliate_product_review.php';
require_once __DIR__ . '/../includes/affiliate_product_publication.php';
require_once __DIR__ . '/../includes/hotmart_commission_staging.php';
require_once __DIR__ . '/../includes/hotmart_commission_stage_pagination.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
$statuses = AffiliateIntegrations::status();
$csrfToken = Security::generateCSRFToken();
$summary = null;
$commissionPreview = null;
$commissionAccountPreview = null;
$commissionStageResult = null;
$commissionStageCounts = null;
$commissionStageProgress = null;
$commissionStageEnabled = trim((string) getenv('HOTMART_ACCOUNT_UCODE')) !== ''
    && strlen((string) getenv('HOTMART_COMMISSION_HASH_KEY')) >= 32;
$hotmartAccountUcode = trim((string) getenv('HOTMART_ACCOUNT_UCODE'));
$commissionPage = null;
$commissionHasNext = false;
$kiwifyProducts = null;
$stagingResult = null;
$kiwifyPage = 1;
$stagedProducts = null;
$reviewSaved = false;
$publishedProductId = null;
$publishedMap = [];
$publicationReady = false;
$reviewStores = [];
$monetizzeChecked = false;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Token CSRF inválido.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'hotmart_summary') {
            $summary = (new AffiliateIntegrations())->hotmartSalesSummary(10);
        } elseif ($action === 'hotmart_commissions' || $action === 'hotmart_commissions_next') {
            $nextPage = $action === 'hotmart_commissions_next';
            // O cursor fica exclusivamente na sessão; nunca é enviado ao navegador.
            $cursor = $nextPage ? ($_SESSION['hotmart_commissions_next'] ?? null) : null;
            if ($nextPage && (!is_string($cursor) || $cursor === '')) {
                throw new RuntimeException('Não há próxima página disponível. Inicie uma nova consulta.');
            }
            $commissionData = (new AffiliateIntegrations())->hotmartSalesCommissions(10, $cursor);
            require_once __DIR__ . '/../includes/hotmart_commission_preview.php';
            $commissionPreview = HotmartCommissionPreview::summarize($commissionData['items']);
            if ($hotmartAccountUcode !== '') {
                require_once __DIR__ . '/../includes/hotmart_commission_account_preview.php';
                $commissionAccountPreview = HotmartCommissionAccountPreview::summarize($commissionData['items'], $hotmartAccountUcode);
            }
            $commissionPage = $nextPage ? (int) ($_SESSION['hotmart_commissions_page'] ?? 1) + 1 : 1;
            $_SESSION['hotmart_commissions_page'] = $commissionPage;
            $_SESSION['hotmart_commissions_next'] = $commissionData['page_info']['next_page_token'];
            $commissionHasNext = $_SESSION['hotmart_commissions_next'] !== null;
        } elseif ($action === 'hotmart_commissions_stage' || $action === 'hotmart_commissions_stage_next') {
            $roleStmt = $pdo->prepare('SELECT nivel FROM usuarios WHERE id = :id AND ativo = 1');
            $roleStmt->execute([':id' => (int) ($_SESSION['user_id'] ?? 0)]);
            if ($roleStmt->fetchColumn() !== 'admin') {
                throw new RuntimeException('Somente administradores podem registrar observações de comissões.');
            }
            $account = trim((string) getenv('HOTMART_ACCOUNT_UCODE'));
            $hashKey = (string) getenv('HOTMART_COMMISSION_HASH_KEY');
            $continuation = $action === 'hotmart_commissions_stage_next';
            $cursor = null;
            if ($continuation) {
                // Status e cursor são obtidos da sessão, nunca do POST do navegador.
                $nextRequest = HotmartCommissionStagePagination::nextRequest($_SESSION, $account, $hashKey);
                $status = $nextRequest['status'];
                $cursor = $nextRequest['cursor'];
            } else {
                $status = $_POST['commission_status'] ?? null;
                if (!is_string($status) || !HotmartCommissionStaging::validStatus($status)) {
                    throw new InvalidArgumentException('Status de comissão inválido.');
                }
            }
            $commissionData = (new AffiliateIntegrations())->hotmartSalesCommissions(10, $cursor, $status);
            $commissionStageResult = HotmartCommissionStaging::stage(
                $pdo, $account, $hashKey, $commissionData['items'], $status
            );
            // Avançar a sessão apenas após a gravação transacional da página.
            $commissionStageProgress = HotmartCommissionStagePagination::record(
                $_SESSION, $account, $hashKey, $status,
                $commissionData['page_info']['next_page_token'], $continuation, $cursor
            );
            $commissionStageCounts = HotmartCommissionStaging::counts($pdo, $account, $hashKey);
        } elseif ($action === 'kiwify_products') {
            $kiwifyPage = AffiliateImportPage::parse($_POST['kiwify_page'] ?? null);
            $kiwifyProducts = (new KiwifyIntegration())->listProducts($kiwifyPage, 10);
        } elseif ($action === 'kiwify_stage') {
            $kiwifyPage = AffiliateImportPage::parse($_POST['kiwify_page'] ?? null);
            $kiwifyProducts = (new KiwifyIntegration())->listProducts($kiwifyPage, 10);
            $stagingResult = AffiliateProductStaging::stageKiwify($pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')), $kiwifyProducts['data']);
            $stagedProducts = AffiliateProductStaging::listKiwify($pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')));
        } elseif ($action === 'kiwify_review') {
            $stagedProducts = AffiliateProductStaging::listKiwify($pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')));
        } elseif ($action === 'kiwify_review_save') {
            $externalId = $_POST['external_id'] ?? null;
            if (!is_string($externalId)) {
                throw new InvalidArgumentException('Identificador externo inválido.');
            }
            AffiliateProductReview::saveKiwify($pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')), $externalId, $_POST);
            $stagedProducts = AffiliateProductStaging::listKiwify($pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')));
            $reviewSaved = true;
        } elseif ($action === 'kiwify_publish') {
            $externalId = $_POST['external_id'] ?? null;
            if (!is_string($externalId)) {
                throw new InvalidArgumentException('Identificador externo inválido.');
            }
            $publishedProductId = AffiliateProductPublication::publishKiwify(
                $pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')), $externalId
            );
            $stagedProducts = AffiliateProductStaging::listKiwify($pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')));
        } elseif ($action === 'monetizze_check') {
            $monetizzeChecked = (new MonetizzeIntegration())->checkConnection();
        } else {
            throw new RuntimeException('Ação não reconhecida.');
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException
            ? 'Não foi possível acessar o catálogo. Confira as migrações de staging, revisão e publicação e as permissões do banco.'
            : $exception->getMessage();
    }
}
if ($stagedProducts !== null) {
    $reviewStores = $pdo->query('SELECT id, nome FROM lojas ORDER BY nome LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    try {
        $publishedMap = AffiliateProductPublication::publicationMap($pdo, trim((string) getenv('KIWIFY_ACCOUNT_ID')));
        $publicationReady = true;
    } catch (PDOException $exception) {
        $error = 'Publicação indisponível: aplique a migração affiliate_product_publication.sql.';
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
            <section class="card mt-3">
                <div class="card-body">
                    <h2 class="h5">Hotmart — Prévia de comissões por moeda e origem</h2>
                    <p>Consulta manual paginada (até 10 transações por página). Sem filtros, a API considera APPROVED/COMPLETE. Valores de todos os participantes são agregados; <strong>não representam sua comissão individual, saldo disponível ou conciliação financeira</strong>. Nenhum nome, comprador, token ou ID de transação é armazenado.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                        <input type="hidden" name="action" value="hotmart_commissions">
                        <button class="btn btn-outline-primary" type="submit" <?= $statuses['hotmart']['ready'] ? '' : 'disabled' ?>>Iniciar consulta de comissões</button>
                    </form>
                    <?php if ($commissionPreview !== null): ?>
                        <div class="alert alert-info mt-3">Página <?= (int) $commissionPage ?>: <?= (int) $commissionPreview['transactions_in_page'] ?> transação(ões) e <?= (int) $commissionPreview['commissions_in_page'] ?> comissão(ões). Totais exclusivos desta página, não cumulativos. Nenhum dado financeiro persistido.</div>
                        <div class="table-responsive"><table class="table table-sm">
                            <thead><tr><th>Origem</th><th>Moeda</th><th>Comissões</th><th>Valor agregado</th></tr></thead><tbody>
                            <?php foreach ($commissionPreview['totals'] as $group): ?>
                                <tr><td><?= hAffiliate($group['source']) ?></td><td><?= hAffiliate($group['currency']) ?></td><td><?= (int) $group['count'] ?></td><td><?= hAffiliate($group['amount']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                        <?php if ($commissionAccountPreview !== null): ?>
                            <h3 class="h6 mt-4">Prévia filtrada por UCODE configurado</h3>
                            <p class="small text-warning-emphasis">Coincidência com o UCODE informado no servidor; <strong>não comprova titularidade, recebimento, liquidação ou conciliação</strong>. Não é um saldo financeiro.</p>
                            <div class="alert alert-secondary">
                                <?= (int) $commissionAccountPreview['matched_commissions'] ?> comissão(ões) coincidente(s) em
                                <?= (int) $commissionAccountPreview['transactions_with_match'] ?> transação(ões);
                                <?= (int) $commissionAccountPreview['other_participants'] ?> de outros participantes e
                                <?= (int) $commissionAccountPreview['without_valid_ucode'] ?> sem UCODE válido, excluídas do filtro.
                            </div>
                            <div class="table-responsive"><table class="table table-sm">
                                <thead><tr><th>Origem</th><th>Moeda</th><th>Comissões coincidentes</th><th>Valor da página</th></tr></thead><tbody>
                                <?php foreach ($commissionAccountPreview['totals'] as $group): ?>
                                    <tr><td><?= hAffiliate($group['source']) ?></td><td><?= hAffiliate($group['currency']) ?></td><td><?= (int) $group['count'] ?></td><td><?= hAffiliate($group['amount']) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table></div>
                        <?php else: ?>
                            <p class="small text-muted mt-2">Prévia por participante desativada: configure HOTMART_ACCOUNT_UCODE no servidor para comparar os UCODEs retornados pela API. O identificador não é exibido.</p>
                        <?php endif; ?>
                        <?php if ($commissionHasNext): ?>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                                <input type="hidden" name="action" value="hotmart_commissions_next">
                                <button class="btn btn-outline-primary" type="submit">Consultar próxima página</button>
                            </form>
                        <?php else: ?>
                            <p class="small text-muted mt-2">A API não informou uma próxima página.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
            <section class="card mt-3">
                <div class="card-body">
                    <h2 class="h5">Hotmart — Registrar observações para revisão</h2>
                    <p class="small text-warning-emphasis">Ação manual restrita a administradores: consulta até 10 transações por página, avança somente quando solicitado (máximo 50 páginas por consulta e status), grava parcelas coincidentes com o UCODE configurado e marca reembolsos/estornos para revisão. Não representa saldo, liquidação, conciliação ou confirmação de titularidade. Não registra compradores nem códigos de transação em claro.</p>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                        <input type="hidden" name="action" value="hotmart_commissions_stage">
                        <div class="col-sm-5">
                            <label for="commission_status" class="form-label">Status a consultar</label>
                            <select id="commission_status" name="commission_status" class="form-select">
                                <option value="APPROVED">APPROVED</option>
                                <option value="COMPLETE">COMPLETE</option>
                                <option value="REFUNDED">REFUNDED</option>
                                <option value="CHARGEBACK">CHARGEBACK</option>
                                <option value="PARTIALLY_REFUNDED">PARTIALLY_REFUNDED</option>
                            </select>
                        </div>
                        <div class="col-sm-7">
                            <button class="btn btn-outline-warning" type="submit" <?= ($commissionStageEnabled && $statuses['hotmart']['ready']) ? '' : 'disabled' ?>>Iniciar registro pela primeira página</button>
                        </div>
                    </form>
                    <?php if (!$commissionStageEnabled): ?>
                        <p class="small text-muted mt-2">Para habilitar, configure HOTMART_ACCOUNT_UCODE e HOTMART_COMMISSION_HASH_KEY (mínimo 32 caracteres aleatórios) fora do repositório e aplique a migração da tabela.</p>
                    <?php endif; ?>
                    <?php if ($commissionStageResult !== null): ?>
                        <div class="alert alert-info mt-3">
                            Página <?= (int) $commissionStageProgress['page'] ?>, status <?= hAffiliate($commissionStageProgress['status']) ?>: <?= (int) $commissionStageResult['commissions_matched'] ?> parcela(s) coincidente(s), em
                            <?= (int) $commissionStageResult['groups_staged'] ?> grupo(s) de observação.
                            <?= $commissionStageResult['review_required'] ? 'Status requer revisão humana.' : 'Sem comprovação de liquidação.' ?>
                            Total armazenado nesta conta: <?= (int) $commissionStageCounts['groups_total'] ?> grupo(s);
                            <?= (int) $commissionStageCounts['groups_to_review'] ?> pendente(s) de revisão.
                        </div>
                    <?php endif; ?>
                    <?php if ($commissionStageProgress !== null && $commissionStageProgress['has_next']): ?>
                        <form method="post" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                            <input type="hidden" name="action" value="hotmart_commissions_stage_next">
                            <button class="btn btn-outline-warning" type="submit">Registrar observações da próxima página</button>
                        </form>
                        <p class="small text-muted mt-2">O status permanece o mesmo nesta sequência. Para mudar o status, inicie nova consulta.</p>
                    <?php elseif ($commissionStageProgress !== null): ?>
                        <p class="small text-muted mt-2">Não há próxima página disponível ou foi atingido o limite de 50 páginas.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="card mt-3">
                <div class="card-body">
                    <h2 class="h5">Kiwify — Produtos disponíveis</h2>
                    <p>Consulta paginada somente leitura (10 produtos por ação, páginas 1 a 1000). Não publica ofertas, não modifica preços e não importa compradores.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                        <input type="hidden" name="action" value="kiwify_products">
                        <label class="form-label" for="kiwify_page_consulta">Página da API</label>
                        <input class="form-control mb-2" id="kiwify_page_consulta" type="number" name="kiwify_page" min="1" max="1000" value="<?= (int) $kiwifyPage ?>" required>
                        <button class="btn btn-primary" type="submit" <?= $statuses['kiwify']['ready'] ? '' : 'disabled' ?>>Consultar produtos</button>
                    </form>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                        <input type="hidden" name="action" value="kiwify_stage">
                        <label class="form-label" for="kiwify_page_importacao">Página a importar</label>
                        <input class="form-control mb-2" id="kiwify_page_importacao" type="number" name="kiwify_page" min="1" max="1000" value="<?= (int) $kiwifyPage ?>" required>
                        <button class="btn btn-outline-primary" type="submit" <?= $statuses['kiwify']['ready'] ? '' : 'disabled' ?>>Importar 10 produtos para revisão</button>
                    </form>
                    <p class="small text-muted mt-2">Importa somente ID, nome, status e moeda para uma tabela isolada por conta. A seleção da página permite continuar a revisão do catálogo sem importar dados de compradores. Não publica ofertas, preços, imagens ou checkout. Execute a migração SQL antes.</p>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                        <input type="hidden" name="action" value="kiwify_review">
                        <button class="btn btn-outline-secondary" type="submit" <?= $statuses['kiwify']['ready'] ? '' : 'disabled' ?>>Ver catálogo em revisão (até 20 registros)</button>
                    </form>
                    <?php if ($stagingResult !== null): ?>
                        <div class="alert alert-info mt-3">Página <?= (int) $kiwifyPage ?> importada: <?= (int) $stagingResult['processed'] ?> registro(s) processado(s), <?= (int) $stagingResult['rejected'] ?> rejeitado(s). Nenhuma oferta foi publicada.</div>
                    <?php endif; ?>
                    <?php if ($reviewSaved): ?>
                        <div class="alert alert-success mt-3">Dados de revisão salvos. O produto continua fora da vitrine.</div>
                    <?php endif; ?>
                    <?php if ($publishedProductId !== null): ?>
                        <div class="alert alert-success mt-3">Produto #<?= (int) $publishedProductId ?> publicado na vitrine após revisão manual.</div>
                    <?php endif; ?>
                    <?php if ($stagedProducts !== null): ?>
                        <h3 class="h6 mt-3">Registros em revisão (até 20 mais recentes)</h3>
                        <div class="table-responsive"><table class="table table-sm">
                            <thead><tr><th>ID externo</th><th>Produto</th><th>Status</th><th>Moeda</th></tr></thead><tbody>
                            <?php foreach ($stagedProducts ?? [] as $item): ?>
                                <tr><td><?= hAffiliate((string) $item['external_id']) ?></td><td><?= hAffiliate((string) $item['name']) ?></td><td><?= hAffiliate((string) $item['status']) ?></td><td><?= hAffiliate((string) ($item['currency'] ?? '-')) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                        <p class="small text-muted">Os campos abaixo são editáveis apenas na área administrativa. Salvar não publica o produto nem consulta os links.</p>
                        <?php foreach ($stagedProducts as $item): ?>
                            <details class="border rounded p-3 mb-2">
                                <summary><?= hAffiliate((string) $item['name']) ?> — revisão e publicação</summary>
                                <?php if (isset($publishedMap[(string) $item['external_id']])): ?>
                                    <div class="alert alert-success mt-2">Já publicado: produto #<?= (int) $publishedMap[(string) $item['external_id']] ?>. Alterações posteriores no staging não atualizam a oferta pública.</div>
                                <?php else: ?>
                                <form method="post" class="row g-2 mt-2">
                                    <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                                    <input type="hidden" name="action" value="kiwify_review_save">
                                    <input type="hidden" name="external_id" value="<?= hAffiliate((string) $item['external_id']) ?>">
                                    <div class="col-12">
                                        <label class="form-label">Descrição (até 1500 caracteres)</label>
                                        <textarea class="form-control" name="description" maxlength="1500" rows="3"><?= hAffiliate((string) ($item['review_description'] ?? '')) ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Preço</label>
                                        <input class="form-control" name="price" inputmode="decimal" placeholder="99.90" value="<?= hAffiliate((string) ($item['review_price'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Preço original</label>
                                        <input class="form-control" name="original_price" inputmode="decimal" placeholder="129.90" value="<?= hAffiliate((string) ($item['review_original_price'] ?? '')) ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">URL HTTPS de checkout</label>
                                        <input class="form-control" type="url" name="checkout_url" maxlength="2048" value="<?= hAffiliate((string) ($item['review_checkout_url'] ?? '')) ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">URL HTTPS da imagem</label>
                                        <input class="form-control" type="url" name="image_url" maxlength="2048" value="<?= hAffiliate((string) ($item['review_image_url'] ?? '')) ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Loja</label>
                                        <select class="form-select" name="store_id">
                                            <option value="">Selecionar posteriormente</option>
                                            <?php foreach ($reviewStores as $store): ?>
                                                <option value="<?= (int) $store['id'] ?>" <?= (int) ($item['review_store_id'] ?? 0) === (int) $store['id'] ? 'selected' : '' ?>><?= hAffiliate((string) $store['nome']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12"><button type="submit" class="btn btn-primary">Salvar revisão (não publicar)</button></div>
                                </form>
                                <?php if ($publicationReady): ?>
                                    <form method="post" class="mt-3" onsubmit="return confirm('Confirma a publicação desta oferta na vitrine? Confira preço, loja e link de afiliado antes.');">
                                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                                        <input type="hidden" name="action" value="kiwify_publish">
                                        <input type="hidden" name="external_id" value="<?= hAffiliate((string) $item['external_id']) ?>">
                                        <button type="submit" class="btn btn-success">Publicar oferta revisada</button>
                                        <p class="small text-muted mt-1">Exige status active, moeda BRL, descrição com 20 caracteres, preço, loja e checkout HTTPS válido. A publicação não é reversível por este painel.</p>
                                    </form>
                                <?php endif; ?>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($kiwifyProducts !== null): ?>
                        <div class="alert alert-success mt-3">Página <?= (int) $kiwifyPage ?>: consulta concluída com <?= count($kiwifyProducts['data']) ?> produto(s). Nenhuma oferta foi publicada.</div>
                        <div class="table-responsive"><table class="table table-sm">
                            <thead><tr><th>Produto</th><th>Status</th><th>Moeda</th></tr></thead><tbody>
                            <?php foreach ($kiwifyProducts['data'] as $row):
                                if (!is_array($row)) { continue; }
                            ?>
                                <tr>
                                    <td><?= hAffiliate((string) ($row['name'] ?? '-')) ?></td>
                                    <td><?= hAffiliate((string) ($row['status'] ?? '-')) ?></td>
                                    <td><?= hAffiliate((string) ($row['currency'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="card mt-3">
                <div class="card-body">
                    <h2 class="h5">Monetizze — Verificação de chave de API</h2>
                    <p>Valida a chave X_CONSUMER_KEY no endpoint de token. Não importa produtos, compradores ou comissões; o token recebido é descartado.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hAffiliate($csrfToken) ?>">
                        <input type="hidden" name="action" value="monetizze_check">
                        <button class="btn btn-primary" type="submit" <?= $statuses['monetizze']['ready'] ? '' : 'disabled' ?>>Verificar chave Monetizze</button>
                    </form>
                    <?php if ($monetizzeChecked): ?>
                        <div class="alert alert-success mt-3">Autenticação Monetizze confirmada nesta consulta. Nenhum dado foi importado.</div>
                    <?php endif; ?>
                </div>
            </section>
            <p class="small text-muted mt-3">A sincronização Monetizze permanece pendente; a verificação de chave não representa uma integração completa.</p>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
