# PROJETO J.A.R.V.I.S. — PERSONAL AI OPERATING SYSTEM

## 1. Missão

Você atuará como arquiteto de software, engenheiro full-stack sênior e especialista em sistemas de inteligência artificial e agentes autônomos.

Sua missão é projetar e implementar uma plataforma pessoal de inteligência artificial chamada J.A.R.V.I.S., inspirada na experiência visual e funcional demonstrada no vídeo de referência fornecido.

O objetivo não é construir apenas um chatbot. Precisamos criar um assistente pessoal inteligente, com capacidade de conversar por voz e texto, raciocinar, utilizar ferramentas, executar tarefas, gerenciar agentes especializados, armazenar memórias e se integrar a sistemas externos.

A experiência deve transmitir a sensação de estar interagindo com um assistente inteligente de ficção científica, mantendo uma arquitetura de software real, escalável, modular e preparada para produção.

**Nome:** J.A.R.V.I.S.

**Significado:** Just A Rather Very Intelligent System.

**Conceito:** Personal AI Operating System.

## 2. Stack tecnológica obrigatória

Backend:
- Laravel 13.
- PHP 8.4 ou compatível com os requisitos do Laravel 13.
- Laravel AI SDK para integração com modelos de IA.
- Laravel Sanctum para autenticação da API.
- Laravel Reverb para comunicação em tempo real.
- Laravel Queues para processamento assíncrono.
- FilamentPHP 5 para administração.

Frontend:
- Vue 3.
- TypeScript.
- Composition API.
- Vite.
- Vue Router.
- Pinia.
- PWA com instalação em desktop e dispositivos móveis.
- Interface responsiva.
- WebSocket para comunicação em tempo real.

Infraestrutura:
- PostgreSQL.
- pgvector para armazenamento vetorial.
- Redis para cache, filas e controle de estado.
- Docker/Sail para ambiente local.
- MinIO ou S3 para arquivos e áudios.
- Mailpit para testes de e-mail.

Integrações:
- Modelos OpenAI.
- Anthropic.
- Outros provedores compatíveis com a arquitetura do Laravel AI SDK.
- Servidores MCP.
- Webhooks.
- APIs externas.

Não acople o sistema a um único fornecedor de modelos de IA.

A implementação deve permitir alternar entre provedores e modelos sem reconstruir a lógica dos agentes.

## 3. Referência visual e experiência

Utilize o vídeo de referência fornecido pelo usuário como referência principal de interface e comportamento.

Antes de implementar a interface:

1. Analise os principais momentos do vídeo.
2. Identifique os elementos visuais e os estados do assistente.
3. Documente os componentes observados.
4. Crie referências visuais em `.spec/init/design/`.
5. Utilize essas referências durante o planejamento das fases.

A identidade visual deve seguir estas características:

- Interface dark mode.
- Fundo quase preto, com tons azul-marinho.
- Elementos luminosos em ciano e azul.
- Detalhes discretos em roxo.
- Linhas finas e bordas tecnológicas.
- Transparências e efeitos de profundidade.
- Animações suaves.
- Tipografia moderna e futurista.
- Visual semelhante a interfaces HUD de ficção científica.

Não criar um dashboard administrativo convencional.

A interface principal deve transmitir a sensação de um sistema operacional pessoal inteligente.

### 3.1. Núcleo visual JARVIS

Criar um componente central chamado `JarvisCore`.

Ele será o principal elemento visual da aplicação.

Deve possuir:

- Anéis circulares concêntricos.
- Partículas e pequenos indicadores.
- Núcleo luminoso central.
- Animações contínuas e suaves.
- Indicadores de atividade.
- Resposta visual à interação por voz.
- Mudança de intensidade conforme o processamento.
- Efeitos de expansão durante a fala.
- Efeitos de escuta durante a captura de áudio.

O núcleo deve ter diferentes estados:

- IDLE: aguardando interação.
- LISTENING: capturando áudio.
- THINKING: processando solicitação.
- SPEAKING: reproduzindo resposta.
- EXECUTING: executando ferramentas ou agentes.
- SLEEPING: modo descanso.
- ERROR: falha de processamento.
- DISCONNECTED: indisponível.

Cada estado deve ter animação, intensidade e comportamento próprios.

Criar as animações preferencialmente com SVG, CSS e Canvas, evitando dependências gráficas desnecessariamente pesadas.

Respeitar `prefers-reduced-motion` e reduzir efeitos em dispositivos de menor capacidade.

### 3.2. Layout principal

Criar a interface de trabalho com:

**Cabeçalho superior:**
- Logotipo J.A.R.V.I.S.
- Indicador do estado atual.
- Relógio em tempo real.
- Data atual.
- Botão dormir/acordar.
- Controle do microfone.
- Indicador de notificações.
- Indicador de conectores.
- Acesso às configurações.

**Painel lateral esquerdo:**
- Núcleo JARVIS em versão compacta.
- Indicador de atividade.
- Lista de agentes ativos.
- Tarefas em processamento.
- Histórico de execuções.
- Status de ferramentas.
- Acompanhamento das tarefas.

**Área central:**
- Conversação com o assistente.
- Mensagens do usuário.
- Mensagens do JARVIS.
- Indicadores de processamento.
- Resultados estruturados.
- Painéis dinâmicos.
- Exibição de conteúdo retornado por ferramentas.
- Histórico de conversas.

**Rodapé de interação:**
- Campo de texto.
- Botão de enviar.
- Botão de ativação por voz.
- Indicador de captura de áudio.
- Atalhos de comandos.

### 3.3. Responsividade

Desktop:
- Experiência HUD completa.
- Núcleo e painel lateral visíveis.
- Área central ampla.

Tablet:
- Painéis adaptáveis.
- Possibilidade de ocultar o painel lateral.

Mobile:
- Núcleo adaptado.
- Navegação inferior ou menu compacto.
- Conversação como elemento principal.
- Acesso rápido ao microfone.
- Agentes e tarefas em painéis expansíveis.

A PWA deverá permitir instalação, funcionamento em diferentes tamanhos de tela e cache dos recursos estáticos.

Não fingir funcionamento offline da IA: apresentar claramente o estado de conexão quando os serviços estiverem indisponíveis.

## 4. Arquitetura funcional

Organizar a aplicação em módulos independentes.

### 4.1. AI Brain — cérebro central

Criar um serviço responsável pela inteligência central do JARVIS.

Responsabilidades:

- Receber solicitações do usuário.
- Identificar a intenção.
- Consultar memórias relevantes.
- Selecionar o modelo adequado.
- Identificar ferramentas disponíveis.
- Decidir quando utilizar agentes especializados.
- Coordenar execuções.
- Consolidar resultados.
- Produzir respostas.
- Manter o contexto das conversas.

Criar uma abstração de provedores de IA.

Permitir:
- Configuração de múltiplos provedores.
- Seleção de modelos.
- Modelo padrão.
- Modelo por agente.
- Fallback entre provedores.
- Controle de consumo e custos.
- Registro de execução e latência.

Nunca expor chaves de API no frontend.

### 4.2. Memory Engine — memória persistente

Criar um sistema de memória organizado em camadas.

Memória de curto prazo:
- Contexto da conversa atual.
- Últimas mensagens.
- Estado das tarefas.
- Contexto temporário.

Memória de longo prazo:
- Preferências do usuário.
- Informações explicitamente autorizadas para armazenamento.
- Projetos conhecidos.
- Contextos recorrentes.
- Aprendizados confirmados.

Memória semântica:
- Embeddings.
- pgvector.
- Busca por similaridade.
- Recuperação contextual.
- RAG.

Requisitos:
- Identificar informações potencialmente úteis.
- Diferenciar fatos confirmados de hipóteses.
- Evitar salvar automaticamente dados sensíveis.
- Permitir edição e exclusão das memórias pelo usuário.
- Associar origem e data às memórias.
- Evitar duplicações.
- Recuperar apenas informações relevantes.

### 4.3. Agent Engine — sistema de agentes

Criar uma arquitetura de agentes especializados.

Cada agente deve possuir:

- Nome.
- Descrição.
- Objetivo.
- Instruções próprias.
- Modelo de IA.
- Ferramentas autorizadas.
- Limites de execução.
- Permissões.
- Histórico.
- Estado de execução.

Agentes iniciais:

1. Personal Assistant: assistente geral.
2. Research Agent: pesquisas e investigação.
3. Developer Agent: programação e análise técnica.
4. Automation Agent: execução de automações.
5. Knowledge Agent: gestão de conhecimento e RAG.
6. Productivity Agent: tarefas, lembretes e organização.
7. Communication Agent: elaboração e gerenciamento de comunicações.

Criar suporte para cadastrar novos agentes pela interface administrativa.

O sistema deve permitir que um agente delegue tarefas a outro, respeitando limites de profundidade, permissões e tempo de execução.

Não implementar uma arquitetura de agentes que execute tarefas sem controle ou supervisão.

### 4.4. Tool Engine — ferramentas

Implementar uma camada centralizada para ferramentas.

As ferramentas poderão ser:

- Ferramentas internas Laravel.
- Ferramentas MCP.
- APIs REST externas.
- Serviços internos.
- Integrações via webhooks.

Cada ferramenta deverá possuir:
- Nome e descrição.
- Identificador.
- Parâmetros.
- Schema de entrada.
- Schema de saída.
- Tempo limite.
- Controle de acesso.
- Registro de execução.
- Tratamento de erros.

Implementar descoberta de ferramentas e integração com servidores MCP.

Criar gerenciamento de conectores, incluindo:
- URL e identificação do servidor.
- Status de conexão.
- Ferramentas disponíveis.
- Última sincronização.
- Erros.
- Permissões.

O sistema deverá suportar ferramentas de leitura e ferramentas que realizem alterações.

Ações sensíveis, irreversíveis ou externas deverão exigir autorização explícita do usuário antes da execução.

Nunca permitir que o modelo execute comandos arbitrários no servidor diretamente.

### 4.5. Voice Engine — interação por voz

A experiência de voz é um dos recursos centrais do JARVIS.

Implementar:

- Captura de áudio pelo navegador.
- Reconhecimento de fala.
- Speech-to-text.
- Text-to-speech.
- Resposta falada.
- Controle de reprodução.
- Interrupção da fala.
- Detecção de silêncio.
- Histórico de interações.
- Seleção de voz.
- Configuração de idioma.
- Indicadores visuais de captura e reprodução.

Utilizar inicialmente as APIs disponíveis no navegador e provedores externos configuráveis.

Preferir APIs de streaming ou comunicação em tempo real quando disponíveis.

Planejar suporte a conversação de baixa latência, sem obrigar o sistema inteiro a depender de um fornecedor específico.

Criar estados visuais sincronizados com:
- Microfone ativo.
- Áudio sendo enviado.
- Reconhecimento em andamento.
- Resposta sendo gerada.
- Áudio sendo reproduzido.

Permitir interação por voz e texto simultaneamente.

O usuário poderá interromper uma resposta falada e enviar uma nova solicitação.

### 4.6. Real-time Engine

Utilizar Laravel Reverb e broadcasting.

Eventos necessários:

- conversation.message.created
- assistant.thinking
- assistant.state.changed
- assistant.response.chunk
- assistant.response.completed
- agent.started
- agent.progress
- agent.completed
- agent.failed
- tool.started
- tool.completed
- tool.failed
- connector.status.changed
- notification.created

O frontend deverá receber atualizações sem precisar realizar polling constante.

Implementar autenticação e autorização dos canais privados.

### 4.7. Task Engine — tarefas e execuções

Permitir que o JARVIS receba tarefas demoradas e continue executando-as em segundo plano.

Exemplos:
- Pesquisar informações.
- Consultar sistemas externos.
- Executar uma sequência de ferramentas.
- Processar documentos.
- Gerar relatórios.
- Acionar agentes especializados.

Cada tarefa possuirá:
- Identificador.
- Solicitação original.
- Agente responsável.
- Etapas.
- Status.
- Progresso.
- Logs.
- Resultado.
- Data de início.
- Data de conclusão.

Estados:
- Pending.
- Queued.
- Running.
- Waiting approval.
- Completed.
- Failed.
- Cancelled.

Permitir acompanhar as tarefas em tempo real pelo painel lateral.

### 4.8. Result Engine — resultados estruturados

Não apresentar tudo como texto simples.

Criar componentes capazes de exibir:
- Respostas textuais.
- Listas.
- Tabelas.
- Relatórios.
- Resultados de pesquisa.
- Cards de tarefas.
- Dados estruturados.
- Status de execução.
- Conteúdo de documentos.

O backend deverá retornar formatos estruturados e validados, sem permitir a execução de HTML ou JavaScript arbitrário fornecido pelo modelo.

## 5. Administração e configurações

Implementar um painel administrativo utilizando FilamentPHP 5.

Recursos:

- Usuários.
- Provedores de IA.
- Modelos disponíveis.
- Configurações do cérebro central.
- Agentes.
- Ferramentas.
- Conectores MCP.
- Memórias.
- Conversas.
- Tarefas.
- Logs.
- Consumo de tokens.
- Custos estimados.
- Permissões.
- Configurações de voz.

Permitir ativar e desativar provedores, ferramentas e agentes sem modificar código.

Segredos deverão ser armazenados de maneira segura, criptografados e nunca apresentados integralmente na interface.

## 6. Segurança

A aplicação deverá ser desenvolvida considerando segurança desde o início.

Requisitos obrigatórios:

- Autenticação segura.
- Isolamento entre usuários.
- Autorização por ferramenta.
- Controle de acesso por agente.
- Criptografia de credenciais.
- Rate limiting.
- Validação de entradas.
- Auditoria de execuções.
- Proteção contra prompt injection.
- Limites de chamadas e consumo.
- Timeout nas ferramentas.
- Proteção contra execução recursiva de agentes.
- Isolamento de execução de tarefas.
- Proteção de canais WebSocket.

Tratar conteúdo de documentos, páginas web e ferramentas externas como dados não confiáveis.

Exigir autorização do usuário antes de ações financeiras, exclusões, envio de mensagens, alterações em sistemas externos ou outras operações com efeitos reais.

Criar logs auditáveis com identificação de quem autorizou cada ação.

## 7. Banco de dados

Planejar entidades para:

- users
- conversations
- messages
- ai_providers
- ai_models
- agents
- agent_tools
- tools
- mcp_servers
- mcp_tool_definitions
- memories
- memory_embeddings
- tasks
- task_steps
- tool_executions
- approvals
- notifications
- user_preferences
- usage_records
- audit_logs

Utilizar PostgreSQL como banco principal e pgvector para os dados vetoriais.

Não criar tabelas desnecessárias ou estruturas excessivamente genéricas sem justificativa.

Documentar relacionamentos, índices, constraints e estratégia de retenção.

## 8. Experiência do usuário

O comportamento deverá ser natural e contextual.

Exemplo:

Usuário: "JARVIS, procure no meu ERP os clientes com pagamentos atrasados."

JARVIS:
1. Reconhece a solicitação.
2. Identifica que precisa utilizar uma ferramenta do ERP.
3. Seleciona o agente e a ferramenta adequados.
4. Valida a autorização.
5. Executa a consulta.
6. Exibe o andamento da tarefa.
7. Apresenta os resultados estruturados.
8. Oferece ações adicionais, quando pertinentes.

Outro exemplo:

Usuário: "JARVIS, lembre que estou desenvolvendo um projeto chamado X."

O assistente deverá compreender a intenção, solicitar esclarecimento quando necessário e permitir registrar a informação em sua memória.

## 9. Qualidade e testes

Criar testes automatizados para:

- Autenticação.
- Conversações.
- Seleção de modelos.
- Memórias.
- Busca vetorial.
- Execução de ferramentas.
- Agentes.
- Delegação.
- Autorizações.
- Tarefas assíncronas.
- Eventos Reverb.
- Integração com provedores.
- Responsividade.
- Estados da interface.
- Instalação PWA.

Utilizar mocks para serviços externos nos testes.

Nenhuma funcionalidade poderá ser considerada concluída apenas porque a interface foi criada.

Cada funcionalidade deverá possuir testes e critérios objetivos de aceitação.

## 10. Diretrizes obrigatórias de desenvolvimento

- Código organizado e modular.
- Controllers enxutos.
- Services para regras de negócio.
- Jobs para operações demoradas.
- Events e Listeners para comunicação desacoplada.
- Policies e Gates para autorização.
- DTOs e validação de contratos.
- Interfaces para integrações externas.
- Tipagem adequada no TypeScript.
- Componentes Vue reutilizáveis.
- Responsabilidade única.
- Documentação técnica atualizada.

Não criar um monólito desorganizado.

Começar com um monólito modular Laravel, evitando microserviços prematuros.

Manter o frontend Vue desacoplado da camada de inteligência.

Não simular funcionalidades que ainda não foram integradas.

Quando uma integração depender de credenciais externas, implementar a abstração, configuração, tratamento de falhas e testes com mocks, deixando claro o que depende da configuração real.

## 11. Utilização obrigatória do Beer & Code Harness

Repositório:

https://github.com/beerandcodeteam/beer-and-code-harness.git

O Harness deverá controlar o processo de especificação, planejamento e execução.

Não ignorar suas etapas.

### Etapa 1 — Diagnóstico

Antes de implementar:

- Verificar o repositório atual.
- Identificar se o projeto já existe.
- Inspecionar arquivos e dependências.
- Verificar instalação do Harness.
- Identificar comandos disponíveis.
- Verificar se existem especificações anteriores.

Não sobrescrever arquivos existentes sem avaliar o impacto.

### Etapa 2 — Inicialização

Utilizar a cadeia `/bc-harness:init`.

Produzir:

- `.spec/init/project-description.md`
- `.spec/init/user-stories.md`
- `.spec/init/database-schema.md`
- `.spec/init/project-phases.md`

Executar novamente o comando `/bc-harness:init` para avançar um passo de cada vez, respeitando a sequência do Harness.

Criar também referências de design em `.spec/init/design/`, quando disponíveis.

As especificações deverão refletir a stack e os requisitos definidos neste documento.

### Etapa 3 — Planejamento

Utilizar `/bc-harness:plan` para transformar a arquitetura e os requisitos em especificações executáveis.

Organizar os recursos em funcionalidades independentes.

Produzir:

- SPEC.md
- PLAN.md
- PHASES.md
- Contratos de API quando necessários.

Resolver ambiguidades, respeitar os checkpoints humanos e validar critérios de aceitação antes da execução.

### Etapa 4 — Contexto de IA

Utilizar `/bc-harness:ai-context` quando houver código implementado suficiente para documentar a realidade do projeto.

Gerar e manter:
- AGENTS.md
- CLAUDE.md
- docs/agents/architecture.md
- docs/agents/tech_stack.md
- docs/agents/coding_guidelines.md
- docs/agents/domain_rules.md
- docs/agents/api_contracts.md
- docs/agents/data_model.md
- docs/agents/dependencies.md
- docs/agents/project_overview.md

Não documentar como implementado algo que ainda seja apenas planejado.

### Etapa 5 — Execução

Após aprovação das especificações e fases:

Utilizar `ralph.sh` conforme a documentação do Harness.

Executar as fases com validação de testes e verificação independente.

Cada fase deverá possuir:
- Objetivo.
- Arquivos envolvidos.
- Tarefas.
- Critérios de aceitação.
- Testes.
- Dependências.

Não considerar uma fase concluída sem passar pelos mecanismos de verificação.

Utilizar Git para manter histórico de implementação por fase, conforme o funcionamento do Harness.

## 12. Organização sugerida das fases

A decomposição definitiva deverá ser realizada pelo Harness, mas considere os seguintes macroblocos:

**Fase 1 — Fundação**
- Estrutura Laravel.
- Estrutura Vue.
- PostgreSQL.
- Redis.
- Docker.
- Autenticação.
- PWA.
- Reverb.
- Contexto de desenvolvimento.

**Fase 2 — Interface JARVIS**
- Layout HUD.
- JarvisCore.
- Estados animados.
- Dashboard.
- Chat.
- Responsividade.
- Histórico.

**Fase 3 — Cérebro de IA**
- Provedores.
- Modelos.
- Conversas.
- Contexto.
- Respostas.
- Streaming.

**Fase 4 — Voz**
- Captura de áudio.
- Speech-to-text.
- Text-to-speech.
- Interação em tempo real.
- Interrupção e reprodução.

**Fase 5 — Memory Engine**
- Memórias.
- Embeddings.
- pgvector.
- RAG.
- Recuperação contextual.

**Fase 6 — Agent Engine**
- Cadastro de agentes.
- Orquestração.
- Delegação.
- Permissões.
- Histórico de execução.

**Fase 7 — Tool Engine**
- Ferramentas internas.
- MCP.
- Descoberta.
- Execução.
- Aprovações.
- Auditoria.

**Fase 8 — Task Engine**
- Filas.
- Tarefas longas.
- Progresso.
- Eventos.
- Cancelamento.
- Retentativas.

**Fase 9 — Administração**
- Filament.
- Provedores.
- Modelos.
- Agentes.
- Conectores.
- Ferramentas.
- Monitoramento.

**Fase 10 — Segurança e produção**
- Hardening.
- Testes de integração.
- Performance.
- Observabilidade.
- Deploy.
- Documentação.

## 13. Critérios finais de aceitação

O projeto deverá entregar uma plataforma funcional na qual o usuário consiga:

1. Acessar o JARVIS pela web e instalar a PWA.
2. Visualizar e interagir com o núcleo animado.
3. Conversar por texto.
4. Conversar por voz.
5. Receber respostas por áudio.
6. Alternar provedores de IA.
7. Manter conversas com contexto.
8. Registrar e recuperar memórias.
9. Cadastrar e utilizar agentes.
10. Conectar servidores MCP.
11. Executar ferramentas autorizadas.
12. Acompanhar tarefas em tempo real.
13. Visualizar resultados estruturados.
14. Administrar provedores, agentes e conectores.
15. Consultar logs e histórico de atividades.

O resultado deverá ser utilizável, não apenas uma demonstração visual.

## 14. Primeira ação

Comece realizando o diagnóstico do projeto e verificando o estado do Beer & Code Harness.

Depois, conduza a inicialização pelo `/bc-harness:init`, respeitando a ordem e os checkpoints.

Não comece implementando todas as funcionalidades diretamente.

Primeiro construa uma especificação coerente, valide a arquitetura, defina o banco de dados e organize as fases.

**Objetivo final: construir um assistente pessoal JARVIS real, modular, expansível, com memória persistente, voz, agentes inteligentes, integração MCP e capacidade de executar tarefas do mundo real com segurança.**
