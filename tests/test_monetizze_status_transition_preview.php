<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/monetizze_status_transition_preview.php';

function transitionAssert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Monetizze transition preview: ' . $label);
    }
}
function transitionReject(Closure $callback, string $label): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $expected) {
        transitionAssert(!str_contains($expected->getMessage(), 'SYNTHETIC-BUYER'), 'erro sem PII');
        return;
    }
    throw new RuntimeException('Entrada inválida aceita: ' . $label);
}

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Monetizze transition preview: integração MySQL não executada (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Somente banco descartável cupons_test é permitido.');
}
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_monetizze_event_observations.sql'));
$account = bin2hex(random_bytes(16));
$otherAccount = bin2hex(random_bytes(16));
$key = str_repeat('T', 48); // Test-only, never operational.
$accountHash = hash_hmac('sha256', 'monetizze:account:' . $account, $key);
$otherHash = hash_hmac('sha256', 'monetizze:account:' . $otherAccount, $key);
$ids = array_map(static fn() => bin2hex(random_bytes(32)), range(1, 5));
$hashId = static fn(string $id): string => hash_hmac('sha256', 'monetizze:event:' . $id, $key);
$event = static fn(string $id, string $acc, string $status, string $code): array => [
    'id' => $id, 'chave_unica' => $acc, 'codigo_status' => $status,
    'postback_evento' => $code, 'comprador' => ['nome' => 'SYNTHETIC-BUYER'],
];
$seed = $pdo->prepare('INSERT INTO monetizze_event_observations
    (account_hash, event_hash, observed_status, observed_event, review_required)
    VALUES (?, ?, ?, ?, ?)');
$delete = $pdo->prepare('DELETE FROM monetizze_event_observations
    WHERE account_hash = ? AND event_hash = ?');
try {
    $seed->execute([$accountHash, $hashId($ids[0]), 2, 2, 0]); // positive -> risk
    $seed->execute([$accountHash, $hashId($ids[1]), 4, 9, 1]); // risk -> apparent positive
    $seed->execute([$accountHash, $hashId($ids[2]), 3, 9, 1]); // risk -> other risk
    $seed->execute([$accountHash, $hashId($ids[3]), 2, 2, 0]); // unchanged
    $seed->execute([$otherHash, $hashId($ids[4]), 2, 2, 0]); // other account, same ID as new

    $batch = [
        $event($ids[0], $account, '2', '9'),
        $event($ids[1], $account, '6', '2'),
        $event($ids[2], $account, '5', '9'),
        $event($ids[3], $account, '2', '2'),
        $event($ids[3], $account, '2', '2'),
        $event($ids[4], $account, '4', '9'),
        $event(bin2hex(random_bytes(32)), $otherAccount, '2', '2'),
    ];
    $expected = [
        'new' => 1, 'unchanged' => 1, 'conflicting' => 3,
        'new_risk' => 1, 'risk_escalations' => 1, 'risk_reductions_unverified' => 1,
        'other_account' => 1, 'duplicates' => 1,
        'authenticated' => false, 'financial_ready' => false,
    ];
    $summary = MonetizzeStatusTransitionPreview::compare($pdo, $batch, $account, $key);
    transitionAssert($summary === $expected, 'contagens agregadas, isolamento e sem autorização');
    $serialized = json_encode($summary, JSON_THROW_ON_ERROR);
    transitionAssert(!str_contains($serialized, 'SYNTHETIC-BUYER')
        && !str_contains($serialized, $account)
        && !str_contains($serialized, $ids[0]), 'sem PII e identificadores');
    transitionAssert(!$pdo->inTransaction(), 'não abre transação');
    $count = $pdo->prepare('SELECT COUNT(*) FROM monetizze_event_observations WHERE account_hash = ?');
    $count->execute([$accountHash]);
    transitionAssert((int) $count->fetchColumn() === 4, 'sem gravações na conta');
    $count->execute([$otherHash]);
    transitionAssert((int) $count->fetchColumn() === 1, 'sem gravações na outra conta');
    transitionReject(static fn() => MonetizzeStatusTransitionPreview::compare($pdo, $batch, $account, 'short'),
        'chave curta');
    transitionReject(static fn() => MonetizzeStatusTransitionPreview::compare(
        $pdo, [$event($ids[0], $account, '02', '9')], $account, $key
    ), 'código ambíguo');
    transitionReject(static fn() => MonetizzeStatusTransitionPreview::compare(
        $pdo, [$event($ids[0], $account, '2', '9'), $event($ids[0], $account, '2', '2')],
        $account, $key
    ), 'duplicidade divergente');
} finally {
    foreach (array_slice($ids, 0, 4) as $id) {
        $delete->execute([$accountHash, $hashId($id)]);
    }
    $delete->execute([$otherHash, $hashId($ids[4])]);
}
echo "Monetizze transition preview: leitura, riscos, isolamento e minimização aprovados.\n";
