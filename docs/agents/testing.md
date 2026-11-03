# Testes (convenções do projeto)

- Runner: `vendor/bin/sail test --filter=NomeDoTeste` (PHPUnit). Frontend: `vendor/bin/sail npm run test` / `typecheck`.
- Testes de feature usam `RefreshDatabase` + `Tests\Concerns\SeedsLookups` (`setUpSeedsLookups()`); `TestCase` limpa cache e `withoutVite()`.
- SDK de IA: `Ai::fakeAgent(RuntimeAgent::class, …)` para execuções de agente; `AnonymousAgent::class` só para o caminho direto (`BrainClient`); `StructuredAnonymousAgent::class` para a extração de resultados. Closures do fake recebem o prompt como **string**.
- Broadcast: `Event::fake()` e inspeção de `broadcastOn()`; para ordem de eventos use `config(['broadcasting.default' => 'null'])` + `Event::listen()`.
- Sessão Sanctum real: header `Referer: http://localhost:5173/...`; após trocar de usuário entre fluxos de API e Filament: `auth()->forgetGuards()` + `shouldUse('web')`.
- MCP: `Tests\Concerns\FakesMcpServer` (Http::fake). Health: mock de `Redis::connection()` + `Http::fake` para Reverb.
- Livewire/Filament: crie os registros **antes** de `Livewire::test(ListX::class)` para ações de tabela.
