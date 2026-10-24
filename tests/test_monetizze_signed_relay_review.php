<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_signed_relay_review.php';

function relayAssert(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('Relay: ' . $label);
    }
}
function relayReject(Closure $operation, string $label): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $exception) {
        relayAssert(!str_contains($exception->getMessage(), 'PRIVATE-BUYER'), $label . ' sem PII');
        return;
    }
    throw new RuntimeException('Relay aceitou ' . $label);
}

$account = str_repeat('a', 32);
$secret = str_repeat('s', 40); // Synthetic secret, never a real credential.
$now = 1792872000;
$timestamp = (string) $now;
$nonce = str_repeat('b', 32);
$event = [
    'id' => str_repeat('c', 64), 'chave_unica' => $account,
    'codigo_status' => '2', 'postback_evento' => '9',
    'comprador' => ['nome' => 'PRIVATE-BUYER'],
];
$body = json_encode([$event], JSON_THROW_ON_ERROR);
$sign = static fn(string $payload, string $ts, string $n, string $key): string =>
    hash_hmac('sha256', "monetizze-relay-v1\n" . $ts . "\n" . $n . "\n" . $payload, $key);
$signature = $sign($body, $timestamp, $nonce, $secret);
$claimed = [];
$claim = static function (string $n, int $expiresAt) use (&$claimed, $now): bool {
    relayAssert($expiresAt === $now + 300, 'prazo do nonce');
    if (isset($claimed[$n])) {
        return false;
    }
    $claimed[$n] = true;
    return true;
};
$run = static fn(string $payload, string $ts, string $n, string $sig, string $key, Closure $guard): array =>
    MonetizzeSignedRelayReview::verifyAndPreview($payload, $ts, $n, $sig, $key, $account, $guard, $now);
$result = $run($body, $timestamp, $nonce, $signature, $secret, $claim);
relayAssert($result['relay_authenticated'] === true, 'assinatura do relay validada');
relayAssert($result['authenticated'] === false && $result['financial_ready'] === false, 'sem autenticação nativa nem uso financeiro');
relayAssert($result['risk'] === 1 && $result['unique'] === 1, 'classificação agregada');
relayAssert(!str_contains(json_encode($result), 'PRIVATE-BUYER'), 'sem dados pessoais na saída');
relayReject(static fn() => $run($body, $timestamp, $nonce, $signature, $secret, $claim), 'repetição');
relayAssert(count($claimed) === 1, 'repetição não criou claim');

$otherNonce = str_repeat('d', 32);
$rejectWithoutClaim = static function (string $payload, string $ts, string $n, string $sig, string $key) use ($run, $claim): void {
    $run($payload, $ts, $n, $sig, $key, $claim);
};
relayReject(static fn() => $rejectWithoutClaim($body, $timestamp, $otherNonce, $signature, $secret), 'nonce adulterado');
relayReject(static fn() => $rejectWithoutClaim($body . ' ', $timestamp, $nonce, $signature, $secret), 'corpo adulterado');
relayReject(static fn() => $rejectWithoutClaim($body, $timestamp, $nonce, $signature, str_repeat('z', 40)), 'segredo incorreto');
relayReject(static fn() => $rejectWithoutClaim($body, (string) ($now - 301), $nonce, $signature, $secret), 'timestamp expirado');
relayReject(static fn() => $rejectWithoutClaim($body, (string) ($now + 301), $nonce, $signature, $secret), 'timestamp futuro');
relayReject(static fn() => $rejectWithoutClaim($body, $timestamp, 'not-hex', $signature, $secret), 'nonce inválido');
relayReject(static fn() => $rejectWithoutClaim($body, $timestamp, $nonce, 'invalid', $secret), 'assinatura inválida');
relayReject(static fn() => $rejectWithoutClaim($body, $timestamp, $nonce, $signature, 'short'), 'segredo fraco');
relayReject(static fn() => $rejectWithoutClaim(str_repeat('X', 65537), $timestamp, $nonce, $signature, $secret), 'corpo excessivo');
relayAssert(count($claimed) === 1, 'envelopes inválidos não consomem nonce');

$badJson = '{';
relayReject(static fn() => $rejectWithoutClaim($badJson, $timestamp, $otherNonce, $sign($badJson, $timestamp, $otherNonce, $secret), $secret), 'JSON inválido');
$badBatch = json_encode([['comprador' => 'PRIVATE-BUYER']], JSON_THROW_ON_ERROR);
relayReject(static fn() => $rejectWithoutClaim($badBatch, $timestamp, $otherNonce, $sign($badBatch, $timestamp, $otherNonce, $secret), $secret), 'evento inválido');
relayAssert(count($claimed) === 1, 'payload inválido não consome nonce');
relayReject(static fn() => $run($body, $timestamp, $otherNonce, $sign($body, $timestamp, $otherNonce, $secret), $secret, static fn(string $n, int $e): bool => false), 'replay guard negou');
relayReject(static fn() => $run($body, $timestamp, $otherNonce, $sign($body, $timestamp, $otherNonce, $secret), $secret, static function (): bool { throw new RuntimeException('PRIVATE-BUYER'); }), 'replay guard indisponível');

echo "Monetizze: assinatura de relay, janela temporal, anti-replay e privacidade aprovados.\n";
