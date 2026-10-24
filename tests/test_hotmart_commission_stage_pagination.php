<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hotmart_commission_stage_pagination.php';

function assertPage(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $message);
    }
}
function rejectsPage(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException | RuntimeException $expected) {
        return;
    }
    throw new RuntimeException('Aceitou entrada inválida: ' . $message);
}

$account = 'A1B2C3D4-1234-5678-9ABC-ABCDEF012345';
$otherAccount = 'B1B2C3D4-1234-5678-9ABC-ABCDEF012345';
$key = str_repeat('K', 48);
$session = [];
rejectsPage(fn() => HotmartCommissionStagePagination::nextRequest($session, $account, $key), 'sem consulta anterior');
$first = HotmartCommissionStagePagination::record($session, $account, $key, 'REFUNDED', 'CURSOR1');
assertPage($first === ['page' => 1, 'status' => 'REFUNDED', 'has_next' => true], 'primeira página');
assertPage(HotmartCommissionStagePagination::nextRequest($session, $account, $key) === [
    'status' => 'REFUNDED', 'cursor' => 'CURSOR1', 'page' => 2,
], 'status e cursor exclusivamente da sessão');
rejectsPage(fn() => HotmartCommissionStagePagination::nextRequest($session, $otherAccount, $key), 'outra conta');
rejectsPage(fn() => HotmartCommissionStagePagination::nextRequest($session, $account, str_repeat('Q', 48)), 'outra chave');
rejectsPage(fn() => HotmartCommissionStagePagination::record(
    $session, $account, $key, 'APPROVED', 'CURSOR2', true, 'CURSOR1'
), 'status alterado');
rejectsPage(fn() => HotmartCommissionStagePagination::record(
    $session, $account, $key, 'REFUNDED', 'CURSOR2', true, 'CURSOR_FALSO'
), 'cursor alterado');
assertPage(HotmartCommissionStagePagination::progress($session, $account, $key) === $first, 'estado não muda em falha');
assertPage(HotmartCommissionStagePagination::progress($session, $otherAccount, $key) === null, 'progresso de outra conta oculto');
$second = HotmartCommissionStagePagination::record($session, $account, $key, 'REFUNDED', 'CURSOR2', true, 'CURSOR1');
assertPage($second['page'] === 2 && $second['has_next'], 'segunda página');
$repeat = HotmartCommissionStagePagination::record($session, $account, $key, 'REFUNDED', 'CURSOR1', true, 'CURSOR2');
assertPage($repeat === ['page' => 3, 'status' => 'REFUNDED', 'has_next' => false], 'ciclo de cursor bloqueado');
rejectsPage(fn() => HotmartCommissionStagePagination::nextRequest($session, $account, $key), 'fim do cursor');
rejectsPage(fn() => HotmartCommissionStagePagination::record(
    $session, $account, $key, 'APPROVED', "bad\nnext"
), 'cursor com quebra de linha');
rejectsPage(fn() => HotmartCommissionStagePagination::record(
    $session, $account, $key, 'APPROVED', 'a&b'
), 'cursor com separador');
rejectsPage(fn() => HotmartCommissionStagePagination::record(
    $session, $account, $key, 'INVALID', null
), 'status desconhecido');

$reset = HotmartCommissionStagePagination::record($session, $account, $key, 'APPROVED', null);
assertPage($reset === ['page' => 1, 'status' => 'APPROVED', 'has_next' => false], 'nova consulta limpa paginação');
assertPage(!str_contains(json_encode($reset, JSON_THROW_ON_ERROR), 'CURSOR'), 'cursor nunca exposto no retorno');

$session = [];
HotmartCommissionStagePagination::record($session, $account, $key, 'COMPLETE', 'PAGE2');
for ($page = 2; $page <= 50; $page++) {
    $request = HotmartCommissionStagePagination::nextRequest($session, $account, $key);
    assertPage($request['page'] === $page && $request['status'] === 'COMPLETE', 'avanço manual limitado');
    $progress = HotmartCommissionStagePagination::record(
        $session, $account, $key, 'COMPLETE', 'PAGE' . ($page + 1), true, $request['cursor']
    );
}
assertPage($progress === ['page' => 50, 'status' => 'COMPLETE', 'has_next' => false], 'limite 50 páginas');
rejectsPage(fn() => HotmartCommissionStagePagination::nextRequest($session, $account, $key), 'página 51 proibida');
echo "Hotmart staging: paginação por status/conta, cursores seguros, ciclo e limite de 50 páginas aprovados.\n";
