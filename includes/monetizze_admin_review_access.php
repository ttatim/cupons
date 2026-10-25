<?php
declare(strict_types=1);

/** Server-side guard for the single-account Monetizze review console. */
final class MonetizzeAdminReviewAccess
{
    public static function requireAdmin(array $session, Closure $lookupActiveRole): string
    {
        try {
            $id = self::id($session['user_id'] ?? null);
            if ($lookupActiveRole($id) !== 'admin') {
                throw new RuntimeException('Acesso negado.');
            }
            return 'admin-user-' . $id;
        } catch (Throwable $exception) {
            throw new RuntimeException('Acesso negado.');
        }
    }

    /** @return array{account:string,hash_key:string} */
    public static function settings(string $account, string $hashKey): array
    {
        $account = strtolower(trim($account));
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $account)
            || strlen($hashKey) < 32 || strlen($hashKey) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $hashKey)) {
            throw new RuntimeException('Revisão Monetizze não configurada no servidor.');
        }
        return ['account' => $account, 'hash_key' => $hashKey];
    }

    public static function id(mixed $value, bool $allowZero = false): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Identificador inválido.');
        }
        $text = (string) $value;
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $text)) {
            throw new InvalidArgumentException('Identificador inválido.');
        }
        $number = filter_var($text, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $allowZero ? 0 : 1],
        ]);
        if ($number === false) {
            throw new InvalidArgumentException('Identificador inválido.');
        }
        return $number;
    }

    public static function action(mixed $value): string
    {
        if (!is_string($value) || !in_array($value,
            ['request_evidence', 'escalate', 'acknowledge_unverified'], true)) {
            throw new InvalidArgumentException('Ação inválida.');
        }
        return $value;
    }

    public static function requestId(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-fA-F0-9]{32}\z/D', $value)) {
            throw new InvalidArgumentException('Solicitação inválida.');
        }
        return strtolower($value);
    }

    public static function authorizer(string $boundAccount, string $boundOperator): Closure
    {
        return static function (string $account, string $operator) use ($boundAccount, $boundOperator): bool {
            return hash_equals($boundAccount, strtolower($account))
                && hash_equals($boundOperator, $operator);
        };
    }
}
