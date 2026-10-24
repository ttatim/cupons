# Etapa 03 — Integrações de afiliados (em andamento)

## Entregue nesta subetapa (24/10/2026)
- Adaptador Hotmart com OAuth 2.0 `client_credentials` e consulta **somente leitura** ao sumário de vendas.
- Painel autenticado em `admin/integracoes-afiliados.php`, acessível pelo menu Integração > Afiliados.
- Indicação explícita de configuração versus conexão comprovada; Monetizze e Kiwify aparecem como **pendentes**, não conectadas.
- Transporte HTTPS com validação TLS e host fixo, sem redirects, timeout, limite de 1 MiB e mensagens de erro sem tokens ou payloads.
- Credenciais Hotmart lidas de variáveis de ambiente; nenhuma chave incluída no código, banco ou logs.
- Testes isolados com transporte simulado em `tests/test_affiliate_integrations.php`; adicionados ao CI PHP 8.3/8.4.
- Verificação TLS habilitada no cliente de APIs legado, que ainda requer revisão de autenticação e mapeamento de cada fornecedor.

## Configuração Hotmart
1. Na Hotmart, crie credenciais de API com acesso autorizado a vendas.
2. Configure no servidor (fora do repositório e do webroot) `HOTMART_CLIENT_ID`, `HOTMART_CLIENT_SECRET`, `HOTMART_BASIC_TOKEN` (token Basic fornecido pela Hotmart, com ou sem prefixo `Basic `).
3. Garanta PHP 8.3+, extensão cURL, certificados CA válidos e saída HTTPS para `api-sec-vlc.hotmart.com` e `developers.hotmart.com`.
4. Entre como administrador, abra Integração > Afiliados e use **Consultar sumário**. O painel não importa compradores nem grava comissões automaticamente.
5. Para validar sem credenciais: `php tests/test_affiliate_integrations.php`.

## Limitações e próximos trabalhos
- **Não há teste real contra a Hotmart nesta subetapa**: depende de credenciais válidas, escopo e conectividade do servidor.
- O cliente Hotmart é somente leitura; não sincroniza automaticamente produtos nem comissões com o banco.
- Conectores Kiwify e Monetizze e normalização de ofertas/comissões permanecem pendentes.
- A autenticação do sistema ainda não possui isolamento de vendedores; integrações por vendedor serão feitas no item 10. Até lá, não configurar segredos de diferentes vendedores no mesmo ambiente.
- A sincronização legada em `admin/api-sync.php` não foi migrada para o novo adaptador e seus resultados não devem ser considerados prova de conexão.
- Nenhum dado real de produção deve ser importado para a instalação limpa de testes.

## Documentação dos fornecedores
- Hotmart autenticação: https://developers.hotmart.com/docs/pt-BR/start/app-auth/
- Hotmart sumário: https://developers.hotmart.com/docs/pt-BR/v1/sales/sales-summary/
- Kiwify API: https://ajuda.kiwify.com.br/pt-br/article/como-funciona-a-api-da-kiwify-1iosjhu/
- Monetizze API: https://help.monetizze.com.br/books/integracoes/page/api-monetizze

## Status do item 3
**Parcial**. O item não deve ser dado como concluído antes de implementar e testar os conectores adicionais e os fluxos previstos.
