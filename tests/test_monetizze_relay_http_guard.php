<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_relay_http_guard.php';

function httpGuardAssert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('Relay HTTP: ' . $label);
}
function httpGuardReject(Closure $call, string $label): void
{
    try { $call(); } catch (InvalidArgumentException|RuntimeException $exception) {
        httpGuardAssert(!str_contains($exception->getMessage(), 'PRIVATE-BUYER'), $label . ' sem PII');
        return;
    }
    throw new RuntimeException('Relay HTTP aceitou ' . $label);
}

$account = str_repeat('a', 32);
$secret = str_repeat('s', 48);
$nonce = str_repeat('b', 32);
$now = 1792920000;
$timestamp = (string) $now;
$body = json_encode([[
    'id' => str_repeat('c', 64), 'chave_unica' => $account,
    'codigo_status' => '2', 'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER'],
]], JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha256', "monetizze-relay-v1\n$timestamp\n$nonce\n$body", $secret);
$server = [
    'REQUEST_METHOD' => 'POST',
    'CONTENT_TYPE' => 'application/json; charset=utf-8',
    'CONTENT_LENGTH' => (string) strlen($body),
    'HTTP_X_MONETIZZE_RELAY_TIMESTAMP' => $timestamp,
    'HTTP_X_MONETIZZE_RELAY_NONCE' => $nonce,
    'HTTP_X_MONETIZZE_RELAY_SIGNATURE' => $signature,
];
$claimed = [];
$claim = static function (string $id, int $expiry) use (&$claimed): bool {
    if (isset($claimed[$id])) return false;
    $claimed[$id] = $expiry;
    return true;
};
$run = static fn(array $headers, string $payload, bool $upstream = true, bool $enabled = true): array =>
    MonetizzeRelayHttpGuard::verifyAndPreview($headers, $payload, $secret, $account, $claim, $upstream, $enabled, $now);

httpGuardReject(static fn() => $run($server, $body, false), 'origem upstream não comprovada');
httpGuardReject(static fn() => $run($server, $body, true, false), 'rota desabilitada');
httpGuardAssert(count($claimed) === 0, 'flags de bloqueio não reservam nonce');
foreach (['GET', 'PUT', 'HEAD'] as $method) {
    httpGuardReject(static fn() => $run(array_replace($server, ['REQUEST_METHOD' => $method]), $body), 'método ' . $method);
}
foreach (['text/plain', 'application/json; charset=iso-8859-1', 'application/json; foo=bar'] as $type) {
    httpGuardReject(static fn() => $run(array_replace($server, ['CONTENT_TYPE' => $type]), $body), 'content-type ' . $type);
}
httpGuardReject(static fn() => $run(array_replace($server, ['CONTENT_LENGTH' => '01']), $body), 'content-length não canônico');
httpGuardReject(static fn() => $run(array_replace($server, ['CONTENT_LENGTH' => '1']), $body), 'content-length divergente');
httpGuardReject(static fn() => $run(array_replace($server, ['HTTP_CONTENT_ENCODING' => 'gzip']), $body), 'compressão');
httpGuardReject(static fn() => $run(array_replace($server, ['HTTP_TRANSFER_ENCODING' => 'chunked']), $body), 'transfer encoding ambíguo');
httpGuardReject(static fn() => $run(array_replace($server, ['HTTP_X_MONETIZZE_RELAY_SIGNATURE' => '0' . substr($signature, 1)]), $body), 'assinatura adulterada');
httpGuardReject(static fn() => $run(array_replace($server, ['HTTP_X_MONETIZZE_RELAY_NONCE' => [$nonce]]), $body), 'header não escalar');
httpGuardReject(static fn() => $run($server, str_repeat('x', 65537)), 'payload excessivo');
httpGuardAssert(count($claimed) === 0, 'nenhuma entrada inválida reserva nonce');
$result = $run($server, $body);
httpGuardAssert($result['risk'] === 1 && $result['relay_authenticated'] === true, 'HMAC e classificação');
httpGuardAssert($result['origin_verified'] === false && $result['authenticated'] === false && $result['financial_ready'] === false, 'sem autenticação nativa nem autorização financeira');
httpGuardAssert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'PRIVATE-BUYER'), 'sem PII');
httpGuardAssert(count($claimed) === 1, 'nonce reservado');
httpGuardReject(static fn() => $run($server, $body), 'replay');
echo "Monetizze: guard HTTP fail-closed, HMAC, replay, limites e privacidade aprovados.\n";
