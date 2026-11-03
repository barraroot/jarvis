# J.A.R.V.I.S. — Operação

Personal AI Operating System: SPA/PWA (Vue 3) + API Laravel 13, painel Filament em `/admin`, IA via `laravel/ai`, tempo real via Reverb, memória vetorial em PostgreSQL + pgvector, filas e cache em Redis.

Este documento cobre como rodar o JARVIS em desenvolvimento (Sail) e em produção: processos, variáveis de ambiente, deploy, observabilidade e segurança.

## Desenvolvimento (Laravel Sail)

```bash
cp .env.example .env
vendor/bin/sail up -d
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed          # lookups, settings, admin, agentes
vendor/bin/sail npm install && vendor/bin/sail npm run dev
```

O `compose.yaml` sobe `laravel.test` (app), `pgsql` (pgvector), `redis`, `reverb`, `queue`, `scheduler`, `mailpit` e `rustfs` (S3 local).

- Testes: `vendor/bin/sail test --filter=NomeDoTeste` (backend), `vendor/bin/sail npm run test` e `vendor/bin/sail npm run typecheck` (frontend).
- Formatação: `vendor/bin/sail bin pint --dirty --format agent`.
- Logs em tempo real (Laravel Pail): `vendor/bin/sail artisan pail` — filtre com `--filter="task_id"` ou `--user=<id>`.

## Processos necessários em produção

| Processo | Comando | Observações |
|---|---|---|
| Web | `php-fpm` (atrás de Nginx) **ou** `php artisan octane:start` | Serve SPA, API (`/api/v1`), Filament (`/admin`) e `/up`. |
| Workers | `php artisan queue:work redis --queue=tasks,default,low --tries=3 --timeout=960 --max-jobs=500 --max-time=3600` | `tasks` = tarefas longas (`RunTask`), `default` = chat/streaming, `low` = hipóteses de memória. |
| WebSocket | `php artisan reverb:start --host=0.0.0.0 --port=8080` | Atrás de proxy TLS (`wss://`). |
| Scheduler | `php artisan schedule:work` (ou cron `* * * * * php artisan schedule:run`) | Expira aprovações, health-check de conectores, retenção diária. |

### Reciclagem de workers

`RunTask` sempre vai para a fila dedicada `tasks` (`RunTask::QUEUE`), isolada do chat. Para conter vazamento de memória e aplicar deploys sem reiniciar manualmente:

- `--max-jobs=500` encerra o worker após 500 jobs; `--max-time=3600` após 1 hora. O supervisor (systemd/Supervisor/Kubernetes) sobe outro.
- `--timeout=960` fica acima do maior timeout de tarefa configurado (900 s padrão dos agentes) para o job marcar a tarefa como falha antes de ser morto.
- Em cargas maiores, separe um pool só para tarefas: `queue:work redis --queue=tasks --max-jobs=100 --max-time=1800` e outro com `--queue=default,low`.
- Após cada deploy: `php artisan queue:restart` e `php artisan reverb:restart`.

## Variáveis de ambiente

O `.env.example` declara **todas** as chaves lidas por `config/*.php` (as opcionais aparecem comentadas; `EnvExampleTest` garante isso). As essenciais em produção:

| Grupo | Chaves |
|---|---|
| Aplicação | `APP_NAME`, `APP_ENV=production`, `APP_KEY`, `APP_DEBUG=false`, `APP_URL`, `APP_LOCALE=pt_BR` |
| Banco | `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (extensão `vector` obrigatória) |
| Redis | `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis` |
| Sessão / SPA | `SESSION_SECURE_COOKIE` (padrão `true` quando `APP_ENV=production`), `SESSION_SAME_SITE=lax`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS` |
| Reverb | `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, `REVERB_HEALTH_URL`, `VITE_REVERB_*` (no build do frontend) |
| Armazenamento (S3 real) | `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`; **remova** `AWS_ENDPOINT` e use `AWS_USE_PATH_STYLE_ENDPOINT=false` fora do RustFS local |
| Administrador inicial | `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` (mín. 12 caracteres) |
| Conectores MCP | `MCP_ALLOW_PRIVATE_HOSTS=false` (só `true` em desenvolvimento), `MCP_TIMEOUT_SECONDS` |
| Logs | `LOG_CHANNEL=stack`, `LOG_STACK=json` (arquivo diário JSON) ou `stderr_json` (containers), `LOG_LEVEL=info`, `LOG_DAILY_DAYS` |

Chaves de provedores de IA são cadastradas no painel (`/admin`, criptografadas no banco); as variáveis `*_API_KEY` são apenas fallback do `config/ai.php`.

Nada de desenvolvimento fica fixo em `config/`: origens CORS e domínios stateful do Sanctum vêm de `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS` e `SANCTUM_STATEFUL_DOMAINS`.

## Deploy

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build                     # gera public/build e public/sw.js
php artisan migrate --force
php artisan db:seed --class=LookupSeeder --force   # idempotente: tabelas de lookup
php artisan optimize                         # config, rotas, eventos e views em cache
php artisan filament:optimize
php artisan queue:restart
php artisan reverb:restart
```

Primeira instalação (uma vez): `php artisan db:seed --force` cria lookups, configurações padrão, o administrador (`ADMIN_*`), a ferramenta de memória e os agentes padrão. Depois disso, novos agentes/ferramentas são geridos no painel; `php artisan jarvis:tools:sync-internal` e `php artisan jarvis:agents:sync-delegation` reconciliam o catálogo.

### `compose.yaml` de produção (opcional)

O `compose.yaml` do Sail é de desenvolvimento (volumes montados, `mailpit`, `rustfs`, Vite na porta 5173). Para usar Docker Compose em produção, crie um `compose.production.yaml` a partir dele:

- imagem construída com o código (sem o volume `.:/var/www/html`) e `APP_ENV=production`;
- remova `mailpit`, `rustfs` e a porta `5173`; use SMTP e S3 reais;
- mantenha `pgsql` (imagem `pgvector/pgvector`) e `redis` só se não usar serviços gerenciados;
- `queue`, `reverb` e `scheduler` com os comandos da tabela de processos acima;
- `restart: unless-stopped` em todos os serviços e healthcheck HTTP em `/up`.

Suba com `docker compose -f compose.production.yaml up -d`.

## Observabilidade

- **Logs estruturados**: canais `json` (diário, `storage/logs/laravel-*.json`) e `stderr_json`. Jobs adicionam `user_id`, `conversation_id`, `task_id`/`message_id` ao contexto (`App\Jobs\Middleware\WithLogContext`) e o `AgentRunner` adiciona `agent_execution_id` durante cada execução.
- **Saúde**: `GET /up` verifica PostgreSQL, Redis e Reverb e responde `200` (`status: ok`) ou `503` com `failed: [...]`; o motivo da falha vai apenas para o log.
- **Consumo e latência de IA**: painel `/admin` → Consumo (custo, tokens, latência média e p95 por período, por usuário e por modelo).
- **Auditoria**: `php artisan jarvis:audit:report --from=2026-11-01 --to=2026-11-30 --output=auditoria.csv`.

## Segurança

- Rate limiting: `api` 120/min por usuário, `login` 5/min por e-mail+IP, `messages` conforme configuração, `approvals` 30/min, `connectors.resync` 6/min por conector — `429` padronizado com `Retry-After`.
- Cabeçalhos: CSP na SPA (self + Reverb + Bunny Fonts), `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `X-Frame-Options: DENY`.
- Conteúdo de ferramentas, conectores, páginas e documentos é dado não confiável (`App\Support\UntrustedContent`); ferramentas de escrita exigem aprovação e são auditadas com o aprovador.
- Segredos (chaves de IA, credenciais de conectores/ferramentas) ficam criptografados e nunca saem do backend.
