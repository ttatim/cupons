<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/monetizze_review_queue.php';

function reviewQueueCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Monetizze review queue: ' . $message);
    }
}

function reviewQueueReject(Closure $call, string $message): void
{
    try {
        $call();
    } catch (InvalidArgumentException | RuntimeException $expected) {
        return;
    }
    throw new RuntimeException('Entrada insegura aceita: ' . $message);
}

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Monetizze review queue: integração MySQL não executada (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Somente banco descartável cupons_test é permitido.');
}
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'),
    (string) getenv('TEST_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
$pdo->exec((string) file_get_contents(
    __DIR__ . '/../sql/updates/20261025_monetizze_review_candidates.sql'
));

$account = bin2hex(random_bytes(16));
$foreign = bin2hex(random_bytes(16));
$hashKey = str_repeat('Q', 48); // Synthetic test-only key.
$accountHash = hash_hmac('sha256', 'monetizze:account:' . $account, $hashKey);
$foreignHash = hash_hmac('sha256', 'monetizze:account:' . $foreign, $hashKey);
$insert = $pdo->prepare('INSERT INTO monetizze_review_candidates
    (account_hash, event_hash, candidate_status, candidate_event,
     original_status, original_event, risk_direction)
    VALUES (?, ?, ?, ?, ?, ?, ?)');
$count = $pdo->prepare('SELECT COUNT(*) FROM monetizze_review_candidates WHERE account_hash = ?');

try {
    for ($i = 0; $i < 3; $i++) {
        $insert->execute([
            $accountHash, hash_hmac('sha256', 'monetizze:event:' . bin2hex(random_bytes(32)), $hashKey),
            3 + $i, 9, 2, 2, 'escalation',
        ]);
    }
    $insert->execute([
        $foreignHash, hash_hmac('sha256', 'monetizze:event:' . bin2hex(random_bytes(32)), $hashKey),
        6, 2, 4, 9, 'reduction_unverified',
    ]);
    $count->execute([$accountHash]);
    $before = (int) $count->fetchColumn();

    $first = MonetizzeReviewQueue::listForAccount($pdo, strtoupper($account), $hashKey, 0, 2);
    reviewQueueCheck(count($first['items']) === 2 && $first['has_more'] === true,
        'primeira página limitada');
    reviewQueueCheck($first['authenticated'] === false
        && $first['financial_ready'] === false && $first['read_only'] === true,
        'sem autorização financeira ou autenticação upstream');
    reviewQueueCheck($first['items'][0]['review_id'] < $first['items'][1]['review_id'],
        'ordenação estável por ID local');
    $second = MonetizzeReviewQueue::listForAccount(
        $pdo, $account, $hashKey, $first['next_after_id'], 2
    );
    reviewQueueCheck(count($second['items']) === 1 && $second['has_more'] === false,
        'segunda página sem duplicações');
    reviewQueueCheck($second['items'][0]['review_id'] > $first['next_after_id'],
        'cursor exclusivo');
    $foreignOnly = MonetizzeReviewQueue::listForAccount($pdo, $foreign, $hashKey);
    reviewQueueCheck(count($foreignOnly['items']) === 1, 'isolamento de outra conta');
    $wrongKey = MonetizzeReviewQueue::listForAccount($pdo, $account, str_repeat('X', 48));
    reviewQueueCheck(count($wrongKey['items']) === 0, 'chave de hash incorreta não lê registros');

    $serialized = json_encode([$first, $second, $foreignOnly], JSON_THROW_ON_ERROR);
    reviewQueueCheck(!str_contains($serialized, $accountHash)
        && !str_contains($serialized, $foreignHash)
        && !str_contains($serialized, $account)
        && !str_contains($serialized, $foreign),
        'nenhum hash ou identificador de conta exposto');
    reviewQueueCheck(array_keys($first['items'][0]) === [
        'review_id', 'original_status', 'original_event',
        'candidate_status', 'candidate_event', 'risk_direction', 'first_seen_at',
    ], 'campos retornados minimizados');

    reviewQueueReject(static fn() => MonetizzeReviewQueue::listForAccount(
        $pdo, 'invalid', $hashKey), 'conta inválida');
    reviewQueueReject(static fn() => MonetizzeReviewQueue::listForAccount(
        $pdo, $account, 'short'), 'chave curta');
    reviewQueueReject(static fn() => MonetizzeReviewQueue::listForAccount(
        $pdo, $account, $hashKey, -1), 'cursor negativo');
    reviewQueueReject(static fn() => MonetizzeReviewQueue::listForAccount(
        $pdo, $account, $hashKey, 0, 0), 'limite zero');
    reviewQueueReject(static fn() => MonetizzeReviewQueue::listForAccount(
        $pdo, $account, $hashKey, 0, 101), 'limite excessivo');

    $count->execute([$accountHash]);
    reviewQueueCheck((int) $count->fetchColumn() === $before,
        'leitura não altera a fila');
} finally {
    // Remove only rows owned by this synthetic test in the disposable DB.
    $delete = $pdo->prepare('DELETE FROM monetizze_review_candidates WHERE account_hash = ?');
    $delete->execute([$accountHash]);
    $delete->execute([$foreignHash]);
}
echo "Monetizze review queue: paginação, isolamento, minimização e leitura aprovados.\n";
