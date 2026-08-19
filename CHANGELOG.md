# Changelog

Formato inspirado em [Keep a Changelog](https://keepachangelog.com/pt-BR/).
Alvo: **GLPI 10.0.x** (validado em 10.0.26). A versão para GLPI 11 tem changelog
próprio no repositório `instant-glpi11-plugins`.

## [0.2.0] — 2026-08-19 (não lançado)

Rodada de ajustes pedida pela Instant sobre o que já rodava em produção. No caminho
apareceram **três bugs sérios** (dashboard em branco fora da entidade raiz,
categoria-pai não selecionável e chamados invisíveis para serviço recursivo) e uma
descoberta de infra: o `cp` do deploy vinha **falhando** porque o caminho do GLPI da
Instant não é `/var/www/glpi` e sim **`/var/www/instant/glpi`** — por isso três
correções seguidas "não fizeram efeito". Nenhuma mudança de schema: atualizar é
copiar os arquivos e recarregar.

### Corrigido
- **Dashboard financeiro em branco fora da entidade raiz**: `latestValuesSql()` passava
  o **nome da tabela** para `getEntitiesRestrictRequest()` enquanto o `FROM` a aliasa
  como `ms`. Com a sessão restrita a uma entidade (o caso normal de quem trabalha dentro
  de um cliente) o SQL citava uma coluna inexistente, a consulta falhava e a aba
  **Dashboards saía só com o título**. Na entidade raiz vendo tudo o GLPI não gera
  cláusula nenhuma — por isso o bug passou por todos os testes anteriores.
- **Categoria-pai não selecionável em Serviços Gerenciados**: o dropdown "Categoria de
  chamado" usava o padrão do GLPI, que renderiza os nós **pai** como item `disabled`
  (só rótulo da hierarquia). Não dava para escolher "Suporte Avançado" justamente por
  ela ter filhas. Resolvido com `'permit_select_parent' => true` — o mesmo parâmetro que
  o core usa na busca —, mantendo o dropdown de árvore nativo.
- **Serviço recursivo não via chamados das entidades filhas**: a busca por categoria
  exigia `entities_id` **igual** ao do serviço; agora, quando o serviço é
  `is_recursive`, considera a entidade **e suas descendentes**
  (`getSonsOf('glpi_entities', …)`).
- **Categoria do serviço passa a incluir toda a subárvore**: os chamados vinculados por
  categoria consideram a categoria declarada **e todas as filhas**
  (`getSonsOf('glpi_itilcategories', …)`, em `categoryTreeIds()`). Um serviço em
  "Suporte Avançado" passa a somar "Suporte Avançado > Active Directory > Criação /
  Alteração de GPO". Afeta os relatórios 1, 2 e 4 — valores de hora, tempo de tarefas e
  a listagem de chamados.
- **"Receita prevista" não conta mais o valor/hora**: valor/hora é **tarifa**, não
  receita — só vira dinheiro no extrato, multiplicado pelas horas. O KPI e o gráfico
  "Top 10 receitas previstas por entidade" somam apenas valores recorrentes (`monthly`,
  `perclass`, `peruser`), batendo com o dashboard do vReports original.
- **Aviso explícito no lugar do zero silencioso**: serviço sem "Categoria de chamado" e
  sem ativos cobertos passa a explicar, na listagem, por que não há chamados vinculados
  — era a causa mais comum de "relatório zerado".
- **[docs/INSTALL.md](docs/INSTALL.md)**: registra o caminho real do GLPI da Instant
  (`/var/www/instant/glpi`) e manda conferir a cópia com `ls -l`.

### Alterado — Extrato financeiro (tela e PDF)
- **Entidade pelo nome curto**: "Uniletra" no lugar de "Instant > Standard > Uniletra",
  na tela, no PDF e nos CSVs (`entityName()`). O gráfico do Dashboard segue com o nome
  completo.
- **Conteúdo do relatório todo em negrito.**
- **Listagem de chamados no formato do original**: **ID, Título, Tipo, Categoria, Req.,
  Abertura, Fechamento, Horas, Custo hora, Custo chamado**. "Horas" é o tempo de tarefas
  **daquele chamado no período** (por `tt.date`), "Custo hora" = horas × valor/hora do
  serviço, "Custo chamado" a soma — a coluna fecha com o "Valor monetário total das
  tarefas" do serviço. Fechamento usa `closedate` (cai para `solvedate`).
- **Bloco do serviço**: barra cinza com *Serviço: <nome>* à esquerda e **CUSTO TOTAL** à
  direita; abaixo, em coluna única, mensal → ativos → categoria → extras → total das
  tarefas → *Tempo total de tarefas*; por último, o título *Listagem dos chamados
  vinculados ao serviço* com a tabela. O resumo da entidade ganhou **Tempo total de
  tarefas** (soma dos serviços). Essas durações saem por extenso ("42 horas 0 minutos",
  `Html::timestampToString` sem dias); a coluna Horas da tabela segue em `HH:MM:SS`.
- **Listagem de ativos cobertos removida** (tela e PDF): o dado continua sendo lido para
  compor os valores, só não é exibido.
- **PDF** (`renderExtratoPrint()`): **logo** da Instant
  (`servicereports/pics/instant-logo.png`, trocável) ancorada à esquerda e título
  centralizado em três linhas — *Extrato de consumo de serviços* / *Período de X a Y* /
  *Empresa: <entidade>*; **paisagem** (`@page { size: A4 landscape }`); uma empresa por
  página; **sem hiperlinks** (o nº do chamado só é link na tela); colunas de custo com
  `white-space:nowrap`; sem os botões de CSV/PDF da tela.

### Adicionado
- **Paginação de 10 em 10** (`PluginServicereportsPager`, `inc/pager.class.php`) nos
  relatórios: Analistas 57 (via `LIMIT/OFFSET`, com os totais do cabeçalho calculados
  sobre o período inteiro) e 59, e Gestão financeira 1 e 4 (10 serviços por página).
  **CSV e impressão/PDF nunca paginam**; filtrar volta para a 1ª página.
- **Analistas › dropdown "Técnico" com todos os técnicos do GLPI** (usuários com o
  direito `own_ticket`, mesmo critério do "Atribuído a" do core), e não só os com
  atividade no período. Técnico sem atividade aparece com o cartão zerado, em vez de
  "nenhum técnico".

> **Paridade:** a versão GLPI 11 (`instant-glpi11-plugins`) recebeu esta mesma rodada
> na sua `0.2.0`, em 2026-08-19.

## [0.1.0] — 2026-08-13 (não lançado)

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
