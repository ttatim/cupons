-- Tabela de usuários administrativos
CREATE TABLE usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de lojas
CREATE TABLE lojas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    logo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de cupons
CREATE TABLE cupons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loja_id INT,
    tipo ENUM('percentual', 'valor') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    descricao TEXT,
    codigo VARCHAR(100),
    link_afiliado TEXT,
    data_inicio DATE,
    data_fim DATE,
    ativo BOOLEAN DEFAULT TRUE,
    cliques INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loja_id) REFERENCES lojas(id)
);

-- Tabela de produtos
CREATE TABLE produtos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loja_id INT,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    preco DECIMAL(10,2),
    preco_original DECIMAL(10,2),
    cupom_desconto VARCHAR(100),
    link_afiliado TEXT,
    imagens JSON,
    ativo BOOLEAN DEFAULT TRUE,
    cliques INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loja_id) REFERENCES lojas(id)
);

-- Tabela de estatísticas
CREATE TABLE estatisticas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tipo ENUM('cupom', 'produto') NOT NULL,
    item_id INT NOT NULL,
    data_acesso DATE NOT NULL,
    cliques INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);