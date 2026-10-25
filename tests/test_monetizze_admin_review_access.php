<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_admin_review_access.php';
function checkReviewAccess(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('Monetizze admin: ' . $label);
}
function rejectReviewAccess(Closure $call, string $label): void {
    try { $call(); } catch (InvalidArgumentException | RuntimeException $exception) { return; }
    throw new RuntimeException('Monetizze admin aceitou: ' . $label);
}
$admin = static fn(int $id): ?string => $id === 17 ? 'admin' : null;
checkReviewAccess(MonetizzeAdminReviewAccess::requireAdmin(['user_id' => '17'], $admin)
    === 'admin-user-17', 'operador derivado da sessão');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::requireAdmin([], $admin), 'sem sessão');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::requireAdmin(['user_id' => '18'], $admin), 'outro usuário');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::requireAdmin(['user_id' => '17'],
    static fn(int $id): string => 'editor'), 'perfil sem privilégio');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::requireAdmin(['user_id' => '17'],
    static fn(int $id): string => throw new RuntimeException('synthetic-secret')), 'falha do banco');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::requireAdmin(['user_id' => '01'], $admin), 'ID não canônico');
checkReviewAccess(MonetizzeAdminReviewAccess::id('0', true) === 0, 'cursor zero');
checkReviewAccess(MonetizzeAdminReviewAccess::id('123') === 123, 'referência positiva');
foreach (['0', '-1', '01', '1e2', '1 OR 1=1', '99999999999999999999999'] as $bad) {
    rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::id($bad), 'referência malformada');
}
$account = bin2hex(random_bytes(16));
$key = str_repeat('Z', 40);
$settings = MonetizzeAdminReviewAccess::settings(strtoupper($account), $key);
checkReviewAccess($settings['account'] === $account && $settings['hash_key'] === $key,
    'conta do servidor');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::settings('invalid', $key), 'conta inválida');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::settings($account, 'short'), 'segredo curto');
$auth = MonetizzeAdminReviewAccess::authorizer($account, 'admin-user-17');
checkReviewAccess($auth($account, 'admin-user-17') === true, 'vínculo servidor');
checkReviewAccess($auth(bin2hex(random_bytes(16)), 'admin-user-17') === false,
    'isolamento por conta');
checkReviewAccess($auth($account, 'admin-user-18') === false, 'isolamento por operador');
foreach (['request_evidence', 'escalate', 'acknowledge_unverified'] as $action) {
    checkReviewAccess(MonetizzeAdminReviewAccess::action($action) === $action, 'triagem permitida');
}
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::action('approve_payment'), 'aprovação financeira');
rejectReviewAccess(static fn() => MonetizzeAdminReviewAccess::requestId('not-a-nonce'), 'nonce inválido');
checkReviewAccess(strlen(MonetizzeAdminReviewAccess::requestId(bin2hex(random_bytes(16)))) === 32,
    'solicitação idempotente');
echo "Monetizze admin: autenticação, parâmetros, conta fixa e ações de triagem aprovados.\n";
