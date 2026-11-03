# Arquitetura (implementada)

## Backend (Laravel 13, PHP 8.5)

- **API v1** (`routes/api.php`, `App\Http\Controllers\Api\V1\*`): SPA autenticada por cookie Sanctum (`statefulApi`). Recursos: `auth`, `conversations` (+ `messages`), `memories`, `tasks`, `approvals`, `notifications`, `connectors` (+ `tools`), `agents`, `agent-executions`, `activity`, `preferences`, `ai/models`. Ownership por Policies (`App\Policies`, trait `AuthorizesOwner`) e `Gate::authorize()` (o `Controller` base é vazio).
- **AI Brain**: `IntentRouter` (classificação + heurística) → resposta direta via `BrainClient` (streaming, fallback de provedores, `UsageRecorder`) ou agente via `AgentRunner`.
- **Agent Engine**: `AgentRuntimeFactory` monta `App\Ai\Agents\RuntimeAgent` (subclasse de `AnonymousAgent` com `maxSteps()` = limite de tool calls + 2) com instruções + política de dados não confiáveis + tools autorizadas (`ToolAdapter`, `AgentTool` para `delegate.<slug>`). `DelegationService` aplica profundidade, ciclo e tempo restante.
- **Tool Engine**: `ToolExecutor` (autorização, JSON Schema, aprovação, timeout via `pcntl_alarm`, `tool_executions`, eventos `tool.*`, auditoria `tool_executed` com `approval_id`/`approved_by`). Handlers: internos (`App\Tools\Internal`), REST/webhook (`HttpToolHandler`), MCP (`McpToolHandler` sobre `McpClient`/`laravel/mcp`).
- **Tarefas longas**: `TaskService` + job `RunTask` na fila dedicada `tasks` (`RunTask::QUEUE`); aprovação estaciona em `waiting_approval` e o `ApprovalService` re-despacha; cancelamento cooperativo por chave em cache.
- **Memória**: `MemoryService`/`MemoryRetriever` com pgvector (índice HNSW `memory_embeddings_embedding_hnsw`), hipóteses via `ExtractMemoryHypotheses` (fila `low`).
- **Resultados estruturados**: `StructuredResultExtractor` (`StructuredAnonymousAgent`) + `ResultNormalizer`/`ResultValidator`.
- **Tempo real**: eventos `ShouldBroadcast` no canal privado `user.{id}` (Reverb).
- **Admin**: Filament 5 em `/admin` (gate `admin`), recursos de IA, operação e sistema; página de configurações (`Settings::RANGES`) e dashboard de consumo (`UsageReport`).

## Frontend (Vue 3 + Pinia + Tailwind 4, PWA)

- `resources/js`: `pages`, `layouts` (HUD), `components/{hud,results,ui}`, `stores` (um por domínio: conversations, messages, tasks, approvals, memories, connectors, agents, activity…), `lib` (http client `fetch`, Echo, voz), `router`, `i18n`.
- Service worker em `public/sw.js` (vite-plugin-pwa); manifest servido por `PwaManifestController`.
- Testes em `tests/js` (Vitest + happy-dom).

## Dados

PostgreSQL 18 + pgvector. Tabelas de lookup (`*_statuses`, `audit_actions`, …) com `LookupModel::idFor()` em cache; nunca usar ids fixos. Ver `.spec/init/database-schema.md`.
