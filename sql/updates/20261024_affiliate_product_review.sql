-- Etapa 03: campos de revisão manual para catálogo Kiwify.
-- Migração incremental: execute após 20261024_affiliate_product_staging.sql.
-- Não publica ofertas nem insere dados de exemplo.
ALTER TABLE affiliate_product_staging
    ADD COLUMN review_description TEXT NULL,
    ADD COLUMN review_price DECIMAL(10,2) NULL,
    ADD COLUMN review_original_price DECIMAL(10,2) NULL,
    ADD COLUMN review_checkout_url VARCHAR(2048) NULL,
    ADD COLUMN review_image_url VARCHAR(2048) NULL,
    ADD COLUMN review_store_id INT NULL,
    ADD CONSTRAINT fk_affiliate_review_store
        FOREIGN KEY (review_store_id) REFERENCES lojas(id) ON DELETE SET NULL;
