<?php
declare(strict_types=1);
require_once __DIR__ . '/monetizze_webhook_review.php';

/**
 * Untrusted native Monetizze sales postback parser (JSON or form-urlencoded).
 * This is NOT an HTTP receiver or proof of origin. No raw buyer data is retained.
 */
final class MonetizzeNativePostbackPreview
{
    private const MAX_BYTES = 65536;
    private const MAX_FIELDS = 200;
    private const REQUIRED = ['id', 'chave_unica', 'codigo_status', 'postback_evento'];
    private const SALES_EVENTS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '98', '99'];

    /** @return array<string,int|bool> Aggregate counts only, never financial authorization. */
    public static function preview(string $raw, string $contentType, string $expectedAccountKey): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Tamanho do postback inválido.');
        }
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($type === 'application/json') {
            try {
                $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('JSON do postback inválido.');
            }
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('Objeto JSON do postback inválido.');
            }
        } elseif ($type === 'application/x-www-form-urlencoded') {
            $data = self::parseForm($raw);
        } else {
            throw new InvalidArgumentException('Formato do postback não suportado.');
        }
        // Reject unsupported event families; sales status is not always present
        // on subscriptions, tracking or tickets, which require separate mapping.
        $eventCode = $data['postback_evento'] ?? null;
        if ((!is_int($eventCode) && !is_string($eventCode))
            || !in_array((string) $eventCode, self::SALES_EVENTS, true)) {
            throw new InvalidArgumentException('Tipo de evento não homologado para vendas.');
        }
        $minimal = [];
        foreach (self::REQUIRED as $field) {
            $value = $data[$field] ?? null;
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException('Campo mínimo do postback inválido.');
            }
            $minimal[$field] = $value;
        }
        // Deliberately discard the rest, including comprador, comissoes, CPF,
        // email, tracking links, tokens and any unknown upstream fields.
        $summary = MonetizzeWebhookReview::preview([$minimal], $expectedAccountKey);
        $summary['origin_verified'] = false;
        return $summary;
    }

    /** @return array<string,string> Only the four fields needed for a safe preview. */
    private static function parseForm(string $raw): array
    {
        $parts = explode('&', $raw);
        if (count($parts) > self::MAX_FIELDS) {
            throw new InvalidArgumentException('Postback com campos excessivos.');
        }
        $minimal = [];
        foreach ($parts as $part) {
            if ($part === '' || !str_contains($part, '=')) {
                throw new InvalidArgumentException('Campo de formulário inválido.');
            }
            [$encodedKey, $encodedValue] = explode('=', $part, 2);
            if (preg_match('/%(?![0-9a-fA-F]{2})/', $encodedKey . $encodedValue)) {
                throw new InvalidArgumentException('Codificação de formulário inválida.');
            }
            $key = urldecode($encodedKey);
            if (in_array($key, self::REQUIRED, true)) {
                if (array_key_exists($key, $minimal)) {
                    throw new InvalidArgumentException('Campo mínimo duplicado.');
                }
                $minimal[$key] = urldecode($encodedValue);
            }
        }
        return $minimal;
    }
}
