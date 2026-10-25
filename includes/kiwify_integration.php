<?php
declare(strict_types=1);

/** Consulta somente leitura à API oficial Kiwify. Não persiste dados ou credenciais. */
final class KiwifyIntegration
{
    private const BASE_URL = 'https://public-api.kiwify.com/v1';
    private ?string $token = null;
    private int $expiresAt = 0;
    /** @var null|Closure(string,string,array,?string):array */
    private ?Closure $transport;

    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    public static function credentialsPresent(): bool
    {
        foreach (['KIWIFY_CLIENT_ID', 'KIWIFY_CLIENT_SECRET', 'KIWIFY_ACCOUNT_ID'] as $key) {
            if (trim((string) getenv($key)) === '') {
                return false;
            }
        }
        return true;
    }

    /** @return array{data:array,pagination:array} */
    public function listProducts(int $page = 1, int $pageSize = 10): array
    {
        if (!self::credentialsPresent()) {
            throw new RuntimeException('Configure KIWIFY_CLIENT_ID, KIWIFY_CLIENT_SECRET e KIWIFY_ACCOUNT_ID no servidor.');
        }
        if ($page < 1 || $page > 1000 || $pageSize < 1 || $pageSize > 50) {
            throw new InvalidArgumentException('Paginação inválida: página 1 a 1000 e tamanho 1 a 50.');
        }
        $accountId = trim((string) getenv('KIWIFY_ACCOUNT_ID'));
        if (!preg_match('/\A[a-zA-Z0-9_-]{3,128}\z/D', $accountId)) {
            throw new RuntimeException('KIWIFY_ACCOUNT_ID inválido.');
        }
        $query = http_build_query(['page_number' => $page, 'page_size' => $pageSize], '', '&', PHP_QUERY_RFC3986);
        $response = $this->request('GET', self::BASE_URL . '/products?' . $query, [
            'Authorization: Bearer ' . $this->accessToken(),
            'x-kiwify-account-id: ' . $accountId,
            'Accept: application/json',
        ], null);
        if (!isset($response['data']) || !is_array($response['data']) || !array_is_list($response['data'])) {
            throw new RuntimeException('Resposta Kiwify sem lista de produtos válida.');
        }
        // A paginação limita também a quantidade aceita da API, não apenas a solicitada.
        // Uma resposta acima do limite nunca deve ser enviada ao painel nem ao staging.
        if (count($response['data']) > $pageSize) {
            throw new RuntimeException('Kiwify retornou mais produtos que o limite solicitado.');
        }
        return [
            'data' => $response['data'],
            'pagination' => is_array($response['pagination'] ?? null) ? $response['pagination'] : [],
        ];
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->expiresAt) {
            return $this->token;
        }
        $body = http_build_query([
            'client_id' => (string) getenv('KIWIFY_CLIENT_ID'),
            'client_secret' => (string) getenv('KIWIFY_CLIENT_SECRET'),
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->request('POST', self::BASE_URL . '/oauth/token', [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);
        $token = $response['access_token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 8192
            || !preg_match('/\A[\x21-\x7e]+\z/D', $token)) {
            throw new RuntimeException('Kiwify não retornou token válido.');
        }
        $this->token = $token;
        $ttl = max(1, min(345600, (int) ($response['expires_in'] ?? 300)));
        $this->expiresAt = time() + max(1, $ttl - 60);
        return $token;
    }

    private function request(string $method, string $url, array $headers, ?string $body): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'public-api.kiwify.com'
            || !str_starts_with($parts['path'] ?? '', '/v1/')) {
            throw new RuntimeException('Destino Kiwify não autorizado.');
        }
        $result = $this->transport !== null
            ? ($this->transport)($method, $url, $headers, $body)
            : self::curlTransport($method, $url, $headers, $body);
        $status = (int) ($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Kiwify retornou HTTP ' . $status . '. Confira permissões, credenciais e limites de API.');
        }
        $decoded = json_decode((string) ($result['body'] ?? ''), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Kiwify retornou JSON inválido.');
        }
        return $decoded;
    }

    private static function curlTransport(string $method, string $url, array $headers, ?string $requestBody): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar conexão Kiwify.');
        }
        $responseBody = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($requestBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        }
        try {
            if (curl_exec($ch) === false) {
                throw new RuntimeException('Falha na conexão segura com Kiwify.');
            }
            return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $responseBody];
        } finally {
            curl_close($ch);
        }
    }
}
