<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/monetizze_relay_nonce_store.php';
require_once __DIR__ . '/../includes/monetizze_signed_relay_review.php';

function nonceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Nonce MySQL: ' . $message);
    }
}
function nonceReject(Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $expected) {
        nonceCheck(!str_contains($expected->getMessage(), 'PRIVATE-BUYER'), 'sem vazamento');
        return;
    }
    throw new RuntimeException('Nonce MySQL aceitou ' . $message);
}

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    // No database access is needed to verify input validation.
    nonceReject(static fn() => MonetizzeRelayNonceStore::claim(
        new PDO('sqlite::memory:'), 'not-a-nonce', 1
    ), 'nonce inválido');
    echo "Monetizze nonce: validação aprovada; MySQL não executado (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Somente banco descartável cupons_test é permitido.');
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
$otherWorker = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_monetizze_relay_nonces.sql'));

$nonce = bin2hex(random_bytes(16));
$nonceTwo = bin2hex(random_bytes(16));
$now = time();
$expiresAt = $now + 300;
$secret = str_repeat('S', 48); // Synthetic test secret, not an operational credential.
$account = str_repeat('a', 32);
$event = ['id' => str_repeat('c', 64), 'chave_unica' => $account,
    'codigo_status' => '2', 'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER']];
$body = json_encode([$event], JSON_THROW_ON_ERROR);
$timestamp = (string) $now;
$signature = static fn(string $n): string =>
    hash_hmac('sha256', "monetizze-relay-v1\n" . $timestamp . "\n" . $n . "\n" . $body, $secret);
$run = static fn(string $n, string $sig, PDO $db): array =>
    MonetizzeSignedRelayReview::verifyAndPreview(
        $body, $timestamp, $n, $sig, $secret, $account,
        MonetizzeRelayNonceStore::callback($db), $now
    );

try {
    nonceReject(static fn() => MonetizzeRelayNonceStore::claim($pdo, 'bad', $expiresAt), 'nonce inválido');
    nonceReject(static fn() => MonetizzeRelayNonceStore::claim($pdo, $nonce, 0), 'expiração inválida');
    $first = $run($nonce, $signature($nonce), $pdo);
    nonceCheck($first['relay_authenticated'] === true && $first['authenticated'] === false
        && $first['financial_ready'] === false && $first['risk'] === 1, 'prévia sem autorização financeira');
    nonceCheck(MonetizzeRelayNonceStore::claim($otherWorker, $nonce, $expiresAt) === false,
        'duplicação recusada em outra conexão após commit');
    nonceReject(static fn() => $run($nonce, $signature($nonce), $otherWorker), 'replay após reinício');
    nonceReject(static fn() => $run($nonceTwo, str_repeat('0', 64), $pdo), 'assinatura adulterada');
    $second = $run($nonceTwo, $signature($nonceTwo), $otherWorker);
    nonceCheck($second['relay_authenticated'] && !$second['financial_ready'], 'nonce novo válido');
    $query = $pdo->prepare('SELECT nonce, expires_at FROM monetizze_relay_nonces WHERE nonce IN (?, ?)');
    $query->execute([$nonce, $nonceTwo]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    nonceCheck(count($rows) === 2, 'apenas dois nonces sintéticos persistidos');
    foreach ($rows as $row) {
        nonceCheck((int) $row['expires_at'] === $expiresAt, 'prazo armazenado');
        nonceCheck(!str_contains(json_encode($row, JSON_THROW_ON_ERROR), 'PRIVATE-BUYER'), 'sem PII');
    }
} finally {
    // Delete ONLY random records created by this test in the dedicated cupons_test DB.
    $cleanup = $pdo->prepare('DELETE FROM monetizze_relay_nonces WHERE nonce IN (?, ?)');
    $cleanup->execute([$nonce, $nonceTwo]);
}

echo "Monetizze nonce: MySQL atômico, replay entre conexões, fail-closed e minimização aprovados.\n";
