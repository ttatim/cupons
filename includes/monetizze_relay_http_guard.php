<?php
declare(strict_types=1);

require_once __DIR__ . '/monetizze_signed_relay_review.php';

/**
 * Transport-only guard for a FUTURE private, authenticated relay endpoint.
 * It does not expose an HTTP route, prove native Monetizze origin or settle funds.
 */
final class MonetizzeRelayHttpGuard
{
    private const MAX_BODY_BYTES = 65536;

    /**
     * @param array<string,mixed> $server Trusted PHP server variables, not user JSON.
     * @param Closure(string,int):bool $claimNonce Durable, atomic replay reservation.
     * @param bool $upstreamVerified Set true ONLY after independently verifying
     *   the trusted relay's authentication of Monetizze upstream. This flag is
     *   an operator assertion, NOT a cryptographic proof by this class.
     * @return array<string,int|bool> Counters only; never payment authorization.
     */
    public static function verifyAndPreview(
        array $server,
        string $rawBody,
        string $relaySecret,
        string $expectedAccountKey,
        Closure $claimNonce,
        bool $upstreamVerified = false,
        bool $enabled = false,
        ?int $now = null
    ): array {
        // Both gates are trusted application configuration, NEVER HTTP headers.
        if (!$enabled || !$upstreamVerified) {
            throw new RuntimeException('Recebimento de relay não habilitado.');
        }
        if (($server['REQUEST_METHOD'] ?? null) !== 'POST') {
            throw new InvalidArgumentException('Método HTTP não permitido.');
        }
        $type = $server['CONTENT_TYPE'] ?? null;
        if (!is_string($type) || !preg_match('/\Aapplication\/json(?:\s*;\s*charset\s*=\s*(?:utf-8|"utf-8"))?\z/iD', trim($type))) {
            throw new InvalidArgumentException('Tipo de conteúdo não permitido.');
        }
        // Reject compressed or ambiguous framing before inspecting signed bytes.
        $encoding = $server['HTTP_CONTENT_ENCODING'] ?? 'identity';
        if (!is_string($encoding) || strtolower(trim($encoding)) !== 'identity'
            || isset($server['HTTP_TRANSFER_ENCODING'])) {
            throw new InvalidArgumentException('Codificação HTTP não permitida.');
        }
        $bodySize = strlen($rawBody);
        if ($bodySize === 0 || $bodySize > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Tamanho de requisição inválido.');
        }
        if (array_key_exists('CONTENT_LENGTH', $server)) {
            $length = $server['CONTENT_LENGTH'];
            if ((!is_string($length) && !is_int($length))
                || !preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D', (string) $length)
                || (int) $length !== $bodySize) {
                throw new InvalidArgumentException('Comprimento HTTP inconsistente.');
            }
        }
        $timestamp = $server['HTTP_X_MONETIZZE_RELAY_TIMESTAMP'] ?? null;
        $nonce = $server['HTTP_X_MONETIZZE_RELAY_NONCE'] ?? null;
        $signature = $server['HTTP_X_MONETIZZE_RELAY_SIGNATURE'] ?? null;
        if (!is_string($timestamp) || !is_string($nonce) || !is_string($signature)) {
            throw new InvalidArgumentException('Cabeçalhos de relay ausentes.');
        }
        $summary = MonetizzeSignedRelayReview::verifyAndPreview(
            $rawBody, $timestamp, $nonce, $signature, $relaySecret,
            $expectedAccountKey, $claimNonce, $now
        );
        // HMAC authenticates the relay, not a native Monetizze webhook.
        $summary['origin_verified'] = false;
        $summary['authenticated'] = false;
        $summary['financial_ready'] = false;
        return $summary;
    }
}
