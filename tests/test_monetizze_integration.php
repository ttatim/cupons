<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/monetizze_integration.php';

function assertMonetizze(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $message);
    }
}
putenv('MONETIZZE_CONSUMER_KEY');
assertMonetizze(!MonetizzeIntegration::credentialsPresent(), 'sem chave');
try {
    (new MonetizzeIntegration())->checkConnection();
    throw new RuntimeException('Chave ausente foi aceita');
} catch (RuntimeException $e) {
    assertMonetizze(str_contains($e->getMessage(), 'Configure'), 'mensagem sem chave');
}
putenv('MONETIZZE_CONSUMER_KEY=test-consumer-key-12345');
$calls = [];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = compact('method', 'url', 'headers', 'body');
    return ['status' => 200, 'body' => '{"TOKEN":"temporary-token"}'];
};
$client = new MonetizzeIntegration(Closure::fromCallable($transport));
assertMonetizze($client->checkConnection(), 'conexão autenticada');
assertMonetizze(count($calls) === 1, 'uma chamada');
assertMonetizze($calls[0]['method'] === 'GET', 'GET');
assertMonetizze($calls[0]['url'] === 'https://api.monetizze.com.br/2.1/token', 'endpoint oficial fixo');
assertMonetizze(in_array('X_CONSUMER_KEY: test-consumer-key-12345', $calls[0]['headers'], true), 'cabeçalho de chave');
assertMonetizze($calls[0]['body'] === null, 'sem corpo');
assertMonetizze((new MonetizzeIntegration(static fn () => ['status' => 200, 'body' => '{"token":"lowercase-token"}']))->checkConnection(), 'token minúsculo');
foreach ([
    ['status' => 401, 'body' => '{"secret":"DO-NOT-LEAK"}'],
    ['status' => 200, 'body' => '{"message":"DO-NOT-LEAK"}'],
    ['status' => 200, 'body' => '{"TOKEN":"bad\\nheader"}'],
    ['status' => 200, 'body' => '{invalid'],
    ['status' => 200, 'body' => str_repeat('A', 1048577)],
] as $response) {
    try {
        (new MonetizzeIntegration(static fn () => $response))->checkConnection();
        throw new RuntimeException('Resposta inválida foi aceita');
    } catch (RuntimeException $e) {
        assertMonetizze(!str_contains($e->getMessage(), 'DO-NOT-LEAK'), 'não vaza resposta');
        assertMonetizze(!str_contains($e->getMessage(), 'test-consumer-key-12345'), 'não vaza chave');
        assertMonetizze(!str_contains($e->getMessage(), 'Resposta inválida foi aceita'), 'rejeita resposta inválida');
    }
}
putenv("MONETIZZE_CONSUMER_KEY=bad\r\nX-Test: injected");
try {
    (new MonetizzeIntegration(static fn () => ['status' => 200, 'body' => '{"TOKEN":"x"}']))->checkConnection();
    throw new RuntimeException('Aceitou injeção de header');
} catch (RuntimeException $e) {
    assertMonetizze(str_contains($e->getMessage(), 'inválida'), 'bloqueia CRLF');
}
echo "Monetizze: verificação de autenticação simulada aprovada.\n";
