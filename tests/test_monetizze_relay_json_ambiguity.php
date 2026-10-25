<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_signed_relay_review.php';

function ambiguityAssert(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('Relay JSON: ' . $label);
    }
}

$account = str_repeat('a', 32);
$secret = str_repeat('s', 48); // Synthetic only.
$now = 1792920000;
$timestamp = (string) $now;
$claimed = [];
$claim = static function (string $nonce, int $expires) use (&$claimed): bool {
    if (isset($claimed[$nonce])) return false;
    $claimed[$nonce] = $expires;
    return true;
};
$run = static function (string $json, string $nonce) use ($account, $secret, $now, $timestamp, $claim): array {
    $signature = hash_hmac('sha256', "monetizze-relay-v1\n$timestamp\n$nonce\n$json", $secret);
    return MonetizzeSignedRelayReview::verifyAndPreview(
        $json, $timestamp, $nonce, $signature, $secret, $account, $claim, $now
    );
};
$reject = static function (string $json, string $label) use ($run, &$claimed): void {
    $nonce = bin2hex(random_bytes(16));
    $before = count($claimed);
    try {
        $run($json, $nonce);
    } catch (InvalidArgumentException $exception) {
        ambiguityAssert(!str_contains($exception->getMessage(), 'PRIVATE-BUYER'), $label . ': privacidade');
        ambiguityAssert(count($claimed) === $before, $label . ': nonce não reservado');
        return;
    }
    throw new RuntimeException('Relay JSON aceitou ' . $label);
};
$event = [
    'id' => str_repeat('c', 64), 'chave_unica' => $account,
    'codigo_status' => '2', 'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER'],
];
$valid = json_encode([$event], JSON_THROW_ON_ERROR);
$reject(str_replace('"id":', '"id":"' . str_repeat('d', 64) . '","id":', $valid), 'id duplicado');
$reject(str_replace('"id":', '"\\u0069d":"' . str_repeat('d', 64) . '","id":', $valid), 'id Unicode equivalente');
$reject(str_replace('"chave_unica":', '"chave_unica":"' . str_repeat('b', 32) . '","chave_unica":', $valid), 'conta duplicada');
$reject(str_replace('"codigo_status":', '"codigo_status":"6","codigo_status":', $valid), 'status duplicado');
$reject(str_replace('"postback_evento":', '"postback_evento":"2","postback_evento":', $valid), 'tipo duplicado');
$reject(str_replace('"nome":"PRIVATE-BUYER"', '"nome":"PRIVATE-BUYER","nome":"PRIVATE-BUYER"', $valid), 'chave aninhada duplicada');
$reject(str_replace('"nome":"PRIVATE-BUYER"', '"\\u006eome":"PRIVATE-BUYER","nome":"PRIVATE-BUYER"', $valid), 'chave aninhada Unicode');
$reject(str_replace('"comprador":{"nome":"PRIVATE-BUYER"}', '"comprador":{"historico":[{"tipo":1,"tipo":2}]}', $valid), 'objeto em array aninhado');
ambiguityAssert(count($claimed) === 0, 'nenhum JSON ambíguo consumiu nonce');

$ok = $run($valid, bin2hex(random_bytes(16)));
ambiguityAssert($ok['risk'] === 1 && $ok['relay_authenticated'] === true, 'evento legítimo preservado');
ambiguityAssert($ok['authenticated'] === false && $ok['financial_ready'] === false, 'sem autorização financeira');
$event['id'] = str_repeat('e', 64);
$two = $run(json_encode([json_decode($valid, true, 16, JSON_THROW_ON_ERROR)[0], $event], JSON_THROW_ON_ERROR), bin2hex(random_bytes(16)));
ambiguityAssert($two['unique'] === 2, 'mesmas chaves em objetos distintos são válidas');
ambiguityAssert(count($claimed) === 2, 'somente dois envelopes válidos reservam nonce');
echo "Monetizze: JSON do relay rejeita chaves duplicadas e preserva anti-replay.\n";
