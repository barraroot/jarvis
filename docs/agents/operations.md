# Operação (implementada)

- Processos: web (php-fpm/octane), `queue:work redis --queue=tasks,default,low --max-jobs --max-time`, `reverb:start`, `schedule:work`. Detalhes no `README.md`.
- Agendamentos (`routes/console.php`): `jarvis:approvals:expire` (minuto), `jarvis:connectors:health-check` (minuto, intervalo dinâmico), `jarvis:retention:prune` (diário).
- Saúde: `GET /up` (`HealthController` + `App\Support\HealthCheck`) — DB, Redis, Reverb (`REVERB_HEALTH_URL` ou derivado de `broadcasting.connections.reverb.options`); `503` com `failed`. Registrado fora do grupo `web` (sessão em Redis não mascara a falha).
- Logs: canais `json` e `stderr_json` (Monolog `JsonFormatter`); contexto `user_id`/`conversation_id`/`task_id`/`message_id` via middleware de job `App\Jobs\Middleware\WithLogContext`, `agent_execution_id` via `AgentRunner`. Dev: `vendor/bin/sail artisan pail`.
- Performance: `Model::preventLazyLoading(! isProduction())`; eager loads obrigatórios em listagens/loops (`QueryBudgetTest`, trait `Tests\Concerns\CountsQueries`).
- `.env.example` declara toda chave `env()` de `config/` (`EnvExampleTest`); nada de host de desenvolvimento fixo em `config/`.
