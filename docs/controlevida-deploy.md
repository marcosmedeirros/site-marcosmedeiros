# Publicacao em marcosmedeiros.site

## Estado da entrega

O codigo fica no repositorio `marcosmedeirros/site-marcosmedeiros`, na rota `/controlevida/`. A hospedagem e o banco de producao precisam ser configurados antes de disponibilizar a rota. O portfolio da raiz permanece acessivel. O dominio antigo `.page` so deve ser aposentado depois de conferir a migracao completa.

## Instalacao usando o MySQL atual

1. Confirmar na Hostinger a conta que hospeda `.site`, a conta de `.page` e a possibilidade de usar o mesmo banco MySQL pela nova aplicacao. Usar as credenciais ja autorizadas por meio da configuracao privada do servidor. Nao adicionar credenciais ao repositorio ou ao README.
2. Fazer um backup nativo do banco completo antes de executar a migracao. O importador tambem salva uma copia JSON das tabelas que utiliza, fora do diretorio publico.
3. Configurar o deploy Git do repositorio novo e conferir o PHP com PDO MySQL e mbstring.
4. Na instalacao, definir no ambiente `CV_URL=https://marcosmedeiros.site/controlevida`, `CV_DSN=mysql:host=...;dbname=...;charset=utf8mb4`, `CV_DB_USER`, `CV_DB_PASSWORD`, `CV_ADMIN_EMAIL`, `CV_ADMIN_PASSWORD` e `CV_DATA_DIR` (caminho privado, fora de public_html). Executar `php scripts/setup.php` e remover as variaveis temporarias de senha do shell.
5. Executar `php scripts/migrate-legacy.php`. Por padrao ele le as tabelas antigas no mesmo banco configurado. Se os bancos estiverem separados, `CV_LEGACY_DSN`, `CV_LEGACY_DB_USER` e `CV_LEGACY_DB_PASSWORD` apontam a origem. `CV_LEGACY_USER_ID` padrao e 1, conforme o app anterior.

O importador preserva tabelas antigas. Copia financas e suas categorias/saldo inicial, tarefas/conclusoes, atividades, habitos, rotina, eventos, planos/historico de treino, corridas, alimentacao, notas, metas e anotacoes pessoais. Tabelas de outros produtos nao sao importadas. Os IDs de origem produzem IDs estaveis no destino. Lancamentos de `finances` ja referenciados por `fin_transactions.legacy_id` nao sao contados duas vezes. Registros ja presentes no destino nao sao sobrescritos por uma segunda execucao.

## Conferencia e troca

- Evitar novos registros no app antigo durante a copia final. Comparar contagens, valores por mes, saldo inicial e saldo acumulado com a origem.
- Conferir login correto/incorreto, API sem sessao (401), gravacao sem CSRF (403), arquivo de configuracao privado (403/404), ciclos de logout e login e recuperacao de registros arquivados.
- Conferir metadados OAuth, consentimento, ferramentas de leitura/escrita, revogacao e acesso HTTPS pelo chat.
- Conferir os arquivos `.htaccess` no servidor. O bloqueio por host `.page` no repositorio novo so vale se esse codigo for implantado naquele dominio. Nao presume bloquear o app antigo em outro public_html.
- Apos validar o destino, retirar o app antigo de acesso publico e redirecionar `.page` para `https://marcosmedeiros.site/controlevida/`, preservando o backup e o banco. Tambem retirar os endpoints e service worker antigos. Nao apenas sobrepor arquivos e deixar as APIs antigas publicas.

## Snapshot da API para previa

`node scripts/export-legacy.mjs --from=2025-01 --to=2026-12` exporta os meses indicados e um snapshot atual para `.private/legacy-snapshot.json`. O periodo e explicitamente limitado; ele nao comprova que nao existam registros fora desse intervalo. `php scripts/import-api-snapshot.php .private/legacy-snapshot.json` monta uma previa privada. A migracao direta do banco e a fonte definitiva para a troca de producao.

## Evolucao planejada

- Google Agenda: OAuth Google com escopo minimo, IDs externos e sincronizacao idempotente antes de sincronizacao bidirecional.
- Notificacoes: consentimento por dispositivo, assinatura Web Push e rotina de envio no servidor.
- Widgets nativos: avaliar aplicativo complementar/wrapper e extensoes especificas do sistema; uma PWA sozinha nao entrega widgets nativos.
- Outras contas: credenciais e refresh tokens guardados apenas no servidor, com revogacao individual e trilha de auditoria.
