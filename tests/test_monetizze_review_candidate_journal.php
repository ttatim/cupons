<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/monetizze_review_candidate_journal.php';

function journalCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Monetizze review journal: ' . $label);
    }
}
function journalReject(Closure $call, string $label): void
{
    try {
        $call();
    } catch (InvalidArgumentException | RuntimeException $expected) {
        journalCheck(!str_contains($expected->getMessage(), 'SYNTHETIC-PRIVATE-BUYER'),
            'erro não expõe PII');
        return;
    }
    throw new RuntimeException('Entrada insegura aceita: ' . $label);
}

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Monetizze review journal: integração MySQL não executada (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Somente banco descartável cupons_test é permitido.');
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
$other = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $options);
foreach ([
    '20261024_monetizze_relay_nonces.sql',
    '20261024_monetizze_event_observations.sql',
    '20261025_monetizze_review_candidates.sql',
] as $migration) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/' . $migration));
}

$account = bin2hex(random_bytes(16));
$foreign = bin2hex(random_bytes(16));
$hashKey = str_repeat('H', 48); // Test-only; not an operational secret.
$relaySecret = str_repeat('S', 48);
$accountHash = hash_hmac('sha256', 'monetizze:account:' . $account, $hashKey);
$ids = array_map(static fn() => bin2hex(random_bytes(32)), range(1, 5));
$hashId = static fn(string $id): string => hash_hmac('sha256', 'monetizze:event:' . $id, $hashKey);
$event = static fn(string $id, string $acc, string $status, string $code): array => [
    'id' => $id, 'chave_unica' => $acc, 'codigo_status' => $status,
    'postback_evento' => $code, 'comprador' => ['nome' => 'SYNTHETIC-PRIVATE-BUYER'],
];
$nonces = array_map(static fn() => bin2hex(random_bytes(16)), range(1, 5));
$now = time();
$timestamp = (string) $now;
$sign = static fn(string $nonce, string $body): string => hash_hmac('sha256',
    "monetizze-relay-v1\n" . $timestamp . "\n" . $nonce . "\n" . $body, $relaySecret);
$run = static fn(PDO $db, string $nonce, string $body, ?string $sig = null): array =>
    MonetizzeReviewCandidateJournal::verifyAndRecord(
        $db, $body, $timestamp, $nonce, $sig ?? $sign($nonce, $body),
        $relaySecret, $account, $hashKey, $now
    );

$seed = $pdo->prepare('INSERT INTO monetizze_event_observations
    (account_hash, event_hash, observed_status, observed_event, review_required)
    VALUES (?, ?, ?, ?, ?)');
$batch = [
    $event($ids[0], $account, '2', '9'), // positive -> risk
    $event($ids[1], $account, '6', '2'), // risk -> apparent reduction
    $event($ids[2], $account, '5', '9'), // risk -> other risk
    $event($ids[3], $account, '2', '2'), // unchanged
    $event($ids[3], $account, '2', '2'), // identical duplicate
    $event($ids[4], $account, '4', '9'), // no baseline
    $event(bin2hex(random_bytes(32)), $foreign, '2', '2'), // another account
];
$body = json_encode($batch, JSON_THROW_ON_ERROR);

try {
    foreach ([[2, 2, 0], [4, 9, 1], [3, 9, 1], [2, 2, 0]] as $i => $values) {
        $seed->execute([$accountHash, $hashId($ids[$i]), ...$values]);
    }
    journalReject(static fn() => $run($pdo, $nonces[0], $body, str_repeat('0', 64)),
        'assinatura adulterada');
    $first = $run($pdo, $nonces[0], $body);
    journalCheck($first['candidates_recorded'] === 3
        && $first['candidates_existing'] === 0
        && $first['risk_escalations'] === 1
        && $first['risk_reductions_unverified'] === 1
        && $first['other_changes'] === 1
        && $first['untracked'] === 1 && $first['unchanged'] === 1
        && $first['other_account'] === 1 && $first['duplicates'] === 1
        && $first['relay_authenticated'] === true
        && $first['authenticated'] === false && $first['financial_ready'] === false,
        'contagens sem autenticação upstream ou liberação financeira');
    journalReject(static fn() => $run($other, $nonces[0], $body), 'nonce repetido');
    $repeat = $run($other, $nonces[1], $body);
    journalCheck($repeat['candidates_recorded'] === 0
        && $repeat['candidates_existing'] === 3, 'idempotência entre conexões');
    $query = $pdo->prepare('SELECT event_hash, candidate_status, candidate_event,
        original_status, original_event, risk_direction
        FROM monetizze_review_candidates WHERE account_hash = ? ORDER BY id');
    $query->execute([$accountHash]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    journalCheck(count($rows) === 3, 'somente divergências persistidas');
    $serialized = json_encode([$rows, $first], JSON_THROW_ON_ERROR);
    journalCheck(!str_contains($serialized, 'SYNTHETIC-PRIVATE-BUYER')
        && !str_contains($serialized, $account)
        && !str_contains($serialized, $ids[0]), 'minimização de dados');
    $original = $pdo->prepare('SELECT observed_status, observed_event
        FROM monetizze_event_observations WHERE account_hash = ? AND event_hash = ?');
    $original->execute([$accountHash, $hashId($ids[0])]);
    journalCheck($original->fetch(PDO::FETCH_NUM) === [2, 2]
        || (function () use ($pdo, $accountHash, $hashId, $ids): bool {
            $q = $pdo->prepare('SELECT observed_status, observed_event
                FROM monetizze_event_observations WHERE account_hash = ? AND event_hash = ?');
            $q->execute([$accountHash, $hashId($ids[0])]);
            $r = $q->fetch(PDO::FETCH_NUM);
            return $r !== false && (int) $r[0] === 2 && (int) $r[1] === 2;
        })(), 'observação original preservada');
    $invalid = json_encode([$event($ids[0], $account, '02', '9')], JSON_THROW_ON_ERROR);
    journalReject(static fn() => $run($pdo, $nonces[2], $invalid), 'código ambíguo');
    $different = json_encode([
        $event($ids[0], $account, '2', '9'),
        $event($ids[0], $account, '6', '2'),
    ], JSON_THROW_ON_ERROR);
    journalReject(static fn() => $run($pdo, $nonces[3], $different),
        'mesmo ID com códigos diferentes no lote');
    $nonceQuery = $pdo->prepare('SELECT COUNT(*) FROM monetizze_relay_nonces WHERE nonce IN (?, ?)');
    $nonceQuery->execute([$nonces[2], $nonces[3]]);
    journalCheck((int) $nonceQuery->fetchColumn() === 0, 'falhas não consomem nonces');
    journalCheck(!$pdo->inTransaction(), 'transação encerrada');
} finally {
    // Only random, test-owned rows from the explicitly disposable cupons_test DB.
    $cleanup = $pdo->prepare('DELETE FROM monetizze_review_candidates WHERE account_hash = ?');
    $cleanup->execute([$accountHash]);
    $cleanup = $pdo->prepare('DELETE FROM monetizze_event_observations WHERE account_hash = ?');
    $cleanup->execute([$accountHash]);
    $cleanup = $pdo->prepare('DELETE FROM monetizze_relay_nonces WHERE nonce IN (?, ?, ?, ?, ?)');
    $cleanup->execute($nonces);
}
echo "Monetizze review journal: atomicidade, idempotência, isolamento e minimização aprovados.\n";
