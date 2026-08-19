# Changelog

Formato inspirado em [Keep a Changelog](https://keepachangelog.com/pt-BR/).
Alvo: **GLPI 10.0.x** (validado em 10.0.26). A versão para GLPI 11 tem changelog
próprio no repositório `instant-glpi11-plugins`.

## [0.1.0] — 2026-08-13 (não lançado)

### Ajustes — Relatórios financeiros e Analistas (2026-08-19)
- **Categoria do serviço passa a incluir toda a subárvore**: os chamados vinculados a um
  serviço por categoria agora consideram a categoria declarada em *Serviços Gerenciados*
  **e todas as suas filhas** (`getSonsOf('glpi_itilcategories', …)`). Ex.: um serviço em
  "Suporte Avançado" passa a somar os chamados de
  "Suporte Avançado > Active Directory > Criação / Alteração de GPO".
  Afeta o Extrato financeiro (1), o Faturamento (2) e a Fatura detalhada (4) — valores de
  hora, tempo de tarefas e a listagem de chamados.
- **Analistas › dropdown "Técnico"**: lista **todos os técnicos do GLPI** (usuários com o
  direito `own_ticket`, mesmo critério do campo "Atribuído a" do core), e não só os que
  tiveram atividade no intervalo de datas. O técnico escolhido sem atividade no período
  agora aparece na aba Técnicos com o cartão zerado, em vez de "nenhum técnico".
- **Paginação de 10 em 10** nos relatórios (`PluginServicereportsPager`, `inc/pager.class.php`):
  Analistas 57 (tarefas — via `LIMIT/OFFSET`, com os totais calculados sobre o período
  inteiro) e 59 (horas fora de expediente), e Gestão financeira 1 e 4 (10 serviços por
  página). Exportações **CSV** e a visão de **impressão/PDF** continuam completas
  (sem paginação); filtrar volta para a 1ª página.

Primeira versão funcional dos dois plugins, reconstruídos por engenharia reversa
da UI web da instância Verdana e **validados num GLPI 10.0.26 local**. Os plugins já
estão **em produção** no GLPI 10 da Instant (`suporte.instanttecnologia.com.br`).

### Adicionado — Gestão financeira › sub-aba **Relatórios** (2026-08-14)
Antes só existia o Dashboard; a sub-aba Relatórios estava **em branco**. Agora o
bloco tem a navegação **Dashboards / Relatórios** (paridade com o vReports) e os 3
relatórios do original, com filtro de período (`start_date`/`end_date`):
- **Extrato financeiro** (id 1): detalhamento por entidade → por serviço. Componentes
  computados de verdade a partir do `managedservices`: **valor mensal** (último
  `monthly`), **valor de ativos** (soma dos `perclass` casados ao tipo dos ativos
  cobertos), **tempo total de tarefas** (Σ `actiontime`, filtrado por `tt.date`) e
  **valor das tarefas** (tempo × último `hourly`). Lista **ativos cobertos** e
  **chamados vinculados** (nº com link, categoria do chamado, status). Export **CSV**
  e visão **PDF** (página limpa via `Html::popHeader` + `window.print()`).
- **Faturamento financeiro** (id 2): "Resumo financeiro geral" com período + valor
  total faturado; export CSV por entidade.
- **Fatura de serviços detalhada** (id 4): documento de fatura **imprimível** (HTML →
  Salvar como PDF). O original gera PDF por engine próprio — entregue com nota honesta.
- **Estrutura honesta** (sem modelo de dados no `managedservices`, batendo com os zeros
  do demo Verdana): "valor por **categoria** de chamado" e "valores **extras**
  relacionados a chamados" = R$ 0,00.
- Validado no GLPI 10.0.26 local com dados reais (mensal R$ 1.500,50 + 6h × R$ 100/h =
  **R$ 2.100,50**). Sem mudança de banco → atualizar é só copiar os arquivos e recarregar.

### Correções pós-deploy — bloco Analistas (relatórios), diagnosticadas em produção
- **Relatório "Tarefas por Técnico" e Horas trabalhadas**: passam a filtrar o período
  por `tt.date` (data da tarefa) em vez de `tt.begin` (Início/planejamento, que fica
  **NULO** em tarefas não planejadas — a maioria). Sem isso, o relatório vinha **vazio**
  em produção mesmo havendo tarefas com duração. Vale para getTasksReport,
  getTechnicians, getPerformance e getOutOfHoursReport.
- Coluna **"Data"** adicionada ao relatório 57; a coluna **"Categoria"** passa a exibir a
  categoria do **chamado** (`glpi_tickets.itilcategories_id`), não a da tarefa (quase
  sempre vazia).
- **Número do chamado** vira link para o chamado (`/front/ticket.form.php?id=`).
- **Exportação CSV** nos relatórios 57 e 59 (botão "Exportar CSV"; delimitador `;`, BOM
  UTF-8, entidades HTML decodificadas; `$escape` explícito no `fputcsv` para PHP 8.4+).
- Sem mudança de banco → atualizar é só copiar os arquivos e recarregar (não reinstalar).

### Adicionado — `managedservices` ("Serviços Gerenciados", menu Ativos)
- Objeto principal `PluginManagedservicesManagedservice` (nome, cliente/`users_id`,
  contrato, categoria de chamado) com CRUD, busca (search options) e menu em Ativos.
- Integração de perfis/direitos (`plugin_managedservices`).
- Aba **Gerência**: gerentes usuário/grupo do serviço.
- Aba **Ativos cobertos pelo serviço**: itemtype+item + data de contrato, com lista
  de "removidos" (soft-delete).
- Aba **Composição do Serviço**: itemtype+item + impacto (Parcial/Total).
- Aba **Financeiro**: 8 dimensões de valor historizadas por data de contrato
  (mensal, hora, por classe de ativo, por usuário, por banco de dados, por
  armazenamento) + configs de horas de suporte e limite de horas.
- Aba **Configuração NMS**: URL do NMS por serviço.

### Adicionado — `servicereports` ("Relatórios", menu Gerência)
- Landing com os 3 blocos.
- **Central de serviços**: 8 KPIs de chamados do mês (contagens via SQL) +
  deep-links para `/front/ticket.php` com os critérios exatos do original.
- **Gestão financeira**: sub-abas **Dashboards** (6 KPIs + 2 gráficos de barras
  HTML/CSS puro) e **Relatórios** (Extrato financeiro, Faturamento financeiro, Fatura
  de serviços detalhada), lendo os dados financeiros do `managedservices`.
- **Analistas**: dashboard Técnicos (horas, chamados por tipo, satisfação, pontos)
  + Relatórios (Tarefas por Técnico e Horas fora de expediente) + Mapas.

### Adicionado — projeto
- `docs/recon/` — engenharia reversa (modelo de dados, KPIs, telas).
- `docs/INSTALL.md` + `docs/INSTALL.pdf` — instalação via `git clone`.
- `dist/managedservices.zip`, `dist/servicereports.zip` — pacotes prontos.
- Repositório privado `instant-glpi-plugins`.

### Correções pegas na validação local (ver histórico git)
- `getTabNameForItem()` precisa ser método de instância (não `static`).
- Dropdown múltiplo (`User`/`Group`/`Dropdown`) lê `'value'` (array), não `'values'`.
- Classe `.small` do tema quebrava o texto da landing (troca por `font-size` inline).

### Limitações conhecidas (partes Verdana-específicas)
- **Analistas › Deslocamentos** e **Mapas**: dependem de fontes de dados não-nativas
  do GLPI (distância de deslocamento; geolocalização). Entregues com estrutura + nota.
- **Analistas › Pontos** = chamados solucionados (aproximação).
- **Horas fora de expediente**: jornada fixa Seg–Sex 08:00–18:00.

### Pendente (Fase 7)
- Arquivos de tradução `.mo` (hoje os textos saem em pt-BR direto via `__()`).
- Refino de ícones/estilos.
- Teste de instalação na VM real da Instant.

### Notas para o próximo agente
- **Sempre teste num GLPI local** (ver `CLAUDE.md`), não só `php -l`.
- O `servicereports` depende do `managedservices` (dados financeiros) — instale o
  primeiro antes.
- Ao mexer na lógica, **replique no repositório GLPI 11** (`instant-glpi11-plugins`)
  para manter paridade.
