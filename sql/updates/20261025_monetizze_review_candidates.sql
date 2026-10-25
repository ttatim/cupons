-- Item 03: append-only LOCAL candidates for manual review of Monetizze status differences.
-- INTERNAL relay HMAC is not Monetizze authentication. No financial authorization.
-- Apply AFTER sql/updates/20261024_monetizze_relay_nonces.sql and
-- sql/updates/20261024_monetizze_event_observations.sql.
-- This creates an EMPTY table; never automatically delete or approve evidence.
CREATE TABLE IF NOT EXISTS monetizze_review_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    candidate_status SMALLINT UNSIGNED NOT NULL,
    candidate_event SMALLINT UNSIGNED NOT NULL,
    original_status SMALLINT UNSIGNED NOT NULL,
    original_event SMALLINT UNSIGNED NOT NULL,
    risk_direction VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monetizze_candidate (account_hash, event_hash, candidate_status, candidate_event),
    KEY idx_monetizze_candidate_account (account_hash, first_seen_at),
    CONSTRAINT chk_monetizze_candidate_direction
        CHECK (risk_direction IN ('escalation', 'reduction_unverified', 'changed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
