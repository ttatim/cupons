<?php
declare(strict_types=1);

require_once __DIR__ . '/monetizze_webhook_review.php';

/**
 * Verifies an INTERNAL trusted relay envelope, NOT a native Monetizze signature.
 * A trusted relay must independently authenticate the upstream Monetizze message
 * before signing and forwarding the unmodified JSON body.
 *
 * No HTTP endpoint, database writes, payments or reconciliation are performed.
 */
final class MonetizzeSignedRelayReview
{
    private const MAX_BODY_BYTES = 65536;
    private const MAX_CLOCK_SKEW = 300;

    /**
     * @param Closure(string,int):bool $claimNonce Atomic, persistent replay claim.
     *   The callback MUST return false for a previously claimed nonce, including
     *   across workers/restarts; an in-memory callback is suitable ONLY for tests.
     * @return array<string,int|bool> Aggregate counters and relay_authenticated.
     */
    public static function verifyAndPreview(
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $signature,
        string $relaySecret,
        string $expectedAccountKey,
        Closure $claimNonce,
        ?int $now = null
    ): array {
        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES
            || strlen($relaySecret) < 32 || strlen($relaySecret) > 512
            || !preg_match('/\A[1-9][0-9]{9,10}\z/D', $timestamp)
            || !preg_match('/\A[a-f0-9]{32}\z/D', $nonce)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $signature)) {
            throw new InvalidArgumentException('Envelope de relay inválido.');
        }
        $currentTime = $now ?? time();
        $eventTime = (int) $timestamp;
        if ($currentTime < 0 || abs($currentTime - $eventTime) > self::MAX_CLOCK_SKEW) {
            throw new InvalidArgumentException('Envelope de relay expirado ou futuro.');
        }
        // Protocol v1: exact bytes, fixed separators, no canonical JSON ambiguity.
        $message = "monetizze-relay-v1\n" . $timestamp . "\n" . $nonce . "\n" . $rawBody;
        $expectedSignature = hash_hmac('sha256', $message, $relaySecret);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new InvalidArgumentException('Assinatura do relay inválida.');
        }
        try {
            $events = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('JSON do relay inválido.');
        }
        if (!is_array($events)) {
            throw new InvalidArgumentException('Lote do relay inválido.');
        }
        // json_decode silently overwrites duplicate object keys. Reject ambiguous
        // signed bytes, including Unicode-escaped aliases, BEFORE nonce claim.
        self::rejectDuplicateJsonKeys($rawBody);
        // Validate before consuming the replay nonce; output never includes PII.
        $summary = MonetizzeWebhookReview::preview($events, $expectedAccountKey);
        try {
            $claimed = $claimNonce($nonce, $eventTime + self::MAX_CLOCK_SKEW);
        } catch (Throwable $exception) {
            // Never expose storage details, credentials, raw body or account key.
            throw new RuntimeException('Proteção contra repetição indisponível.');
        }
        if ($claimed !== true) {
            throw new InvalidArgumentException('Envelope de relay já processado.');
        }
        $summary['relay_authenticated'] = true;
        // This explicitly remains false: the relay signature is not proof that
        // Monetizze itself sent the message, nor permission to settle funds.
        return $summary;
    }

    /** Scan all JSON objects, including nested buyer metadata, without retaining values. */
    private static function rejectDuplicateJsonKeys(string $json): void
    {
        $offset = 0;
        $length = strlen($json);
        self::scanValue($json, $offset, $length);
        self::skipWhitespace($json, $offset, $length);
        if ($offset !== $length) {
            throw new InvalidArgumentException('Estrutura JSON do relay inválida.');
        }
    }

    private static function scanValue(string $json, int &$offset, int $length): void
    {
        self::skipWhitespace($json, $offset, $length);
        if ($offset >= $length) {
            throw new InvalidArgumentException('Estrutura JSON do relay inválida.');
        }
        $char = $json[$offset];
        if ($char === '{') {
            $offset++;
            $seen = [];
            self::skipWhitespace($json, $offset, $length);
            if ($offset < $length && $json[$offset] === '}') {
                $offset++;
                return;
            }
            do {
                self::skipWhitespace($json, $offset, $length);
                $start = $offset;
                self::scanString($json, $offset, $length);
                $key = json_decode(substr($json, $start, $offset - $start), true, 16, JSON_THROW_ON_ERROR);
                $index = "\0" . $key; // Avoid PHP's numeric-string key coercion.
                if (isset($seen[$index])) {
                    throw new InvalidArgumentException('Chave JSON duplicada no relay.');
                }
                $seen[$index] = true;
                self::skipWhitespace($json, $offset, $length);
                self::consume($json, $offset, $length, ':');
                self::scanValue($json, $offset, $length);
                self::skipWhitespace($json, $offset, $length);
                if ($offset < $length && $json[$offset] === '}') {
                    $offset++;
                    return;
                }
                self::consume($json, $offset, $length, ',');
            } while (true);
        }
        if ($char === '[') {
            $offset++;
            self::skipWhitespace($json, $offset, $length);
            if ($offset < $length && $json[$offset] === ']') {
                $offset++;
                return;
            }
            do {
                self::scanValue($json, $offset, $length);
                self::skipWhitespace($json, $offset, $length);
                if ($offset < $length && $json[$offset] === ']') {
                    $offset++;
                    return;
                }
                self::consume($json, $offset, $length, ',');
            } while (true);
        }
        if ($char === '"') {
            self::scanString($json, $offset, $length);
            return;
        }
        // json_decode already checked scalar syntax and nesting depth.
        $start = $offset;
        while ($offset < $length && !str_contains(",]} \t\r\n", $json[$offset])) {
            $offset++;
        }
        if ($start === $offset) {
            throw new InvalidArgumentException('Estrutura JSON do relay inválida.');
        }
    }

    private static function scanString(string $json, int &$offset, int $length): void
    {
        self::consume($json, $offset, $length, '"');
        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                $offset += 2;
            } elseif ($json[$offset++] === '"') {
                return;
            }
        }
        throw new InvalidArgumentException('Estrutura JSON do relay inválida.');
    }

    private static function skipWhitespace(string $json, int &$offset, int $length): void
    {
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    private static function consume(string $json, int &$offset, int $length, string $expected): void
    {
        if ($offset >= $length || $json[$offset] !== $expected) {
            throw new InvalidArgumentException('Estrutura JSON do relay inválida.');
        }
        $offset++;
    }

}
