<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_native_postback_preview.php';

function ambiguityAssert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Postback ambíguo: ' . $label);
    }
}

function ambiguityReject(string $raw, string $account, string $label): void
{
    try {
        MonetizzeNativePostbackPreview::preview($raw, 'application/json', $account);
    } catch (InvalidArgumentException $exception) {
        ambiguityAssert(!str_contains($exception->getMessage(), 'PRIVATE-BUYER'), $label . ' sem dados pessoais');
        return;
    }
    throw new RuntimeException('Postback aceitou ' . $label);
}

$account = str_repeat('a', 32);
$event = [
    'id' => str_repeat('b', 64),
    'chave_unica' => $account,
    'codigo_status' => '2',
    'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER', 'obs' => 'texto com }, vírgula, e \"aspas\"'],
];
$raw = json_encode($event, JSON_THROW_ON_ERROR);
$preview = MonetizzeNativePostbackPreview::preview($raw, 'application/json', $account);
ambiguityAssert($preview['risk'] === 1 && $preview['origin_verified'] === false, 'JSON aninhado e origem não autenticada');
ambiguityAssert(!str_contains(json_encode($preview, JSON_THROW_ON_ERROR), 'PRIVATE-BUYER'), 'privacidade');

foreach (['id', 'chave_unica', 'codigo_status', 'postback_evento'] as $field) {
    ambiguityReject(substr($raw, 0, -1) . ',"' . $field . '":"injected"}', $account, 'campo mínimo duplicado ' . $field);
}
ambiguityReject(substr($raw, 0, -1) . ',"\u0069d":"injected"}', $account, 'id duplicado com escape Unicode');
ambiguityReject(substr($raw, 0, -1) . ',"outro":1,"outro":2}', $account, 'campo extra duplicado');

$atLimit = $event;
for ($i = 0; $i < 195; $i++) {
    $atLimit['extra_' . $i] = $i;
}
ambiguityAssert(
    MonetizzeNativePostbackPreview::preview(json_encode($atLimit, JSON_THROW_ON_ERROR), 'application/json', $account)['risk'] === 1,
    'limite de 200 campos permitido'
);
$atLimit['extra_195'] = 195;
ambiguityReject(json_encode($atLimit, JSON_THROW_ON_ERROR), $account, '201 campos JSON');
ambiguityAssert(
    MonetizzeNativePostbackPreview::preview(" \n " . $raw . " \t ", 'application/json', $account)['risk'] === 1,
    'espaços JSON válidos'
);

echo "Monetizze: chaves JSON duplicadas, escapes, limites e privacidade aprovados.\n";
