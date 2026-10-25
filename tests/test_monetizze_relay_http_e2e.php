<?php
declare(strict_types=1);

/**
 * Real loopback HTTP -> PHP built-in server -> HMAC guard -> MySQL nonce E2E.
 * Synthetic payloads only; disposable cupons_test database required.
 * This test never creates a production webhook.
 */
function e2eCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Monetizze HTTP E2E: ' . $message);
    }
}
$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    if (getenv('GITHUB_ACTIONS') === 'true') {
        throw new RuntimeException('TEST_DB_DSN obrigatório no CI.');
    }
    echo "Monetizze HTTP E2E: não executado (TEST_DB_DSN ausente).\n";
    exit(0);
}
e2eCheck((bool) preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/D', $dsn), 'somente banco cupons_test');
e2eCheck(extension_loaded('curl') && extension_loaded('pdo_mysql'), 'extensões curl e pdo_mysql');
e2eCheck(function_exists('proc_open'), 'proc_open necessário para servidor de teste');

$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
e2eCheck($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'driver MySQL');
$schema = file_get_contents(__DIR__ . '/../sql/updates/20261024_monetizze_relay_nonces.sql');
e2eCheck(is_string($schema) && $schema !== '', 'migração disponível');
$pdo->exec($schema);

$secret = str_repeat('E', 48);
$account = str_repeat('a', 32);
$validNonce = bin2hex(random_bytes(16));
$badNonce = bin2hex(random_bytes(16));
$ambiguousNonce = bin2hex(random_bytes(16));
$timestamp = (string) time();
$body = json_encode([[
    'id' => str_repeat('c', 64), 'chave_unica' => $account,
    'codigo_status' => '2', 'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER'],
]], JSON_THROW_ON_ERROR);
$sign = static fn(string $payload, string $nonce): string =>
    hash_hmac('sha256', "monetizze-relay-v1\n$timestamp\n$nonce\n$payload", $secret);

$fixture = __DIR__ . '/fixtures/monetizze_http_router.php';
$docroot = __DIR__ . '/fixtures';
$process = null;
$logFile = null;
$port = null;
$stop = static function () use (&$process, &$logFile): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
        $process = null;
    }
    if (is_string($logFile) && is_file($logFile)) {
        unlink($logFile);
        $logFile = null;
    }
};
$start = static function (bool $enabled, bool $upstream) use (
    &$process, &$logFile, &$port, $fixture, $docroot, $secret, $account, $stop
): void {
    $stop();
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    e2eCheck(is_resource($socket), 'porta loopback disponível');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    e2eCheck(is_string($address) && preg_match('/:(\d+)$/D', $address, $matches) === 1, 'porta alocada');
    $port = (int) $matches[1];
    $logFile = tempnam(sys_get_temp_dir(), 'cupons-http-e2e-');
    e2eCheck(is_string($logFile), 'log temporário disponível');
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'TEST_RELAY_HTTP_E2E' => '1',
        'TEST_RELAY_ENABLED' => $enabled ? '1' : '0',
        'TEST_RELAY_UPSTREAM' => $upstream ? '1' : '0',
        'TEST_RELAY_SECRET' => $secret,
        'TEST_RELAY_ACCOUNT' => $account,
    ]);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $fixture],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        dirname(__DIR__),
        $env
    );
    e2eCheck(is_resource($process), 'servidor PHP iniciado');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    e2eCheck($ready, 'servidor HTTP pronto');
};
$request = static function (string $method, string $payload, string $nonce, ?string $signature = null,
    string $contentType = 'application/json') use (&$port, $timestamp, $sign, $secret): array {
    $handle = curl_init('http://127.0.0.1:' . $port . '/relay-test');
    e2eCheck($handle !== false, 'cliente HTTP inicializado');
    $headers = [
        'Content-Type: ' . $contentType,
        'X-Monetizze-Relay-Timestamp: ' . $timestamp,
        'X-Monetizze-Relay-Nonce: ' . $nonce,
        'X-Monetizze-Relay-Signature: ' . ($signature ?? $sign($payload, $nonce)),
    ];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
    }
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    e2eCheck(is_string($response), 'requisição HTTP concluída: ' . $error);
    $decoded = json_decode($response, true);
    e2eCheck(is_array($decoded), 'resposta JSON válida');
    e2eCheck(!str_contains($response, 'PRIVATE-BUYER') && !str_contains($response, $secret), 'sem PII ou segredo na resposta');
    return [$status, $decoded];
};
$count = static function () use ($pdo, $validNonce, $badNonce, $ambiguousNonce): int {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM monetizze_relay_nonces WHERE nonce IN (?, ?, ?)');
    $statement->execute([$validNonce, $badNonce, $ambiguousNonce]);
    return (int) $statement->fetchColumn();
};

try {
    e2eCheck($count() === 0, 'nonces sintéticos inéditos');
    $start(false, false);
    [$status] = $request('POST', $body, $validNonce);
    e2eCheck($status === 400 && $count() === 0, 'relay desabilitado por padrão');

    $start(true, false);
    [$status] = $request('POST', $body, $validNonce);
    e2eCheck($status === 400 && $count() === 0, 'upstream não homologado bloqueado');

    $start(true, true); // Synthetic fixture ONLY, not real upstream proof.
    [$status] = $request('GET', '', $badNonce);
    e2eCheck($status === 400 && $count() === 0, 'GET rejeitado');
    [$status] = $request('POST', $body, $badNonce, str_repeat('0', 64));
    e2eCheck($status === 400 && $count() === 0, 'HMAC inválido não consome nonce');
    [$status] = $request('POST', $body, $badNonce, null, 'text/plain');
    e2eCheck($status === 400 && $count() === 0, 'Content-Type inválido rejeitado');

    $duplicate = '[{"id":"' . str_repeat('c', 64) . '","id":"' . str_repeat('c', 64)
        . '","chave_unica":"' . $account . '","codigo_status":"2","postback_evento":"9"}]';
    [$status] = $request('POST', $duplicate, $ambiguousNonce);
    e2eCheck($status === 400 && $count() === 0, 'JSON ambíguo rejeitado antes de nonce');

    [$status, $result] = $request('POST', $body, $validNonce);
    e2eCheck($status === 200 && ($result['relay_authenticated'] ?? null) === true, 'HTTP e HMAC aprovados');
    e2eCheck(($result['risk'] ?? null) === 1, 'classificação de risco preservada');
    e2eCheck(($result['origin_verified'] ?? null) === false
        && ($result['authenticated'] ?? null) === false
        && ($result['financial_ready'] ?? null) === false, 'nenhuma comprovação de origem ou autorização financeira');
    e2eCheck($count() === 1, 'nonce persistido no MySQL');

    [$status] = $request('POST', $body, $validNonce);
    e2eCheck($status === 400 && $count() === 1, 'replay HTTP rejeitado após persistência');
} finally {
    $stop();
    // Remove ONLY the three random synthetic nonces owned by this test.
    $cleanup = $pdo->prepare('DELETE FROM monetizze_relay_nonces WHERE nonce IN (?, ?, ?)');
    $cleanup->execute([$validNonce, $badNonce, $ambiguousNonce]);
}
echo "Monetizze HTTP E2E: loopback, gates, HMAC, MySQL, replay, ambiguidade e privacidade aprovados.\n";
