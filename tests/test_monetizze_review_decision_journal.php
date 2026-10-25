<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_review_decision_journal.php';

function decisionCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('Monetizze decisions: ' . $label);
}
function decisionReject(Closure $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException | RuntimeException $e) {
        decisionCheck(!str_contains($e->getMessage(), 'SYNTHETIC-PRIVATE-BUYER'), 'sem PII em erro');
        return;
    }
    throw new RuntimeException('Entrada insegura aceita: ' . $label);
}
$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Monetizze decisions: integração MySQL não executada (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=cupons_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Somente banco descartável cupons_test é permitido.');
}
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $opts);
$other = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), $opts);
foreach (['20261025_monetizze_review_candidates.sql', '20261025_monetizze_review_decisions.sql'] as $file) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/' . $file));
}
$account = bin2hex(random_bytes(16));
$foreign = bin2hex(random_bytes(16));
$key = str_repeat('T', 48); // synthetic test-only secret
$operator = 'synthetic-admin-01';
$accountHash = hash_hmac('sha256', 'monetizze:account:' . $account, $key);
$foreignHash = hash_hmac('sha256', 'monetizze:account:' . $foreign, $key);
$allow = static fn(string $a, string $o): bool => $a === $account && $o === $operator;
$deny = static fn(string $a, string $o): bool => false;
$insert = $pdo->prepare('INSERT INTO monetizze_review_candidates
    (account_hash, event_hash, candidate_status, candidate_event,
     original_status, original_event, risk_direction) VALUES (?, ?, ?, ?, ?, ?, ?)');
try {
    $ids = [];
    foreach ([$accountHash, $foreignHash] as $hash) {
        $insert->execute([$hash, hash_hmac('sha256', 'monetizze:event:' . bin2hex(random_bytes(32)), $key),
            3, 9, 2, 2, 'escalation']);
        $ids[] = (int) $pdo->lastInsertId();
    }
    [$own, $notOwn] = $ids;
    $req = bin2hex(random_bytes(16));
    $record = static fn(PDO $db, int $id, string $r, string $action, Closure $auth) =>
        MonetizzeReviewDecisionJournal::record($db, $account, $key, $operator, $auth, $id, $r, $action);
    decisionReject(static fn() => $record($pdo, $own, $req, 'escalate', $deny), 'sem autorização');
    decisionReject(static fn() => $record($pdo, $own, $req, 'approve_payment', $allow), 'aprovação financeira');
    decisionReject(static fn() => $record($pdo, $notOwn, $req, 'escalate', $allow), 'outra conta');
    decisionReject(static fn() => $record($pdo, $own, 'bad', 'escalate', $allow), 'request ID');
    decisionReject(static fn() => $record($pdo, $own, $req, 'escalate',
        static fn() => throw new RuntimeException('SYNTHETIC-PRIVATE-BUYER')), 'erro de autorizador');
    $one = $record($pdo, $own, $req, 'request_evidence', $allow);
    decisionCheck($one['recorded'] === true && $one['decision_id'] > 0
        && $one['authenticated'] === false && $one['financial_ready'] === false, 'registro não financeiro');
    $again = $record($other, $own, strtoupper($req), 'request_evidence', $allow);
    decisionCheck($again['recorded'] === false && $again['decision_id'] === $one['decision_id'],
        'idempotência entre conexões');
    decisionReject(static fn() => $record($other, $own, $req, 'escalate', $allow),
        'reuso divergente de solicitação');
    $two = $record($other, $own, bin2hex(random_bytes(16)), 'escalate', $allow);
    decisionCheck($two['recorded'] === true && $two['decision_id'] > $one['decision_id'],
        'histórico append-only');
    $first = MonetizzeReviewDecisionJournal::historyForCandidate(
        $pdo, $account, $key, $operator, $allow, $own, 0, 1);
    $second = MonetizzeReviewDecisionJournal::historyForCandidate(
        $pdo, $account, $key, $operator, $allow, $own, $first['next_after_id'], 1);
    decisionCheck(count($first['items']) === 1 && $first['has_more'] === true
        && $first['read_only'] === true && $first['financial_ready'] === false
        && count($second['items']) === 1 && $second['has_more'] === false
        && $second['items'][0]['decision_id'] === $two['decision_id'], 'paginação');
    decisionCheck(array_keys($first['items'][0]) === ['decision_id', 'action', 'recorded_at'],
        'minimização de retorno');
    decisionReject(static fn() => MonetizzeReviewDecisionJournal::historyForCandidate(
        $pdo, $account, $key, $operator, $deny, $own), 'leitura não autorizada');
    decisionReject(static fn() => MonetizzeReviewDecisionJournal::historyForCandidate(
        $pdo, $account, $key, $operator, $allow, $notOwn), 'leitura cruzada');
    decisionReject(static fn() => MonetizzeReviewDecisionJournal::historyForCandidate(
        $pdo, $account, $key, $operator, $allow, $own, -1), 'cursor inválido');
    $query = $pdo->prepare('SELECT account_hash, operator_hash, request_hash, action
        FROM monetizze_review_decisions WHERE account_hash = ?');
    $query->execute([$accountHash]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    decisionCheck(count($rows) === 2, 'duas ações auditadas');
    $json = json_encode([$rows, $first, $second], JSON_THROW_ON_ERROR);
    decisionCheck(!str_contains($json, $account) && !str_contains($json, $foreign)
        && !str_contains($json, $operator) && !str_contains($json, $req), 'identificadores em HMAC');
    $check = $pdo->prepare('SELECT candidate_status FROM monetizze_review_candidates WHERE id = ?');
    $check->execute([$own]);
    decisionCheck((int) $check->fetchColumn() === 3, 'candidato preservado');
    decisionCheck(!$pdo->inTransaction(), 'transação encerrada');
} finally {
    // Only random synthetic rows in explicitly disposable cupons_test.
    $pdo->prepare('DELETE FROM monetizze_review_decisions WHERE account_hash IN (?, ?)')
        ->execute([$accountHash, $foreignHash]);
    $pdo->prepare('DELETE FROM monetizze_review_candidates WHERE account_hash IN (?, ?)')
        ->execute([$accountHash, $foreignHash]);
}
echo "Monetizze decisions: autorização, idempotência, auditoria, isolamento e minimização aprovados.\n";
