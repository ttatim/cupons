<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monetizze_admin_review_access.php';
require_once __DIR__ . '/../includes/monetizze_review_queue.php';
require_once __DIR__ . '/../includes/monetizze_review_decision_journal.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
try {
    $operatorRef = MonetizzeAdminReviewAccess::requireAdmin(
        $_SESSION,
        static function (int $userId) use ($pdo): ?string {
            $query = $pdo->prepare('SELECT nivel FROM usuarios WHERE id = :id AND ativo = 1');
            $query->execute([':id' => $userId]);
            $role = $query->fetchColumn();
            return is_string($role) ? $role : null;
        }
    );
} catch (Throwable $exception) {
    http_response_code(403);
    exit('Acesso restrito a administradores ativos.');
}

function hMonetizze(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$csrfToken = Security::generateCSRFToken();
$configuration = null;
$error = null;
$queue = null;
$history = null;
$reviewId = null;
$afterId = 0;
$historyAfterId = 0;
try {
    $configuration = MonetizzeAdminReviewAccess::settings(
        (string) getenv('MONETIZZE_REVIEW_ACCOUNT_KEY'),
        (string) getenv('MONETIZZE_REVIEW_HASH_KEY')
    );
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}

if ($configuration !== null) {
    $account = $configuration['account'];
    $hashKey = $configuration['hash_key'];
    $authorize = MonetizzeAdminReviewAccess::authorizer($account, $operatorRef);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!Security::validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))
                || !Security::checkRateLimit('monetizze_review_decision', 10, 60)) {
                throw new RuntimeException('Solicitação não autorizada.');
            }
            $postedReviewId = MonetizzeAdminReviewAccess::id($_POST['review_id'] ?? null);
            $action = MonetizzeAdminReviewAccess::action($_POST['action'] ?? null);
            $requestId = MonetizzeAdminReviewAccess::requestId($_POST['request_id'] ?? null);
            MonetizzeReviewDecisionJournal::record(
                $pdo, $account, $hashKey, $operatorRef, $authorize,
                $postedReviewId, $requestId, $action
            );
            header('Location: monetizze-revisao.php?review_id=' . $postedReviewId . '&saved=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            http_response_code(400);
            $error = 'Não foi possível registrar a decisão. Verifique a autorização, os dados e as migrações.';
        }
    }
    try {
        $afterId = MonetizzeAdminReviewAccess::id($_GET['after_id'] ?? '0', true);
        if (isset($_GET['review_id'])) {
            $reviewId = MonetizzeAdminReviewAccess::id($_GET['review_id']);
            $historyAfterId = MonetizzeAdminReviewAccess::id($_GET['history_after_id'] ?? '0', true);
        }
        $queue = MonetizzeReviewQueue::listForAccount($pdo, $account, $hashKey, $afterId, 25);
        if ($reviewId !== null) {
            $history = MonetizzeReviewDecisionJournal::historyForCandidate(
                $pdo, $account, $hashKey, $operatorRef, $authorize,
                $reviewId, $historyAfterId, 25
            );
        }
    } catch (Throwable $exception) {
        http_response_code(400);
        $error = 'Consulta indisponível. Verifique os parâmetros e as migrações de revisão.';
        $queue = null;
        $history = null;
    }
}
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Revisão Monetizze | Cupons</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head><body><div class="container-fluid"><div class="row">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
<h1 class="h3">Revisão Monetizze — eventos não verificados</h1>
<div class="alert alert-warning" role="status">Os eventos não têm origem Monetizze comprovada. As decisões são apenas de triagem humana: não autorizam conciliação, pagamentos, saldos ou reconhecimento de receita.</div>
<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= hMonetizze($error) ?></div><?php endif; ?>
<?php if (isset($_GET['saved']) && $_GET['saved'] === '1' && $error === null): ?><div class="alert alert-success">Decisão de triagem registrada no diário de auditoria.</div><?php endif; ?>
<?php if ($queue !== null): ?>
<section class="card mb-4"><div class="card-body"><h2 class="h5">Fila da conta configurada no servidor</h2>
<p class="text-secondary">Somente administradores ativos podem acessar esta fila. Identificadores externos e dados de compradores não são exibidos.</p>
<div class="table-responsive"><table class="table table-striped table-sm align-middle"><thead><tr><th>Referência local</th><th>Status original</th><th>Evento original</th><th>Status candidato</th><th>Evento candidato</th><th>Risco</th><th>Observado localmente</th><th>Ação</th></tr></thead><tbody>
<?php foreach ($queue['items'] as $item): ?><tr>
<td><?= (int) $item['review_id'] ?></td><td><?= (int) $item['original_status'] ?></td><td><?= (int) $item['original_event'] ?></td>
<td><?= (int) $item['candidate_status'] ?></td><td><?= (int) $item['candidate_event'] ?></td>
<td><?= hMonetizze((string) $item['risk_direction']) ?></td><td><?= hMonetizze((string) $item['first_seen_at']) ?></td>
<td><a class="btn btn-outline-primary btn-sm" href="?review_id=<?= (int) $item['review_id'] ?>">Revisar</a></td>
</tr><?php endforeach; ?>
<?php if (!$queue['items']): ?><tr><td colspan="8">Nenhum candidato nesta página.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($queue['has_more']): ?><a class="btn btn-outline-secondary" href="?after_id=<?= (int) $queue['next_after_id'] ?>">Próxima página</a><?php endif; ?>
</div></section>
<?php endif; ?>
<?php if ($reviewId !== null && $history !== null): ?>
<section class="card"><div class="card-body"><h2 class="h5">Triagem da referência local #<?= $reviewId ?></h2>
<p>Registre uma medida de acompanhamento. Não há opção de aprovação financeira.</p>
<form method="post" class="row g-2 mb-4">
<input type="hidden" name="csrf_token" value="<?= hMonetizze($csrfToken) ?>">
<input type="hidden" name="review_id" value="<?= $reviewId ?>">
<input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)) ?>">
<div class="col-md-7"><label class="form-label" for="action">Decisão</label><select class="form-select" name="action" id="action" required>
<option value="request_evidence">Solicitar comprovação</option><option value="escalate">Escalonar para análise</option><option value="acknowledge_unverified">Registrar ciência (não verificado)</option>
</select></div><div class="col-md-5 d-flex align-items-end"><button type="submit" class="btn btn-primary">Registrar triagem</button></div></form>
<h3 class="h6">Histórico imutável de decisões</h3>
<table class="table table-sm"><thead><tr><th>ID local</th><th>Ação</th><th>Registrado em</th></tr></thead><tbody>
<?php foreach ($history['items'] as $item): ?><tr><td><?= (int) $item['decision_id'] ?></td><td><?= hMonetizze((string) $item['action']) ?></td><td><?= hMonetizze((string) $item['recorded_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$history['items']): ?><tr><td colspan="3">Nenhuma decisão registrada.</td></tr><?php endif; ?>
</tbody></table>
<?php if ($history['has_more']): ?><a class="btn btn-outline-secondary" href="?review_id=<?= $reviewId ?>&amp;history_after_id=<?= (int) $history['next_after_id'] ?>">Próximas decisões</a><?php endif; ?>
</div></section>
<?php endif; ?>
</main></div></div></body></html>
