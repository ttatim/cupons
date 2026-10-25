<?php
declare(strict_types=1);

/**
 * HTTP integration of the real admin login and Monetizze triage UI.
 * Synthetic identities and candidates ONLY; cupons_test MySQL ONLY.
 * No production endpoint, no provider credentials and no financial action.
 */
function reviewHttpCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Monetizze admin HTTP: ' . $message);
    }
}
$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    if (getenv('GITHUB_ACTIONS') === 'true') {
        throw new RuntimeException('TEST_DB_DSN obrigatório no CI.');
    }
    echo "Monetizze admin HTTP: não executado (TEST_DB_DSN ausente).\n";
    exit(0);
}
reviewHttpCheck((bool) preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/D', $dsn), 'banco descartável cupons_test obrigatório');
reviewHttpCheck(extension_loaded('curl') && extension_loaded('pdo_mysql'), 'curl e pdo_mysql obrigatórios');
reviewHttpCheck(function_exists('proc_open'), 'proc_open obrigatório para o servidor isolado');

putenv('APP_ENV=testing');
putenv('DB_HOST=127.0.0.1');
putenv('DB_NAME=cupons_test');
putenv('DB_USER=' . (string) getenv('TEST_DB_USER'));
putenv('DB_PASS=' . (string) getenv('TEST_DB_PASSWORD'));
require_once __DIR__ . '/../includes/config.php'; // Bootstrap existing schema, no demo users.
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261025_monetizze_review_candidates.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261025_monetizze_review_decisions.sql'));

$account = bin2hex(random_bytes(16));
$otherAccount = bin2hex(random_bytes(16));
$hashKey = bin2hex(random_bytes(32));
$adminName = 't' . bin2hex(random_bytes(8));
$editorName = 'e' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24));
$adminId = 0;
$editorId = 0;
$reviewId = 0;
$otherId = 0;
$server = null;
$logFile = null;
$cookies = [];
$port = 0;

$cleanup = static function () use ($pdo, &$server, &$logFile, &$cookies, &$reviewId, &$otherId, &$adminId, &$editorId): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
        $server = null;
    }
    foreach ($cookies as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_string($logFile) && is_file($logFile)) {
        unlink($logFile);
    }
    // Only delete synthetic rows created by THIS test; never truncate or reset a table.
    foreach ([$reviewId, $otherId] as $id) {
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM monetizze_review_decisions WHERE candidate_id = ?');
            $stmt->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM monetizze_review_candidates WHERE id = ?');
            $stmt->execute([$id]);
        }
    }
    foreach ([$adminId, $editorId] as $id) {
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
            $stmt->execute([$id]);
        }
    }
};
$http = static function (string $path, string $cookie, ?array $form = null) use (&$port): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    reviewHttpCheck($handle !== false, 'inicializar cURL');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
    ]);
    if ($form !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $error = curl_error($handle);
    curl_close($handle);
    reviewHttpCheck(is_string($response), 'requisição HTTP: ' . $error);
    return [$status, substr($response, $headerSize), substr($response, 0, $headerSize)];
};
$hidden = static function (string $html, string $name): string {
    $pattern = '/name="' . preg_quote($name, '/') . '" value="([^"]+)"/';
    reviewHttpCheck(preg_match($pattern, $html, $match) === 1, 'campo HTML ' . $name);
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
};

try {
    $insertUser = $pdo->prepare('INSERT INTO usuarios (username, email, password, nome, nivel, ativo) VALUES (?, ?, ?, ?, ?, 1)');
    $insertUser->execute([$adminName, $adminName . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'Synthetic Admin', 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    $insertUser->execute([$editorName, $editorName . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'Synthetic Editor', 'editor']);
    $editorId = (int) $pdo->lastInsertId();

    $insertCandidate = $pdo->prepare('INSERT INTO monetizze_review_candidates
        (account_hash, event_hash, candidate_status, candidate_event, original_status, original_event, risk_direction)
        VALUES (?, ?, 3, 9, 2, 2, ?)');
    $insertCandidate->execute([hash_hmac('sha256', 'monetizze:account:' . $account, $hashKey), bin2hex(random_bytes(32)), 'escalation']);
    $reviewId = (int) $pdo->lastInsertId();
    $insertCandidate->execute([hash_hmac('sha256', 'monetizze:account:' . $otherAccount, $hashKey), bin2hex(random_bytes(32)), 'escalation']);
    $otherId = (int) $pdo->lastInsertId();

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    reviewHttpCheck(is_resource($socket), 'porta loopback');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    reviewHttpCheck(is_string($address) && preg_match('/:(\d+)$/D', $address, $match) === 1, 'porta dinâmica');
    $port = (int) $match[1];
    $logFile = tempnam(sys_get_temp_dir(), 'cupons-admin-http-');
    reviewHttpCheck(is_string($logFile), 'log temporário');
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_HOST' => '127.0.0.1',
        'DB_NAME' => 'cupons_test',
        'DB_USER' => (string) getenv('TEST_DB_USER'),
        'DB_PASS' => (string) getenv('TEST_DB_PASSWORD'),
        'MONETIZZE_REVIEW_ACCOUNT_KEY' => $account,
        'MONETIZZE_REVIEW_HASH_KEY' => $hashKey,
    ]);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes, dirname(__DIR__), $env);
    reviewHttpCheck(is_resource($server), 'servidor iniciado');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    reviewHttpCheck($ready, 'servidor pronto');
    foreach (['guest', 'admin', 'editor'] as $role) {
        $cookie = tempnam(sys_get_temp_dir(), 'cupons-cookie-');
        reviewHttpCheck(is_string($cookie), 'cookie isolado');
        $cookies[$role] = $cookie;
    }

    [$status, , $headers] = $http('/admin/monetizze-revisao.php', $cookies['guest']);
    reviewHttpCheck($status === 302 && str_contains($headers, 'Location: login.php'), 'acesso anônimo redirecionado');

    $login = static function (string $username, string $cookie) use ($http, $hidden, $password): void {
        [$status, $html] = $http('/admin/login.php', $cookie);
        reviewHttpCheck($status === 200, 'GET login');
        $csrf = $hidden($html, 'csrf_token');
        [$status, , $headers] = $http('/admin/login.php', $cookie, [
            'username' => $username, 'password' => $password, 'csrf_token' => $csrf,
        ]);
        reviewHttpCheck($status === 302 && str_contains($headers, 'Location: dashboard.php'), 'login válido');
    };
    $login($adminName, $cookies['admin']);
    [$status, $html] = $http('/admin/monetizze-revisao.php', $cookies['admin']);
    reviewHttpCheck($status === 200 && str_contains($html, '?review_id=' . $reviewId), 'fila da conta correta');
    reviewHttpCheck(!str_contains($html, '?review_id=' . $otherId), 'candidato de outra conta oculto');
    reviewHttpCheck(!str_contains($html, $account) && !str_contains($html, $hashKey), 'segredos não expostos');

    [$status, $html] = $http('/admin/monetizze-revisao.php?review_id=' . $reviewId, $cookies['admin']);
    reviewHttpCheck($status === 200 && str_contains($html, 'Registrar triagem'), 'formulário de triagem');
    $csrf = $hidden($html, 'csrf_token');
    $requestId = $hidden($html, 'request_id');
    $form = ['csrf_token' => $csrf, 'review_id' => (string) $reviewId,
        'request_id' => $requestId, 'action' => 'request_evidence'];
    [$status] = $http('/admin/monetizze-revisao.php', $cookies['admin'], array_replace($form, ['csrf_token' => 'invalid']));
    reviewHttpCheck($status === 400, 'CSRF inválido rejeitado');
    [$status] = $http('/admin/monetizze-revisao.php', $cookies['admin'], array_replace($form, ['action' => 'approve_payment']));
    reviewHttpCheck($status === 400, 'aprovação financeira rejeitada');
    $count = $pdo->prepare('SELECT COUNT(*) FROM monetizze_review_decisions WHERE candidate_id = ?');
    $count->execute([$reviewId]);
    reviewHttpCheck((int) $count->fetchColumn() === 0, 'ações inválidas não persistidas');

    [$status, , $headers] = $http('/admin/monetizze-revisao.php', $cookies['admin'], $form);
    reviewHttpCheck($status === 303 && str_contains($headers, 'saved=1'), 'triagem válida usa PRG');
    [$status] = $http('/admin/monetizze-revisao.php', $cookies['admin'], $form);
    reviewHttpCheck($status === 303, 'requisição idempotente aceita sem duplicar');
    $count->execute([$reviewId]);
    reviewHttpCheck((int) $count->fetchColumn() === 1, 'uma única decisão persistida');
    [$status, $html] = $http('/admin/monetizze-revisao.php?review_id=' . $reviewId, $cookies['admin']);
    reviewHttpCheck($status === 200 && str_contains($html, 'request_evidence'), 'histórico visível');
    [$status, $html] = $http('/admin/monetizze-revisao.php?review_id=' . $otherId, $cookies['admin']);
    reviewHttpCheck($status === 400 && !str_contains($html, 'request_evidence'), 'histórico de outra conta negado');

    $login($editorName, $cookies['editor']);
    [$status] = $http('/admin/monetizze-revisao.php', $cookies['editor']);
    reviewHttpCheck($status === 403, 'editor não pode consultar');
    [$status] = $http('/admin/monetizze-revisao.php', $cookies['editor'], $form);
    reviewHttpCheck($status === 403, 'editor não pode decidir');
    $count->execute([$reviewId]);
    reviewHttpCheck((int) $count->fetchColumn() === 1, 'negação não altera auditoria');
    echo "Monetizze admin HTTP: login, sessão, CSRF, autorização, isolamento, idempotência e triagem aprovados.\n";
} finally {
    $cleanup();
}
