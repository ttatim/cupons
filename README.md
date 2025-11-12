# Cupons (Pelando Clone)

Portal completo para divulgação de cupons de desconto e produtos de dropshipping, com área pública, painel administrativo, integrações com APIs de afiliados e coleta de métricas de desempenho.

---

## Visão geral

| Área | Descrição |
| --- | --- |
| Público | Listagens filtráveis de cupons, produtos e lojas, páginas individuais (`index.php`, `cupons.php`, `produtos.php`, `loja.php`, `oferta.php`). |
| Admin | Painel responsivo (`admin/`) com autenticação, CRUD de cupons/produtos/lojas, estatísticas em tempo real, exportação e gerenciamento de integrações. |
| APIs internas | Endpoints AJAX em `ajax/` para carregar listas, estatísticas, uploads, notificações e sincronizações assíncronas. |
| Serviços auxiliares | Logs em `logs/`, scripts de sincronização (`admin/api-sync.php`), monitoramento básico (`ajax/system-info.php`, `ajax/system-status.php`) e helpers reutilizáveis (`includes/`). |

---

## Principais funcionalidades

- Cadastro e exibição de cupons e produtos com filtros por loja, busca textual e paginação.
- Gestão de lojas, upload otimizado de imagens e controle de validade dos cupons.
- Painel com métricas (cliques, conversões, receita por parceria, itens mais clicados, alertas de expiração).
- Sincronização com APIs de afiliados (Mercado Livre, Shopee, AliExpress) e registro de logs por integração.
- Exportação de estatísticas em CSV/JSON e endpoints para dashboards embutidos.
- Monitoramento do sistema (health check, ping, uso de memória, notificações) e camadas básicas de segurança/XSS/CSRF.

---

## Tecnologias utilizadas

- **Backend:** PHP 8+, PDO/MySQL, sessões nativas, cURL, GD (para manipulação de imagens).
- **Banco:** MySQL 5.7+/8.0 (scripts em `sql/database.sql` e `sql/sample-database.sql`).
- **Frontend:** HTML5/CSS3 (Bootstrap-like), JavaScript vanilla (`assets/js/*.js`), Chart.js (carregado via bundle local), componentes responsivos personalizados (`assets/css/`).
- **Infraestrutura sugerida:** Apache/Nginx com suporte a `.htaccess`, PHP-FPM ou PHP embutido para desenvolvimento.

---

## Estrutura de diretórios

```
.
├── index.php                # Landing pública
├── cupons.php / produtos.php / loja.php / oferta.php
├── admin/                   # Painel administrativo completo
│   ├── dashboard.php        # KPIs e gráficos
│   ├── cupons.php / produtos.php / lojas.php / estatisticas.php / configuracoes.php
│   ├── api-sync.php         # Console para sincronizar APIs de afiliados
│   ├── includes/            # Header, sidebar, nav, footer do painel
│   └── login.php / logout.php
├── ajax/                    # Endpoints assíncronos (HTML/JSON)
│   ├── cupom.php / produto.php / estatisticas.php
│   ├── upload.php / api.php / cupom-admin.php
│   ├── system-info.php / system-status.php / check-updates.php
│   └── exportar-estatisticas.php / marcar-notificacao-lida.php / contador-notificacoes.php
├── assets/
│   ├── css/ (style.css, admin.css, responsive.css)
│   ├── js/  (main.js, admin.js, chart.js, upload.js)
│   └── uploads/ + images/ (lojas, produtos, logos, favicon)
├── includes/
│   ├── config.php / database.php / auth.php
│   ├── security.php / functions.php / apis.php
│   └── header.php / footer.php
├── logs/                    # security.log, errors.log, api.log
└── sql/                     # database.sql, sample-database.sql, updates/
```

---

## Pré-requisitos

1. PHP 8.1+ com extensões `pdo_mysql`, `openssl`, `curl`, `gd`, `json`, `mbstring`.
2. MySQL 5.7+ (ou MariaDB equivalente).
3. Servidor HTTP (Apache com mod_rewrite, Nginx ou `php -S` para desenvolvimento).
4. Composer não é obrigatório, mas recomendado caso deseje adicionar libs.

---

## Configuração rápida (desenvolvimento)

1. **Clonar o repositório**
   ```bash
   git clone git@github.com:seu-usuario/cupons.git
   cd cupons
   ```
2. **Configurar o host virtual** (por exemplo `cupons.local`) apontando para a raiz do projeto ou execute:
   ```bash
   php -S localhost:8000 -t .
   ```
3. **Criar banco e usuário**
   ```sql
   CREATE DATABASE pelando_clone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'pelando'@'localhost' IDENTIFIED BY 'senha_segura';
   GRANT ALL PRIVILEGES ON pelando_clone.* TO 'pelando'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. **Importar o schema**
   ```bash
   mysql -u pelando -p pelando_clone < sql/database.sql
   # Opcional: carregar dados de exemplo
   mysql -u pelando -p pelando_clone < sql/sample-database.sql
   ```
5. **Ajustar credenciais**
   - Edite `includes/config.php` e atualize `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
   - Configure a sessão segura (headers + cookies) e chame `criarUsuarioPadrao($pdo);` caso deseje criar o usuário padrão automaticamente (`ttatim` / `senha123`). Exemplo:
     ```bash
     php -r "require 'includes/config.php'; criarUsuarioPadrao($pdo); echo \"Usuario criado\\n\";"
     ```
6. **Permissões de escrita**
   ```bash
   chmod -R 775 assets/uploads logs
   ```
7. **Configurar APIs de afiliados**
   - Atualize `includes/apis.php` com suas chaves (`SUA_API_KEY`, `SEU_PARTNER_ID`, etc.).
   - Defina as colunas de configuração na tabela `configuracoes` (ver `admin/configuracoes.php`).
8. **Acessar**
   - Público: `http://localhost:8000`
   - Admin: `http://localhost:8000/admin` (login padrão descrito acima)

---

## Banco de dados

Tabela | Propósito
--- | ---
`usuarios` | Autenticação do painel (senha com `password_hash`). Campos extras podem ser adicionados para perfis.
`lojas` | Cadastro das lojas (slug usado em rotas e filtros).
`cupons` | Metadados dos cupons (tipo, valor, datas, códigos, links de afiliado, estatísticas de clique).
`produtos` | Produtos ligados às lojas, com preços, imagens (JSON) e cupom aplicado.
`estatisticas` | Métricas de cliques/conversões por dia e tipo (`cupom` ou `produto`).
`comissoes` | Totais importados das plataformas de afiliados.
`logs_sincronizacao`, `configuracoes`, `notificacoes` (se existir no SQL) | Apoio às telas de sync/configurações.

Scripts auxiliares ficam em `sql/updates/` para migrações incrementais.

---

## Endpoints AJAX/serviços internos

| Endpoint | Método | Retorno | Uso principal |
| --- | --- | --- | --- |
| `ajax/cupom.php` | GET | HTML parcial de cards (ou JSON quando chamado pelo modal) | Listagem e modal de cupons (`assets/js/main.js`). |
| `ajax/produto.php` | GET | HTML de produtos filtrados | Páginas de produtos e widgets. |
| `ajax/estatisticas.php?range=7d` | GET | HTML com KPIs/mini gráficos | Cards de estatísticas na home/Admin. |
| `ajax/exportar-estatisticas.php?formato=csv` | GET | Arquivo CSV/JSON | Export via painel. |
| `ajax/upload.php` | POST multipart | JSON | Upload de imagens (produtos/lojas). |
| `ajax/cupom-admin.php` | POST | JSON | CRUD em lote para cupons via painel. |
| `ajax/marcar-notificacao-lida.php` / `ajax/contador-notificacoes.php` | POST/GET | JSON | Badges de notificações no admin. |
| `ajax/system-info.php` / `ajax/system-status.php` / `ajax/check-updates.php` | GET | JSON/HTML | Monitoramento e health-checks (autenticados). |
| `ajax/api.php?action=ping|health` | GET | HTML | Teste de disponibilidade (sem autenticação). |

Todos os endpoints exigem `Security::setSecurityHeaders()` e sanitização manual (`Security::sanitizeXSS`, `Security::validateCSRFToken`) quando houver formulários.

---

## Fluxos importantes

1. **Sincronização de APIs (`admin/api-sync.php`)**
   - Seleciona plataforma (Mercado Livre, Shopee, AliExpress) e ação (ofertas, comissões, categorias, teste de conexão).
   - Cada execução registra em `logs_sincronizacao` e, quando bem-sucedida, atualiza `produtos`, `comissoes` e tabelas auxiliares.
   - Pode ser acionado manualmente via painel ou automatizado via cron chamando uma rota autenticada (por exemplo, `wget --user-agent=cron https://seusite/admin/api-sync.php` com cookie de sessão válido ou endpoint específico protegido por token).

2. **Upload e gerenciamento de imagens**
   - `includes/functions.php` provê `uploadImagem`, `otimizarImagem`, `redimensionarImagem` e `excluirImagem`.
   - `assets/uploads/{produtos,lojas}` deve ter permissão de escrita; arquivos são renomeados com `uniqid()` para evitar colisões.

3. **Estatísticas e dashboards**
   - `admin/dashboard.php` agrega KPIs (cupons ativos, produtos, lojas, usuários, cliques/conversões) e gráficos alimentados por `estatisticas` + `comissoes`.
   - `assets/js/chart.js` busca blocos HTML de `ajax/estatisticas.php`, permitindo embutir widgets em outras páginas.

4. **Sistema de notificações/alertas**
   - Próximos cupons a expirar, sincronizações recentes e contadores de notificações utilizam queries periódicas (ver `admin/dashboard.php`, `ajax/contador-notificacoes.php`).

---

## Segurança e boas práticas

- `includes/security.php` já fornece sanitização, rate limiting, CSRF tokens e headers endurecidos. Garanta que `Security::setSecurityHeaders()` seja chamado no bootstrap da aplicação.
- Ajuste `session_set_cookie_params` para habilitar cookies `Secure`, `HttpOnly` e `SameSite=Strict` (comentário já incluído no arquivo).
- O `.htaccess` sugerido bloqueia `.env`, `.sql`, `.log` e força HTTPS; aplique-o na raiz do projeto.
- Registre eventos críticos via `Security::logSecurityEvent()`; o arquivo `logs/security.log` deve ficar fora do alcance público (ou protegido via servidor).
- Substitua as chaves placeholders das APIs e nunca versiona credenciais reais.

---

## Logs e monitoramento

Arquivo | Conteúdo
--- | ---
`logs/security.log` | Entradas JSON com eventos de segurança (IP, user-agent, timestamp).
`logs/errors.log` | Erros genéricos da aplicação (preencher via `ini_set('error_log', ...)` se desejar).
`logs/api.log` | Acessos aos endpoints AJAX (ex.: `ajax/cupom.php`).

Você pode integrar esses logs a ferramentas externas (Papertrail, Loggly, etc.) ou configurar rota cron para limpeza periódica.

---

## Próximos passos sugeridos

1. Configurar variáveis de ambiente (por exemplo, usando `vlucas/phpdotenv`) para evitar credenciais fixas.
2. Converter `includes/apis.php` para consumir SDKs oficiais das plataformas ou filas assíncronas.
3. Adicionar testes automatizados (PHPUnit/Pest) para funções críticas (`uploadImagem`, sincronizações, autenticação).
4. Incrementar o front-end com bundlers (Vite/Rollup) e migração opcional para SPA em Vue/React, mantendo compatibilidade com os endpoints existentes.

---

Projeto mantido por **TTaTim**. Sugestões e melhorias são bem-vindas via pull requests.
