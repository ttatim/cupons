<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/kiwify_integration.php';

function checkKiwify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $message);
    }
}
foreach (['KIWIFY_CLIENT_ID', 'KIWIFY_CLIENT_SECRET', 'KIWIFY_ACCOUNT_ID'] as $key) {
    putenv($key);
}
checkKiwify(!KiwifyIntegration::credentialsPresent(), 'credenciais ausentes');
try {
    (new KiwifyIntegration())->listProducts();
    throw new RuntimeException('Aceitou credenciais ausentes');
} catch (RuntimeException $e) {
    checkKiwify(str_contains($e->getMessage(), 'Configure'), 'mensagem de configuração');
}
putenv('KIWIFY_CLIENT_ID=test-client');
putenv('KIWIFY_CLIENT_SECRET=test-secret');
putenv('KIWIFY_ACCOUNT_ID=test-account');
$requests = [];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
    $requests[] = compact('method', 'url', 'headers', 'body');
    if (count($requests) === 1) {
        return ['status' => 200, 'body' => '{"access_token":"test-token","expires_in":86400}'];
    }
    return ['status' => 200, 'body' => '{"data":[{"id":"p1","name":"Produto de teste"}],"pagination":{"page_number":1,"page_size":10}}'];
};
$client = new KiwifyIntegration(Closure::fromCallable($transport));
$result = $client->listProducts();
checkKiwify(count($result['data']) === 1, 'retorno de produtos');
checkKiwify(count($requests) === 2, 'OAuth seguido de listagem');
checkKiwify($requests[0]['method'] === 'POST' && $requests[0]['url'] === 'https://public-api.kiwify.com/v1/oauth/token', 'OAuth oficial');
checkKiwify($requests[0]['body'] === 'client_id=test-client&client_secret=test-secret', 'credenciais no corpo do POST');
checkKiwify(in_array('Authorization: Bearer test-token', $requests[1]['headers'], true), 'bearer token');
checkKiwify(in_array('x-kiwify-account-id: test-account', $requests[1]['headers'], true), 'account id');
checkKiwify(str_contains($requests[1]['url'], '/products?page_number=1&page_size=10'), 'paginação');
$client->listProducts(2, 5);
checkKiwify(count($requests) === 3, 'reutiliza token durante execução');
try {
    $client->listProducts(1, 1000);
    throw new RuntimeException('Aceitou paginação inválida');
} catch (InvalidArgumentException $e) {
    checkKiwify(str_contains($e->getMessage(), 'Paginação'), 'limite de paginação');
}
$failure = new KiwifyIntegration(static fn () => ['status' => 401, 'body' => '{"token":"SECRET-NOT-FOR-LOG"}']);
try {
    $failure->listProducts();
    throw new RuntimeException('Aceitou HTTP 401');
} catch (RuntimeException $e) {
    checkKiwify(str_contains($e->getMessage(), '401') && !str_contains($e->getMessage(), 'SECRET-NOT-FOR-LOG'), 'erro seguro');
}
// Um Bearer malformado nunca deve ser usado numa segunda requisição HTTP.
$invalidTokens = [
    'espaco' => 'token with space',
    'tab' => "token\twith-tab",
    'crlf' => "token\r\nX-Test: injected",
    'del' => "token\x7f",
    'vazio' => '',
    'extenso' => str_repeat('a', 8193),
];
foreach ($invalidTokens as $label => $badToken) {
    $calls = 0;
    $malformedTransport = static function (string $method, string $url, array $headers, ?string $body) use ($badToken, &$calls): array {
        $calls++;
        if ($calls !== 1) {
            throw new RuntimeException('Requisição de produtos enviada com Bearer inválido.');
        }
        return ['status' => 200, 'body' => json_encode(['access_token' => $badToken, 'expires_in' => 300], JSON_THROW_ON_ERROR)];
    };
    try {
        (new KiwifyIntegration(Closure::fromCallable($malformedTransport)))->listProducts();
        throw new RuntimeException('Aceitou Bearer inválido: ' . $label);
    } catch (RuntimeException $e) {
        checkKiwify($e->getMessage() === 'Kiwify não retornou token válido.', 'erro seguro para Bearer ' . $label);
        checkKiwify($calls === 1, 'não consulta produtos com Bearer ' . $label);
    }
}
putenv("KIWIFY_ACCOUNT_ID=bad\r\nX-Test: evil");
try {
    (new KiwifyIntegration(static fn () => ['status' => 200, 'body' => '{}']))->listProducts();
    throw new RuntimeException('Aceitou account id com CRLF');
} catch (RuntimeException $e) {
    checkKiwify(str_contains($e->getMessage(), 'inválido'), 'bloqueia injeção de header');
}
// Respostas acima do page_size contratado não podem chegar ao staging.
// Restaurar conta sintética válida após o teste de injeção de cabeçalho.
putenv('KIWIFY_ACCOUNT_ID=test-account');
$oversizedCalls = 0;
$oversizedTransport = static function (string $method, string $url, array $headers, ?string $body) use (&$oversizedCalls): array {
    $oversizedCalls++;
    if ($oversizedCalls === 1) {
        return ['status' => 200, 'body' => '{"access_token":"test-token","expires_in":300}'];
    }
    $products = array_fill(0, 11, ['id' => 'synthetic', 'name' => 'Produto sintético']);
    return ['status' => 200, 'body' => json_encode([
        'data' => $products,
        'pagination' => ['page_number' => 1, 'page_size' => 10],
    ], JSON_THROW_ON_ERROR)];
};
try {
    (new KiwifyIntegration(Closure::fromCallable($oversizedTransport)))->listProducts(1, 10);
    throw new RuntimeException('Aceitou página Kiwify com produtos excedentes');
} catch (RuntimeException $e) {
    checkKiwify($e->getMessage() === 'Kiwify retornou mais produtos que o limite solicitado.', 'rejeita página acima do limite');
}
checkKiwify($oversizedCalls === 2, 'limite rejeitado após uma consulta de produtos');

echo "Kiwify: testes simulados aprovados.\n";
