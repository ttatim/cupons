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
$pdo->exec('DELETE FROM hotmart_commission_staging');
$r = HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'APPROVED');
checkStage($r['groups_staged'] === 1 && $r['commissions_matched'] === 2 && !$r['review_required'], 'primeiro registro');
HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'APPROVED');
checkStage(HotmartCommissionStaging::counts($pdo, $ucode, $key) === ['groups_total' => 1, 'groups_to_review' => 0], 'idempotência');
checkStage(HotmartCommissionStaging::counts($pdo, $other, $key)['groups_total'] === 0, 'isolamento de conta');
HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'REFUNDED');
checkStage(HotmartCommissionStaging::counts($pdo, $ucode, $key)['groups_to_review'] === 1, 'reembolso exige revisão');
HotmartCommissionStaging::stage($pdo, $ucode, $key, $page, 'APPROVED');
checkStage(HotmartCommissionStaging::counts($pdo, $ucode, $key)['groups_to_review'] === 1, 'revisão não regride');
$state = $pdo->query('SELECT observed_status, amount_cents FROM hotmart_commission_staging')->fetch(PDO::FETCH_ASSOC);
checkStage($state['observed_status'] === 'REFUNDED' && (int) $state['amount_cents'] === 1100, 'estado estornado preservado, sem ajuste financeiro automático');
$pdo->exec('DELETE FROM hotmart_commission_staging');
echo "Hotmart staging: MySQL, idempotência, isolamento e estornos para revisão aprovados.\n";
