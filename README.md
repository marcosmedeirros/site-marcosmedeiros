# Controle Vida

Central pessoal em `https://marcosmedeiros.site/controlevida/`. O site profissional continua na raiz. Aplicacao PHP + PDO (MySQL em producao, SQLite na previa local), interface sem build e PWA instalavel.

## O que esta implementado

- Hoje: tarefas pendentes, agenda, tarefas da casa, habitos, mini-habitos, resumo financeiro, treinos e refeicoes.
- CRUD de tarefas, eventos, habitos, lancamentos, treinos, refeicoes, notas e metas. Repeticoes diarias, semanais e mensais. Conclusoes por data, historico e arquivo recuperavel.
- Financas em centavos inteiros, categorias, filtros mensais, CSV e exportacao privada de dados.
- Agenda mensal com exportacao ICS. Sincronizacao automatica com Google Agenda, push e widgets nativos de celular ficam para a proxima etapa; nenhum deles e apresentado como conectado.
- Autenticacao por sessao e senha com hash, CSRF, limite de tentativas, validacao no servidor, revisoes para evitar sobrescritas concorrentes e auditoria de operacoes.
- MCP HTTP com OAuth, PKCE S256, consentimento, permissoes de leitura/escrita, tokens curtos, rotacao e revogacao de conexoes.

## Dados e privacidade

O navegador guarda os dados pessoais apenas em memoria. O service worker armazena assets e uma pagina offline neutra; nao armazena respostas de API ou paginas autenticadas. Sem conexao, alteracoes sao recusadas e exibidas como nao salvas. Instalar a PWA nao cria widgets nativos do sistema operacional.

Senhas, backups e credenciais nao fazem parte do Git. `.controlevida.local.php` e gerado pelo instalador local/CLI, ignorado pelo Git e bloqueado pelo Apache. O diretorio de dados deve ficar fora do `public_html`. O banco usa tabelas `cv_*`, permitindo reaproveitar o MySQL existente sem renomear ou remover as tabelas antigas.

## Desenvolvimento

Requisitos: PHP 8.0+ com PDO, pdo_sqlite para testes, pdo_mysql para producao e mbstring; Node 20+ para ferramentas de desenvolvimento. Em producao, usar uma versao PHP suportada pelo provedor.

1. `npm ci` e `npm run vendor` para atualizar a copia local do Lucide. O arquivo e a licenca ja sao versionados, portanto Node nao e necessario na hospedagem.
2. Definir `CV_ADMIN_EMAIL`, `CV_ADMIN_PASSWORD` e `CV_URL` (por exemplo `http://127.0.0.1:8873/controlevida`) apenas no ambiente do processo de instalacao.
3. Executar `php scripts/setup.php` uma vez. O instalador recusa sobrescrever uma configuracao existente.
4. Executar `php -S 127.0.0.1:8873 -t . scripts/router.php`.
5. `npm test`: testa autenticacao, CSRF, conflitos de revisao, importacao, PKCE, repeticao de codigo OAuth, rotacao/revogacao e interoperabilidade usando o SDK oficial MCP. `PHP_BIN` permite selecionar o executavel PHP.

## Publicacao e migracao

Consultar [docs/controlevida-deploy.md](docs/controlevida-deploy.md). A instalacao local e a importacao de um snapshot para previa nao constituem publicacao ou migracao do banco de producao.

## MCP

Endereco de producao: `https://marcosmedeiros.site/controlevida/mcp.php`. As ferramentas consultam registros, criam/atualizam, concluem e arquivam. Alteracoes usam a mesma validacao e o mesmo banco da interface. Informacoes de alimentacao sao descritivas e qualitativas; nao ha calculo de calorias, metas de peso ou prescricao de medicamento.

Depois da publicacao e verificacao do HTTPS, conectar o servidor como plugin MCP privado com OAuth e DCR e autorizar a propria conta. A conexao precisa ser habilitada no chat; criar o codigo do servidor nao conecta automaticamente uma conversa. Seguir o fluxo disponivel na conta em [Criar servidor MCP personalizado](https://developers.openai.com/api/docs/guides/custom-mcp-server). OAuth segue a [documentacao de autenticacao da OpenAI](https://developers.openai.com/plugins/build/auth) e a [especificacao MCP](https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization).

Novas integracoes devem passar por adaptadores no servidor e registrar a origem e a referencia externa. Nao ligar clientes diretamente ao MySQL nem distribuir a senha da conta como chave de integracao.
