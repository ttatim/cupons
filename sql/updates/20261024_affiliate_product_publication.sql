-- Etapa 03: publicação supervisionada, sem inserir dados de exemplo.
-- Executar UMA VEZ depois de:
--   sql/database.sql
--   20261024_affiliate_product_staging.sql
--   20261024_affiliate_product_review.sql
-- Faça backup do banco existente antes da migração.
ALTER TABLE affiliate_product_staging
    ADD COLUMN published_product_id INT NULL,
    ADD COLUMN published_at DATETIME NULL,
    ADD UNIQUE KEY uq_affiliate_published_product (published_product_id),
    ADD CONSTRAINT fk_affiliate_published_product
        FOREIGN KEY (published_product_id) REFERENCES produtos(id) ON DELETE SET NULL;
