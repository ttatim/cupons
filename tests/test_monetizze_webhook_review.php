<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_webhook_review.php';

function verifyMonetizzeReview(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Teste Monetizze falhou: ' . $label);
    }
}
function eventForTest(string $id, string $account, string $status, string $code): array
{
    return [
        'id' => $id, 'chave_unica' => $account,
        'codigo_status' => $status, 'postback_evento' => $code,
        'comprador' => ['nome' => 'DADO-PESSOAL-NAO-RETORNAR', 'email' => 'secret@example.test'],
        'venda' => ['valor' => '999999.00'],
    ];
}
$key = str_repeat('a', 32);
$events = [
    eventForTest(str_repeat('1', 64), $key, '2', '2'),
    eventForTest(str_repeat('1', 64), $key, '2', '2'),
    eventForTest(str_repeat('2', 64), $key, '2', '9'), // solicitação de reembolso, apesar do status 2
    eventForTest(str_repeat('3', 64), $key, '4', '2'), // devolução
    eventForTest(str_repeat('4', 64), $key, '6', '88'), // evento desconhecido
    eventForTest(str_repeat('5', 64), str_repeat('b', 32), '2', '2'),
];
$summary = MonetizzeWebhookReview::preview($events, $key);
$expected = [
    'received' => 6, 'unique' => 4, 'duplicates' => 1, 'other_account' => 1,
    'positive' => 1, 'risk' => 2, 'needs_review' => 1,
    'financial_ready' => false, 'authenticated' => false,
];
foreach ($expected as $field => $value) {
    verifyMonetizzeReview(array_key_exists($field, $summary) && $summary[$field] === $value, 'campo agregado ' . $field . ': esperado ' . var_export($value, true) . ', recebido ' . var_export($summary[$field] ?? null, true));
}
verifyMonetizzeReview(count($summary) === count($expected), 'nenhum campo adicional');
verifyMonetizzeReview(!str_contains(json_encode($summary), 'DADO-PESSOAL'), 'sem dados pessoais');
verifyMonetizzeReview(!str_contains(json_encode($summary), '999999'), 'sem valores');
verifyMonetizzeReview(!str_contains(json_encode($summary), $key), 'sem chave da conta');

$invalid = [
    [[], $key],
    [array_fill(0, 51, $events[0]), $key],
    [[$events[0]], 'invalid-key'],
    [[array_merge($events[0], ['id' => 'x'])], $key],
    [[array_merge($events[0], ['chave_unica' => 'x'])], $key],
    [[array_merge($events[0], ['codigo_status' => "2\r\n"])], $key],
    [[array_merge($events[0], ['postback_evento' => ['2']])], $key],
    [[[...$events[0], 'id' => str_repeat('1', 64), 'codigo_status' => null]], $key],
    [[...$events[0], array_merge($events[0], ['codigo_status' => '4'])], $key],
    [[...$events[0], array_merge($events[0], ['postback_evento' => '9'])], $key],
    [[array_merge($events[0], ['codigo_status' => '02'])], $key],
    [[array_merge($events[0], ['postback_evento' => '09'])], $key],
];
foreach ($invalid as [$batch, $expected]) {
    try {
        MonetizzeWebhookReview::preview($batch, $expected);
        throw new RuntimeException('Entrada inválida foi aceita');
    } catch (InvalidArgumentException $e) {
        verifyMonetizzeReview(!str_contains($e->getMessage(), 'secret@example.test'), 'erro sem PII');
    }
}
verifyMonetizzeReview(MonetizzeWebhookReview::preview([eventForTest(str_repeat('f', 64), strtoupper($key), '6', '2')], $key)['positive'] === 1, 'conta hex case-insensitive');
echo "Monetizze: prévia de webhook sem PII e sem efeitos financeiros aprovada.\n";
