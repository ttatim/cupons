<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/monetizze_relay_event_staging.php';

function eventCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Monetizze staging: ' . $message);
    }
}
function eventReject(Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $expected) {
        eventCheck(!str_contains($expected->getMessage(), 'PRIVATE-BUYER'), 'PII em erro');
        return;
    }
    throw new RuntimeException('Monetizze staging aceitou ' . $message);
}

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Monetizze staging: integração MySQL não executada (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Somente banco descartável cupons_test é permitido.');
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$db = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
$other = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
$db->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_monetizze_relay_nonces.sql'));
$db->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_monetizze_event_observations.sql'));

$account = bin2hex(random_bytes(16));
$otherAccount = bin2hex(random_bytes(16));
$eventId = bin2hex(random_bytes(32));
$otherId = bin2hex(random_bytes(32));
$nonce1 = bin2hex(random_bytes(16));
$nonce2 = bin2hex(random_bytes(16));
$nonce3 = bin2hex(random_bytes(16));
$nonce4 = bin2hex(random_bytes(16));
$hashKey = str_repeat('H', 48); // Synthetic, never operational.
$relaySecret = str_repeat('S', 48);
$now = time();
$timestamp = (string) $now;
$body = json_encode([
    ['id' => $eventId, 'chave_unica' => $account, 'codigo_status' => '2',
        'postback_evento' => '9', 'comprador' => ['nome' => 'PRIVATE-BUYER']],
    ['id' => $otherId, 'chave_unica' => $otherAccount, 'codigo_status' => '2',
        'postback_evento' => '2'],
], JSON_THROW_ON_ERROR);
$sign = static fn(string $n, string $b): string => hash_hmac('sha256',
    "monetizze-relay-v1\n" . $timestamp . "\n" . $n . "\n" . $b, $relaySecret);
$run = static fn(PDO $pdo, string $n, string $b, ?string $sig = null, ?string $key = null): array =>
    MonetizzeRelayEventStaging::verifyAndStage(
        $pdo, $b, $timestamp, $n, $sig ?? $sign($n, $b),
        $relaySecret, $account, $key ?? $hashKey, $now
    );
$accountHash = hash_hmac('sha256', 'monetizze:account:' . $account, $hashKey);
$eventHash = hash_hmac('sha256', 'monetizze:event:' . $eventId, $hashKey);

try {
    eventReject(static fn() => $run($db, $nonce1, $body, null, 'short'), 'chave HMAC curta');
    eventReject(static fn() => $run($db, $nonce1, $body, str_repeat('0', 64)), 'assinatura adulterada');
    $first = $run($db, $nonce1, $body);
    eventCheck($first['observations_recorded'] === 1 && $first['observations_existing'] === 0
        && $first['other_account'] === 1 && $first['risk'] === 1
        && $first['relay_authenticated'] && !$first['authenticated'] && !$first['financial_ready'],
        'primeira observação mínima sem autorização financeira');
    eventReject(static fn() => $run($other, $nonce1, $body), 'replay do mesmo nonce');
    $repeat = $run($other, $nonce2, $body);
    eventCheck($repeat['observations_recorded'] === 0 && $repeat['observations_existing'] === 1,
        'idempotência entre conexões e nonces distintos');
    $changed = json_encode([['id' => $eventId, 'chave_unica' => $account,
        'codigo_status' => '6', 'postback_evento' => '2']], JSON_THROW_ON_ERROR);
    eventReject(static fn() => $run($db, $nonce3, $changed),
        'mesmo disparo com status divergente entre lotes');
    $nonceCheck = $db->prepare('SELECT COUNT(*) FROM monetizze_relay_nonces WHERE nonce = ?');
    $nonceCheck->execute([$nonce3]);
    eventCheck((int) $nonceCheck->fetchColumn() === 0,
        'divergência faz rollback do nonce e preserva evidência anterior');
    $conflictingBatch = json_encode([
        ['id' => $eventId, 'chave_unica' => $account, 'codigo_status' => '2', 'postback_evento' => '9'],
        ['id' => $eventId, 'chave_unica' => $account, 'codigo_status' => '6', 'postback_evento' => '2'],
    ], JSON_THROW_ON_ERROR);
    eventReject(static fn() => $run($db, $nonce4, $conflictingBatch),
        'mesmo disparo com códigos divergentes no lote');
    $bad = json_encode([['id' => 'INVALID', 'chave_unica' => $account,
        'codigo_status' => '2', 'postback_evento' => '2']], JSON_THROW_ON_ERROR);
    eventReject(static fn() => $run($db, $nonce4, $bad), 'lote inválido');
    $stmt = $db->prepare('SELECT account_hash, event_hash, observed_status, observed_event, review_required
        FROM monetizze_event_observations WHERE account_hash = ? AND event_hash = ?');
    $stmt->execute([$accountHash, $eventHash]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    eventCheck(count($rows) === 1 && (int) $rows[0]['observed_status'] === 2
        && (int) $rows[0]['observed_event'] === 9 && (int) $rows[0]['review_required'] === 1,
        'primeiro registro imutável de risco');
    eventCheck(!str_contains(json_encode($rows, JSON_THROW_ON_ERROR), 'PRIVATE-BUYER')
        && !str_contains(json_encode($rows, JSON_THROW_ON_ERROR), $eventId)
        && !str_contains(json_encode($rows, JSON_THROW_ON_ERROR), $account), 'sem dados identificáveis');
    $stmt = $db->prepare('SELECT COUNT(*) FROM monetizze_relay_nonces WHERE nonce = ?');
    $stmt->execute([$nonce4]);
    eventCheck((int) $stmt->fetchColumn() === 0, 'lote inválido não consome nonce');
} finally {
    // Only test-created random records in the dedicated cupons_test database.
    $cleanup = $db->prepare('DELETE FROM monetizze_event_observations WHERE account_hash = ? AND event_hash = ?');
    $cleanup->execute([$accountHash, $eventHash]);
    $cleanup = $db->prepare('DELETE FROM monetizze_relay_nonces WHERE nonce IN (?, ?, ?, ?)');
    $cleanup->execute([$nonce1, $nonce2, $nonce3, $nonce4]);
}

echo "Monetizze staging: MySQL transacional, idempotência, minimização e fail-closed aprovados.\n";
