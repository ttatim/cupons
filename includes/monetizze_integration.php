<?php
declare(strict_types=1);

/**
 * Monetizze API v2.1: verificação de autenticação somente leitura.
 *
 * A chave X_CONSUMER_KEY fica exclusivamente em variável de ambiente.
 * Este adaptador NÃO sincroniza produtos, vendas ou comissões.
 */
final class MonetizzeIntegration
{
    private const TOKEN_URL = 'https://api.monetizze.com.br/2.1/token';

    /** @var null|Closure(string,string,array,?string):array */
    private ?Closure $transport;

    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    public static function credentialsPresent(): bool
    {
        return trim((string) getenv('MONETIZZE_CONSUMER_KEY')) !== '';
    }

    /**
     * Verifica a chave com o endpoint oficial de emissão de TOKEN.
     * Nunca retorna ou armazena o token, apenas o resultado da autenticação.
     */
    public function checkConnection(): bool
    {
        $key = trim((string) getenv('MONETIZZE_CONSUMER_KEY'));
        if ($key === '') {
            throw new RuntimeException('Configure MONETIZZE_CONSUMER_KEY no servidor.');
        }
        // Evita injeção de cabeçalhos e limita a extensão da credencial.
        if (strlen($key) > 512 || strlen($key) < 10 || !preg_match('/\A[\x21-\x7e]+\z/D', $key)) {
            throw new RuntimeException('MONETIZZE_CONSUMER_KEY inválida.');
        }
        $headers = ['X_CONSUMER_KEY: ' . $key, 'Accept: application/json'];
        $response = $this->transport !== null
            ? ($this->transport)('GET', self::TOKEN_URL, $headers, null)
            : self::curlTransport(self::TOKEN_URL, $headers);

        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            // Não incluir corpo de erro, URL autenticada ou credencial em mensagens.
            throw new RuntimeException('Monetizze retornou HTTP ' . $status . '. Confira a chave e as permissões.');
        }
        $body = (string) ($response['body'] ?? '');
        if (strlen($body) > 1048576) {
            throw new RuntimeException('Resposta Monetizze excedeu o limite permitido.');
        }
        $data = json_decode($body, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Monetizze retornou JSON inválido.');
        }
        $token = $data['TOKEN'] ?? $data['token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 8192
            || preg_match('/[\r\n]/', $token)) {
            throw new RuntimeException('Monetizze não retornou um TOKEN válido.');
        }
        // O token é intencionalmente descartado. Não gravar em logs, sessão ou banco.
        return true;
    }

    private static function curlTransport(string $url, array $headers): array
    {
        // Destino imutável, sem URLs de usuário, redirects ou outros protocolos.
        if ($url !== self::TOKEN_URL) {
            throw new RuntimeException('Destino Monetizze não autorizado.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar conexão Monetizze.');
        }
        $responseBody = '';
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
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
        try {
            if (curl_exec($ch) === false) {
                throw new RuntimeException('Falha na conexão segura com Monetizze.');
            }
            return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $responseBody];
        } finally {
            curl_close($ch);
        }
    }
}
