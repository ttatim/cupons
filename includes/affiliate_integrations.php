<?php
declare(strict_types=1);
require_once __DIR__ . '/kiwify_integration.php';
require_once __DIR__ . '/monetizze_integration.php';

/**
 * Integração Hotmart (OAuth 2.0 + consulta somente leitura).
 * Segredos são obtidos exclusivamente de variáveis de ambiente do servidor.
 * Não armazena nem registra tokens, URLs autenticadas ou dados de compradores.
 */
final class AffiliateIntegrations
{
    private const AUTH_URL = 'https://api-sec-vlc.hotmart.com/security/oauth/token';
    private const SALES_URL = 'https://developers.hotmart.com/payments/api/v1/sales/summary';
    private ?string $token = null;
    private int $expiresAt = 0;
    /** @var null|Closure(string,string,array,?string):array */
    private ?Closure $transport;

    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    public static function status(): array
    {
        $ready = self::credentialsPresent();
        return [
            'hotmart' => [
                'label' => 'Hotmart',
                'state' => $ready ? 'configurada (não verificada)' : 'aguardando credenciais',
                'ready' => $ready,
                'implemented' => true,
            ],
            'kiwify' => [
                'label' => 'Kiwify',
                'state' => KiwifyIntegration::credentialsPresent() ? 'configurada (não verificada)' : 'aguardando credenciais',
                'ready' => KiwifyIntegration::credentialsPresent(),
                'implemented' => true,
            ],
            'monetizze' => [
                'label' => 'Monetizze',
                'state' => MonetizzeIntegration::credentialsPresent()
                    ? 'chave configurada (não verificada); consultas pendentes'
                    : 'aguardando chave; consultas pendentes',
                'ready' => MonetizzeIntegration::credentialsPresent(),
                'implemented' => false,
                'auth_check_implemented' => true,
            ],
        ];
    }

    public static function credentialsPresent(): bool
    {
        foreach (['HOTMART_CLIENT_ID', 'HOTMART_CLIENT_SECRET', 'HOTMART_BASIC_TOKEN'] as $key) {
            if (trim((string) getenv($key)) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Consulta sumário da Hotmart, sem persistir dados pessoais.
     * @return array{items:array,page_info:array}
     */
    public function hotmartSalesSummary(int $maxResults = 10): array
    {
        if (!self::credentialsPresent()) {
            throw new RuntimeException('Configure HOTMART_CLIENT_ID, HOTMART_CLIENT_SECRET e HOTMART_BASIC_TOKEN no servidor.');
        }
        if ($maxResults < 1 || $maxResults > 50) {
            throw new InvalidArgumentException('Limite permitido: 1 a 50.');
        }
        $query = http_build_query(['max_results' => $maxResults], '', '&', PHP_QUERY_RFC3986);
        $data = $this->request('GET', self::SALES_URL . '?' . $query, [
            'Authorization: Bearer ' . $this->accessToken(),
            'Accept: application/json',
        ]);
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('Resposta da Hotmart não contém uma lista de resultados.');
        }
        return ['items' => $data['items'], 'page_info' => is_array($data['page_info'] ?? null) ? $data['page_info'] : []];
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->expiresAt) {
            return $this->token;
        }
        $query = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => (string) getenv('HOTMART_CLIENT_ID'),
            'client_secret' => (string) getenv('HOTMART_CLIENT_SECRET'),
        ], '', '&', PHP_QUERY_RFC3986);
        $basic = trim((string) getenv('HOTMART_BASIC_TOKEN'));
        // Hotmart fornece o token Basic; pode vir com ou sem o prefixo.
        if (str_starts_with(strtolower($basic), 'basic ')) {
            $basic = substr($basic, 6);
        }
        $data = $this->request('POST', self::AUTH_URL . '?' . $query, [
            'Authorization: Basic ' . $basic,
            'Accept: application/json',
            'Content-Type: application/json',
        ]);
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 8192) {
            throw new RuntimeException('Hotmart não retornou um token de acesso válido.');
        }
        $this->token = $token;
        $ttl = max(1, (int) ($data['expires_in'] ?? 300));
        $this->expiresAt = time() + max(1, $ttl - 60);
        return $token;
    }

    private function request(string $method, string $url, array $headers): array
    {
        // URL definida internamente: não aceita host, caminhos ou redirecionamentos fornecidos pelo usuário.
        $host = parse_url($url, PHP_URL_HOST);
        if (!in_array($host, ['api-sec-vlc.hotmart.com', 'developers.hotmart.com'], true)
            || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Destino da integração não autorizado.');
        }
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $headers, null);
        } else {
            $response = self::curlTransport($method, $url, $headers);
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            // Não expor body nem URL (que pode conter client_secret).
            throw new RuntimeException('Hotmart retornou HTTP ' . $status . '. Verifique credenciais, permissões e limites de API.');
        }
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Hotmart retornou JSON inválido.');
        }
        return $decoded;
    }

    private static function curlTransport(string $method, string $url, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar conexão com Hotmart.');
        }
        $body = '';
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
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($ch) === false) {
                throw new RuntimeException('Falha na conexão segura com Hotmart.');
            }
            return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally {
            curl_close($ch);
        }
    }
}
