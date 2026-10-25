-- Item 03: append-only human TRIAGE log for UNVERIFIED Monetizze candidates.
-- Apply after 20261025_monetizze_review_candidates.sql; creates EMPTY table.
-- No provider verification, financial approval, payment or balance changes.
CREATE TABLE IF NOT EXISTS monetizze_review_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    operator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monetizze_decision_request (account_hash, request_hash),
    KEY idx_monetizze_decision_candidate (account_hash, candidate_id, id),
    CONSTRAINT fk_monetizze_decision_candidate FOREIGN KEY (candidate_id)
        REFERENCES monetizze_review_candidates (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_monetizze_decision_action CHECK (
        action IN ('request_evidence', 'escalate', 'acknowledge_unverified')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
