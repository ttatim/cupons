<?php
declare(strict_types=1);

/**
 * Fail-closed, server-side authorization for provider API and catalog actions.
 * The current integration console is single-account; seller-scoped access is
 * deliberately deferred until tenant isolation is implemented (requirement 10).
 */
final class AffiliateAdminAccess
{
    /** @param Closure(int):?string $lookupActiveRole Lookup MUST filter ativo = 1. */
    public static function requireActiveAdmin(array $session, Closure $lookupActiveRole): void
    {
        try {
            $rawId = $session['user_id'] ?? null;
            if ((!is_int($rawId) && !is_string($rawId))
                || !preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $rawId)) {
                throw new RuntimeException('Acesso negado.');
            }
            $id = filter_var((string) $rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $lookupActiveRole($id) !== 'admin') {
                throw new RuntimeException('Acesso negado.');
            }
        } catch (Throwable $exception) {
            // Never disclose whether a user exists, is disabled, or has a role.
            throw new RuntimeException('Acesso negado.');
        }
    }
}
