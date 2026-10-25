<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/monetizze_relay_http_guard.php';
require_once __DIR__ . '/../includes/monetizze_relay_nonce_store.php';

/**
 * Full transport guard -> signed relay -> durable MySQL nonce smoke test.
 * All identifiers and credentials are synthetic. NEVER point at a real database.
 * This test creates only its own random nonce rows in the dedicated cupons_test DB.
 */
function chainCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Monetizze HTTP/MySQL: ' . $label);
    }
}

function chainReject(Closure $operation, string $label): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $exception) {
        chainCheck(!str_contains($exception->getMessage(), 'PRIVATE-BUYER'), $label . ' sem PII');
        return;
    }
    throw new RuntimeException('Monetizze HTTP/MySQL aceitou: ' . $label);
}

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    if (getenv('GITHUB_ACTIONS') === 'true') {
        throw new RuntimeException('TEST_DB_DSN obrigatório no CI: teste MySQL não pode ser ignorado.');
    }
    echo "Monetizze HTTP/MySQL: integração não executada (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Somente o banco descartável cupons_test é permitido.');
}

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
$otherWorker = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
chainCheck($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'driver MySQL obrigatório');
$schema = file_get_contents(__DIR__ . '/../sql/updates/20261024_monetizze_relay_nonces.sql');
chainCheck(is_string($schema) && $schema !== '', 'migração de nonce disponível');
$pdo->exec($schema);

$secret = str_repeat('S', 48);
$account = str_repeat('a', 32);
$nonce = bin2hex(random_bytes(16));
$badNonce = bin2hex(random_bytes(16));
$invalidNonce = bin2hex(random_bytes(16));
$now = time();
$timestamp = (string) $now;
$body = json_encode([[
    'id' => str_repeat('c', 64),
    'chave_unica' => $account,
    'codigo_status' => '2',
    'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER'],
]], JSON_THROW_ON_ERROR);
$sign = static fn(string $payload, string $id): string =>
    hash_hmac('sha256', "monetizze-relay-v1\n" . $timestamp . "\n" . $id . "\n" . $payload, $secret);
$server = [
    'REQUEST_METHOD' => 'POST',
    'CONTENT_TYPE' => 'application/json; charset=utf-8',
    'CONTENT_LENGTH' => (string) strlen($body),
    'HTTP_X_MONETIZZE_RELAY_TIMESTAMP' => $timestamp,
    'HTTP_X_MONETIZZE_RELAY_NONCE' => $nonce,
    'HTTP_X_MONETIZZE_RELAY_SIGNATURE' => $sign($body, $nonce),
];
$run = static fn(array $headers, string $payload, PDO $connection, bool $upstream = true, bool $enabled = true): array =>
    MonetizzeRelayHttpGuard::verifyAndPreview(
        $headers, $payload, $secret, $account,
        MonetizzeRelayNonceStore::callback($connection),
        $upstream, $enabled, $now
    );
$count = static function () use ($pdo, $nonce, $badNonce, $invalidNonce): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM monetizze_relay_nonces WHERE nonce IN (?, ?, ?)');
    $stmt->execute([$nonce, $badNonce, $invalidNonce]);
    return (int) $stmt->fetchColumn();
};

try {
    chainCheck($count() === 0, 'nonces aleatórios inéditos');
    chainReject(static fn() => $run($server, $body, $pdo, false), 'upstream não homologado');
    chainReject(static fn() => $run($server, $body, $pdo, true, false), 'integração desabilitada');
    chainCheck($count() === 0, 'flags não gravam nonce');

    $invalidLength = array_replace($server, [
        'CONTENT_LENGTH' => '1',
        'HTTP_X_MONETIZZE_RELAY_NONCE' => $invalidNonce,
    ]);
    chainReject(static fn() => $run($invalidLength, $body, $pdo), 'Content-Length adulterado');

    $badSignature = array_replace($server, [
        'HTTP_X_MONETIZZE_RELAY_NONCE' => $badNonce,
        'HTTP_X_MONETIZZE_RELAY_SIGNATURE' => str_repeat('0', 64),
    ]);
    chainReject(static fn() => $run($badSignature, $body, $pdo), 'HMAC inválido');
    chainCheck($count() === 0, 'entradas inválidas não reservam nonce');

    $result = $run($server, $body, $pdo);
    chainCheck($result['relay_authenticated'] === true && $result['risk'] === 1, 'cadeia HMAC e classificação');
    chainCheck($result['origin_verified'] === false && $result['authenticated'] === false
        && $result['financial_ready'] === false, 'sem autenticação nativa ou efeito financeiro');
    chainCheck(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'PRIVATE-BUYER'), 'resposta sem PII');
    chainCheck($count() === 1, 'somente nonce válido persistido');

    chainReject(static fn() => $run($server, $body, $otherWorker), 'replay entre conexões');
    chainCheck($count() === 1, 'replay não grava outra linha');

    // Even a correctly signed JSON with duplicate keys must be rejected BEFORE nonce claim.
    $duplicate = '[{"id":"' . str_repeat('c', 64) . '","id":"' . str_repeat('c', 64)
        . '","chave_unica":"' . $account . '","codigo_status":"2","postback_evento":"9"}]';
    $ambiguous = array_replace($server, [
        'CONTENT_LENGTH' => (string) strlen($duplicate),
        'HTTP_X_MONETIZZE_RELAY_NONCE' => $badNonce,
        'HTTP_X_MONETIZZE_RELAY_SIGNATURE' => $sign($duplicate, $badNonce),
    ]);
    chainReject(static fn() => $run($ambiguous, $duplicate, $otherWorker), 'JSON assinado ambíguo');
    chainCheck($count() === 1, 'JSON ambíguo não consome nonce');
} finally {
    // Remove ONLY the random synthetic nonces generated by this test.
    $cleanup = $pdo->prepare('DELETE FROM monetizze_relay_nonces WHERE nonce IN (?, ?, ?)');
    $cleanup->execute([$nonce, $badNonce, $invalidNonce]);
}

echo "Monetizze HTTP/MySQL: cadeia real, flags fail-closed, HMAC, nonce persistente, replay e privacidade aprovados.\n";
