<?php
declare(strict_types=1);

/**
 * Isolated CI-only HTTP fixture. NOT a production Monetizze endpoint.
 * Run only via loopback PHP built-in server with disposable test credentials.
 */
if (PHP_SAPI !== 'cli-server'
    || getenv('APP_ENV') !== 'testing'
    || getenv('TEST_RELAY_HTTP_E2E') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['REQUEST_URI'] ?? '') !== '/relay-test') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $dsn = (string) getenv('TEST_DB_DSN');
    if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/D', $dsn)) {
        throw new RuntimeException('Test database required.');
    }
    require_once __DIR__ . '/../../includes/monetizze_relay_http_guard.php';
    require_once __DIR__ . '/../../includes/monetizze_relay_nonce_store.php';
    $pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $body = file_get_contents('php://input');
    if (!is_string($body)) {
        throw new RuntimeException('Body unavailable.');
    }
    $summary = MonetizzeRelayHttpGuard::verifyAndPreview(
        $_SERVER,
        $body,
        (string) getenv('TEST_RELAY_SECRET'),
        (string) getenv('TEST_RELAY_ACCOUNT'),
        MonetizzeRelayNonceStore::callback($pdo),
        getenv('TEST_RELAY_UPSTREAM') === '1',
        getenv('TEST_RELAY_ENABLED') === '1'
    );
    http_response_code(200);
    echo json_encode($summary, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | RuntimeException $exception) {
    http_response_code(400);
    echo '{"error":"invalid_request"}';
} catch (Throwable $exception) {
    http_response_code(500);
    echo '{"error":"internal_error"}';
}
