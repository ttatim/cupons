<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hotmart_commission_staging.php';
function checkStage(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('Falhou: ' . $label); }
}
$ucode = 'A1B2C3D4-1234-5678-9ABC-ABCDEF012345';
$other = 'B1B2C3D4-1234-5678-9ABC-ABCDEF012345';
$key = str_repeat('S', 48);
$page = [['transaction' => 'HP12345678901234', 'buyer' => ['email' => 'secret@example.invalid'], 'commissions' => [
    ['user' => ['ucode' => strtolower($ucode), 'name' => 'SECRET'], 'source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '10.25']],
    ['user' => ['ucode' => $ucode], 'source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '0.75']],
    ['user' => ['ucode' => $other], 'source' => 'PRODUCER', 'commission' => ['currency_value' => 'USD', 'value' => '999.00']],
]]];
$n = HotmartCommissionStaging::normalize($page, $ucode, $key, 'APPROVED');
checkStage($n['matched'] === 2 && count($n['rows']) === 1, 'agrupamento por transação');
checkStage($n['rows'][0]['amount_cents'] === 1100 && $n['rows'][0]['commission_count'] === 2, 'valor em centavos');
checkStage(strlen($n['rows'][0]['transaction_hash']) === 64 && strlen($n['account_hash']) === 64, 'HMAC');
$encoded = json_encode($n, JSON_THROW_ON_ERROR);
foreach ([$ucode, strtolower($ucode), 'HP12345678901234', 'SECRET', 'secret@example.invalid', '999.00'] as $secret) {
    checkStage(!str_contains($encoded, $secret), 'minimização: ' . $secret);
}
checkStage(HotmartCommissionStaging::normalize($page, $other, $key, 'APPROVED')['matched'] === 1, 'isolamento por UCODE');
checkStage($n['account_hash'] !== HotmartCommissionStaging::normalize($page, $other, $key, 'APPROVED')['account_hash'], 'escopos separados');
foreach ([['items' => $page, 'ucode' => $ucode, 'key' => 'weak', 'status' => 'APPROVED'],
          ['items' => $page, 'ucode' => 'bad', 'key' => $key, 'status' => 'APPROVED'],
          ['items' => $page, 'ucode' => $ucode, 'key' => $key, 'status' => 'INVALID'],
          ['items' => [['commissions' => []]], 'ucode' => $ucode, 'key' => $key, 'status' => 'APPROVED'],
          ['items' => [['transaction' => 'HP12345678901234', 'commissions' => [[
              'user' => ['ucode' => $ucode], 'source' => 'AFFILIATE',
              'commission' => ['currency_value' => 'BRL', 'value' => '1.999']]]]], 'ucode' => $ucode, 'key' => $key, 'status' => 'APPROVED']
] as $bad) {
    try { HotmartCommissionStaging::normalize($bad['items'], $bad['ucode'], $bad['key'], $bad['status']); throw new RuntimeException('Aceitou entrada inválida'); }
    catch (InvalidArgumentException $expected) {}
}
$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') { echo "Hotmart staging: validação, minimização e HMAC aprovados; MySQL não executado (TEST_DB_DSN ausente).\n"; exit(0); }
if (!str_contains($dsn, 'dbname=cupons_test')) { throw new RuntimeException('Banco de teste obrigatório: cupons_test.'); }
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_hotmart_commission_staging.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_hotmart_commission_observations.sql'));
$pdo->exec('DELETE FROM hotmart_commission_observations');
$pdo->exec('DELETE FROM hotmart_commission_staging');
$r = HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'APPROVED');
checkStage($r['groups_staged'] === 1 && $r['commissions_matched'] === 2 && !$r['review_required']
    && $r['observations_recorded'] === 1, 'primeiro registro e histórico');
$repeat = HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'APPROVED');
checkStage($repeat['observations_recorded'] === 0, 'reimportação não duplica observação');
checkStage(HotmartCommissionStaging::observationBreakdown($pdo, $ucode, $key) === [
    ['status' => 'APPROVED', 'observations' => 1],
], 'histórico agregado da conta');
checkStage(HotmartCommissionStaging::counts($pdo, $ucode, $key) === ['groups_total' => 1, 'groups_to_review' => 0], 'idempotência');
checkStage(HotmartCommissionStaging::counts($pdo, $other, $key)['groups_total'] === 0, 'isolamento de conta');
HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'REFUNDED');
checkStage(HotmartCommissionStaging::counts($pdo, $ucode, $key)['groups_to_review'] === 1, 'reembolso exige revisão');
checkStage(HotmartCommissionStaging::observationBreakdown($pdo, $ucode, $key) === [
    ['status' => 'APPROVED', 'observations' => 1],
    ['status' => 'REFUNDED', 'observations' => 1],
], 'reembolso preservado em histórico distinto');
HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'APPROVED');
checkStage(HotmartCommissionStaging::counts($pdo, $ucode, $key)['groups_to_review'] === 1, 'revisão não regride');
$state = $pdo->query('SELECT observed_status, amount_cents FROM hotmart_commission_staging')->fetch(PDO::FETCH_ASSOC);
checkStage($state['observed_status'] === 'REFUNDED' && (int) $state['amount_cents'] === 1100, 'estado estornado preservado, sem ajuste financeiro automático');
checkStage(HotmartCommissionStaging::reviewBreakdown($pdo, $ucode, $key) === [
    ['status' => 'REFUNDED', 'groups' => 1],
], 'fila agregada por status de alerta');
checkStage(HotmartCommissionStaging::reviewBreakdown($pdo, $other, $key) === [], 'fila de outra conta isolada');

// Uma observação APPROVED atrasada não pode reverter valor, quantidade ou
// status já sinalizados como reembolso; nenhum ajuste financeiro é calculado.
$staleApproved = $page;
$staleApproved[0]['commissions'][0]['commission']['value'] = '999.99';
HotmartCommissionStaging::stage($pdo, $ucode, $key, $staleApproved, 'APPROVED');
$preserved = $pdo->query("SELECT observed_status, amount_cents, commission_count, review_required
    FROM hotmart_commission_staging WHERE source = 'AFFILIATE'")->fetch(PDO::FETCH_ASSOC);
checkStage($preserved['observed_status'] === 'REFUNDED'
    && (int) $preserved['amount_cents'] === 1100
    && (int) $preserved['commission_count'] === 2
    && (int) $preserved['review_required'] === 1, 'observação tardia não altera alerta');
checkStage(HotmartCommissionStaging::observationBreakdown($pdo, $ucode, $key) === [
    ['status' => 'APPROVED', 'observations' => 2],
    ['status' => 'REFUNDED', 'observations' => 1],
], 'observação com novo valor é auditável sem desfazer alerta');

// Novo status de risco pode atualizar o alerta, mas nunca o resolver sozinho.
$chargeback = $page;
$chargeback[0]['commissions'][0]['commission']['value'] = '2.50';
HotmartCommissionStaging::stage($pdo, $ucode, $key, $chargeback, 'CHARGEBACK');
$updated = $pdo->query("SELECT observed_status, amount_cents, review_required
    FROM hotmart_commission_staging WHERE source = 'AFFILIATE'")->fetch(PDO::FETCH_ASSOC);
checkStage($updated['observed_status'] === 'CHARGEBACK'
    && (int) $updated['amount_cents'] === 325
    && (int) $updated['review_required'] === 1, 'novo estorno mantém revisão');
checkStage(HotmartCommissionStaging::reviewBreakdown($pdo, $ucode, $key) === [
    ['status' => 'CHARGEBACK', 'groups' => 1],
], 'fila reflete último alerta observado');
HotmartCommissionStaging::stage($pdo, $other, $key, $page, 'PARTIALLY_REFUNDED');
checkStage(HotmartCommissionStaging::reviewBreakdown($pdo, $other, $key) === [
    ['status' => 'PARTIALLY_REFUNDED', 'groups' => 1],
], 'alerta de outra conta independente');
checkStage(HotmartCommissionStaging::reviewBreakdown($pdo, $ucode, $key) === [
    ['status' => 'CHARGEBACK', 'groups' => 1],
], 'consulta de outra conta não mistura alertas');
checkStage(HotmartCommissionStaging::observationBreakdown($pdo, $ucode, $key) === [
    ['status' => 'APPROVED', 'observations' => 2],
    ['status' => 'CHARGEBACK', 'observations' => 1],
    ['status' => 'REFUNDED', 'observations' => 1],
], 'histórico não perde alertas anteriores');
checkStage(HotmartCommissionStaging::observationBreakdown($pdo, $other, $key) === [
    ['status' => 'PARTIALLY_REFUNDED', 'observations' => 1],
], 'histórico de outra conta isolado');
checkStage((int) $pdo->query('SELECT COUNT(*) FROM hotmart_commission_observations')->fetchColumn() === 5,
    'idempotência e isolamento persistidos');
$pdo->exec('DELETE FROM hotmart_commission_observations');
$pdo->exec('DELETE FROM hotmart_commission_staging');
echo "Hotmart staging: MySQL, histórico idempotente, isolamento e alertas aprovados.\n";
