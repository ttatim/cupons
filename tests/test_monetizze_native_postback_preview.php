<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_native_postback_preview.php';

function nativeAssert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('Postback nativo: ' . $label);
}
function nativeReject(Closure $call, string $label): void
{
    try { $call(); } catch (InvalidArgumentException $exception) {
        nativeAssert(!str_contains($exception->getMessage(), 'PRIVATE-BUYER'), $label . ' sem PII');
        return;
    }
    throw new RuntimeException('Postback aceitou ' . $label);
}
$account = str_repeat('a', 32);
$event = [
    'id' => str_repeat('b', 64), 'chave_unica' => $account,
    'codigo_status' => '2', 'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER', 'email' => 'private@example.test'],
];
$json = json_encode($event, JSON_THROW_ON_ERROR);
$run = static fn(string $body, string $type): array => MonetizzeNativePostbackPreview::preview($body, $type, $account);
$result = $run($json, 'application/json; charset=utf-8');
nativeAssert($result['risk'] === 1 && $result['unique'] === 1, 'reembolso mesmo com status 2');
nativeAssert($result['origin_verified'] === false && $result['authenticated'] === false && $result['financial_ready'] === false, 'não autentica origem');
nativeAssert(!str_contains(json_encode($result), 'PRIVATE-BUYER'), 'não retorna comprador');
$form = http_build_query($event, '', '&', PHP_QUERY_RFC1738);
$fromForm = $run($form, 'application/x-www-form-urlencoded');
nativeAssert($fromForm === $result, 'formulário e JSON equivalentes');
nativeAssert($run($json, 'APPLICATION/JSON; CHARSET="UTF-8"') === $result, 'charset UTF-8 JSON entre aspas');
nativeAssert($run($form, 'application/x-www-form-urlencoded; charset=UTF-8') === $result, 'charset UTF-8 formulário');
foreach ([
    'application/json; charset=iso-8859-1',
    'application/json; charset=utf-8; foo=bar',
    'application/json; foo=bar',
    'application/x-www-form-urlencoded; charset=latin1',
    'application/x-www-form-urlencoded; boundary=x',
    "application/json\r\nX-Injected: yes",
    str_repeat('a', 129),
] as $invalidType) {
    nativeReject(static fn() => $run($json, $invalidType), 'content-type ambíguo ou inseguro');
}
$other = $event;
$other['chave_unica'] = str_repeat('c', 32);
nativeAssert($run(json_encode($other, JSON_THROW_ON_ERROR), 'application/json')['other_account'] === 1, 'outra conta não é aceita');
foreach (['application/xml', 'text/plain', 'application/jsonp'] as $type) {
    nativeReject(static fn() => $run($json, $type), 'tipo ' . $type);
}
nativeReject(static fn() => $run('[]', 'application/json'), 'lista JSON');
nativeReject(static fn() => $run('{', 'application/json'), 'JSON inválido');
nativeReject(static fn() => $run(str_repeat('x', 65537), 'application/json'), 'tamanho excessivo');
nativeReject(static fn() => $run($form . '&id=' . str_repeat('c', 64), 'application/x-www-form-urlencoded'), 'id duplicado');
nativeReject(static fn() => $run($form . '&codigo_status=4', 'application/x-www-form-urlencoded'), 'status duplicado');
nativeReject(static fn() => $run($form . '&unexpected=%ZZ', 'application/x-www-form-urlencoded'), 'percent inválido');
nativeReject(static fn() => $run($form . '&x', 'application/x-www-form-urlencoded'), 'segmento inválido');
nativeReject(static fn() => $run(implode('&', array_fill(0, 201, 'x=1')), 'application/x-www-form-urlencoded'), 'campos excessivos');
$missing = $event;
unset($missing['id']);
nativeReject(static fn() => $run(json_encode($missing, JSON_THROW_ON_ERROR), 'application/json'), 'id ausente');
$subscription = $event;
$subscription['postback_evento'] = '101';
nativeReject(static fn() => $run(json_encode($subscription, JSON_THROW_ON_ERROR), 'application/json'), 'assinatura fora do escopo');
$invalid = $event;
$invalid['codigo_status'] = '02';
nativeReject(static fn() => $run(json_encode($invalid, JSON_THROW_ON_ERROR), 'application/json'), 'status não canônico');
echo "Monetizze: formatos nativos de vendas, limites, privacidade e origem não comprovada aprovados.\n";
