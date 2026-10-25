-- Etapa 03: observações MÍNIMAS de eventos de relay interno Monetizze.
-- Não autentica a origem Monetizze; não autoriza conciliação ou pagamento.
-- Aplicar após sql/database.sql e 20261024_monetizze_relay_nonces.sql.
-- Nenhuma PII, payload, identificador externo ou chave de conta em claro.
-- Sem limpeza automática até aprovação formal de política de retenção.
CREATE TABLE IF NOT EXISTS monetizze_event_observations (
    account_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_status SMALLINT UNSIGNED NOT NULL,
    observed_event SMALLINT UNSIGNED NOT NULL,
    review_required TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_hash, event_hash),
    KEY idx_monetizze_observation_review (account_hash, review_required, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
