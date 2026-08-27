# Changelog

Formato inspirado em [Keep a Changelog](https://keepachangelog.com/pt-BR/).
Alvo: **GLPI 10.0.x** (validado em 10.0.26). A versão para GLPI 11 tem changelog
próprio no repositório `instant-glpi11-plugins`.

## [0.4.0] — 2026-08-27 (não lançado)

Nova sub-aba **Relatórios** na **Central de serviços** (Gerência › Relatórios › Central de
Serviços), com o primeiro relatório de lá: o **"Relatório central de serviços"**, clone do
PDF de 8 páginas do vReports que a Instant mandou como referência (a 8ª, "Top 10 Serviços",
foi descartada a pedido). Sem mudança de schema.

### Adicionado
- **Sub-abas Dashboard | Relatórios** na Central de serviços. O Dashboard é o que já
  existia (KPIs do mês); a nova aba tem seletor de relatório + filtro de período, no mesmo
  padrão da Gestão financeira e dos Analistas.
- **Relatório central de serviços**, em 7 seções — cada uma vira uma página do PDF:
  1. **Capa/cabeçalho**: cliente (entidade ativa da sessão), total de chamados abertos e
     período.
  2. **Total de atendimento**: linha com os chamados abertos por dia.
  3. **Atendimento diário**: barras de abertos × encerrados por dia.
  4. **Atendimentos por categoria**: Top 7 categorias, com o percentual sobre o total.
  5. **Atendimento SLA — (Não conformidade)**: por dia, quantos estouraram o prazo para o
     analista **assumir** o chamado (SLA de atendimento) e quantos estouraram o prazo de
     **solução**.
  6. **Atendimento SLA — (Nível de serviço)**: rosca dentro × fora do prazo de solução.
  7. **Top usuários requerentes**: os 10 com mais chamados abertos no período.
- **Exportação em PDF** (`&pdf=1` → `PluginServicereportsCentralpdf`,
  `inc/centralpdf.class.php`): A4 paisagem, capa + uma seção por página, no layout
  "Institucional" (faixa com logo, Cliente/Período/Emissão, rodapé "Página X de Y").
- **Exportação CSV**: um arquivo só, com as seções separadas por linha em branco (o
  original oferece um CSV por gráfico; sete botões na tela seriam pior).
- **`PluginServicereportsChart`** (`inc/chart.class.php`): gráficos SVG montados no PHP —
  linha, barras agrupadas, barras horizontais e rosca —, com tooltip único no hover
  (`data-tip-title`/`data-tip-body`, conteúdo montado com `textContent`). Mesma decisão do
  relatório 61: sem biblioteca JS, porque a mesma série precisa ser redesenhada no TCPDF
  para o PDF.

### Regras (confirmadas com a Instant em 2026-08-27)
- **Aberto** = `glpi_tickets.date` (data de abertura); é por ela que recortam as seções
  2, 4, 5, 6 e 7.
- **Encerrado** = tem `solvedate` no período — inclui chamado que já avançou para
  **Fechado** (no GLPI, Fechado passou por Solucionado e guarda a data). Por isso o total
  de encerrados pode passar o de abertos no mesmo período, como no relatório original.
- **Fora do SLA de solução**: `solvedate > time_to_resolve`, a mesma comparação da
  estatística "solucionados com atraso" do core (`Stat::inter_solved_late`). **Não** se
  soma o `sla_waiting_duration`: o GLPI já empurra o `time_to_resolve` pelo tempo em que o
  chamado ficou Pendente, e somar de novo contaria o mesmo tempo duas vezes.
  Chamado **sem SLA** conta como dentro do prazo — é o que fecha a rosca com o total de
  abertos (no PDF de referência, 810 + 63 = 873).
- **Fora do SLA de atendimento**: assumido depois do `time_to_own`, ou ainda não assumido
  com o prazo vencido. "Assumido" é o take into account do GLPI
  (`takeintoaccountdate`, com fallback em `takeintoaccount_delay_stat` para chamado
  antigo).

### Armadilhas pagas
- **`PieSector()` do TCPDF com `$cw=false`** desenha a rosca no sentido anti-horário e os
  rótulos das fatias, calculados no sentido horário, caem na fatia errada. Vai `true`.
- **Período em `d/m/Y` fixo** no PDF, não `Html::convDate()`: o formato de data é
  preferência de cada usuário, e o cabeçalho mudaria conforme quem imprime.
- Filtro de anos vira um SVG quilométrico: `dayLabels()` tem trava de 800 dias, e os
  gráficos diários passam a mostrar 1 rótulo a cada N e a esconder os números.

### Paridade
- Portado no mesmo dia para o repo **GLPI 11** (`instant-glpi11-plugins`, 0.4.0).

## [0.3.0] — 2026-08-27 (não lançado)

Novo relatório no bloco **Analistas › Relatórios**: **61 — "Chamados por Status e
Técnico"** (pedido da Instant a partir de um card do dashboard nativo). Sem mudança de
schema: atualizar é copiar os arquivos e recarregar.

### Adicionado
- **Relatório 61 — Chamados por Status e Técnico.** Barras **empilhadas** por técnico,
  uma faixa por status (Novo, Em atendimento atribuído/planejado, Pendente, Solucionado,
  Fechado), com **tooltip** no hover mostrando técnico, status, quantidade, percentual do
  técnico e total dele. Abaixo do gráfico, a **tabela** técnico × status com os mesmos
  números, totais por linha e por coluna. Não pagina — o gráfico é o relatório.
  Decisões de leitura (todas confirmadas com a Instant em 27/08):
  - **Conta chamados, não tarefas**, e o vínculo é o ator **Atribuído**
    (`glpi_tickets_users` tipo `ASSIGN`) — a mesma regra do card nativo do GLPI que serviu
    de modelo. Chamado com dois técnicos atribuídos conta 1 para **cada um**, então a soma
    das barras pode passar o número de chamados do período (dito no texto de ajuda).
  - **Período pela data de abertura** (`glpi_tickets.date`) e **status atual**: a pergunta
    é "dos chamados abertos no período, em que status estão e com quem". Diferente dos
    relatórios 57/59/60, que recortam pela data da *tarefa*, e do Extrato financeiro, que
    recorta pelo *fechamento*.
  - **Eixo X = só os técnicos com chamados no período** (sem colunas vazias). Técnico
    escolhido no filtro sem chamados aparece zerado, como nos cartões de performance.
- **Gráfico em SVG gerado no PHP** (`PluginServicereportsAnalysts::renderStatusChart()`),
  sem biblioteca JS: o GLPI 10 traz o Chartist, mas ele exigiria plugin para tooltip e
  não serve para o PDF. O tooltip é ~30 linhas de JS que só leem `data-*` dos `<rect>`
  (montado com `textContent`, nunca `innerHTML`). Rola na horizontal quando há muitos
  técnicos; rótulos girados -32° e cortados em 22 caracteres (nome inteiro no tooltip e na
  tabela).
- **Exportação em PDF** (`&pdf=1` → `PluginServicereportsStatustechpdf`,
  `inc/statustechpdf.class.php`): A4 **paisagem**, TCPDF, layout "Institucional" do
  Extrato (faixa com logo, Técnico/Período/Emissão, rodapé "Página X de Y"). O gráfico é
  redesenhado com primitivas do TCPDF — as mesmas cores da tela — e a tabela vem embaixo,
  quebrando de página com o cabeçalho repetido.
- **Exportação CSV** do 61 (`chamados_por_status_e_tecnico.csv`), no padrão dos outros
  relatórios, com a última linha trazendo o período pedido no filtro.

### Armadilhas pagas (para não repetir)
- **`SetXY()` com `x` negativo no TCPDF significa "a partir da borda direita"** — o rótulo
  girado da **primeira** barra sumia da folha. A célula do rótulo agora vai de `x=0` até o
  pé da barra, com o texto alinhado à direita.
- **`Cell()` não quebra linha**: "EM ATENDIMENTO (ATRIBUÍDO)" transbordava por cima da
  coluna vizinha no cabeçalho da tabela. Virou `MultiCell` de duas linhas.
- **Folga no topo do eixo Y**: sem ela, com um técnico só, a barra encostava no teto e o
  rótulo do total ficava por cima da grade. `niceScale()` sempre deixa um passo de sobra.
- **Tooltip posicionado já no `mouseover`** (e não só no `mousemove`): entrando na barra
  sem mover o ponteiro depois, ele aparecia no canto da página.
- Órfã de tabela: se a tabela não cabe embaixo do gráfico mas cabe inteira numa folha
  nova, ela começa numa folha nova e o **gráfico ocupa a folha toda** — em vez de duas
  linhas derramando na página seguinte e meia página em branco na primeira.

### Paridade
- Portado no mesmo dia para o repo **GLPI 11** (`instant-glpi11-plugins`, 0.3.0).

## [0.2.2] — 2026-08-27 (não lançado)

Dois ajustes no **relatório 60 — "Entidade vs. Analistas"**, pedidos pela Instant depois
de olhar o relatório com dados reais. Sem mudança de schema: atualizar é copiar os
arquivos e recarregar.

### Alterado
- **Período volta a ser pela data da tarefa (`tt.date`), e o chamado não precisa mais
  estar fechado.** O relatório nasceu (26/08) somando pela regra do **Extrato
  financeiro** — o chamado entrava pela `closedate` e levava todas as tarefas dele —, o
  que deixava de fora **toda** tarefa de chamado ainda em aberto. Como o que se quer aqui
  é a *somatória de tarefas do analista no período*, e não o faturável, a consulta passa a
  recortar a **tarefa** pela própria data: entram todas as tarefas lançadas no intervalo,
  esteja o chamado fechado, solucionado ou em aberto. Com isso o relatório 60 deixa de ser
  a exceção e volta à mesma regra dos relatórios 57 e 59.
  **Consequência esperada:** os números sobem em relação à versão anterior, e continuam
  **não** batendo com o Extrato financeiro da mesma entidade — lá o recorte é por
  fechamento, e só o que está ligado a um serviço gerenciado conta. O texto de ajuda
  acima da tabela foi reescrito para dizer isso.

### Adicionado
- **Última linha com o período do relatório.** Depois dos dados (e depois da linha de
  totais) sai `Período do relatório: 01/08/2026 a 14/08/2026`, com as datas exatamente
  como pedidas no filtro, em `d/m/Y`. Vale para a **tela** e para o **CSV** (última linha
  do arquivo), para que uma planilha ou uma impressão solta continue dizendo a que período
  se refere. Na tela a linha fica **fora** da tabela de propósito: o `<tfoot>` de totais é
  `position: sticky` e, ao rolar até o fim, cobriria uma segunda linha do rodapé.

### Paridade
- Portado no mesmo dia para o repo **GLPI 11** (`instant-glpi11-plugins`, 0.2.2): mesma
  mudança de `WHERE`, mesmo `$periodLabel`, seção do relatório 60 idêntica byte a byte
  entre os dois `front/analysts.php` (a menos do prefixo de classe).

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
  sobre o período inteiro) e 59, e Gestão financeira 1 (10 serviços por página).
  **CSV e impressão/PDF nunca paginam**; filtrar volta para a 1ª página.
- **Analistas › dropdown "Técnico" com todos os técnicos do GLPI** (usuários com o
  direito `own_ticket`, mesmo critério do "Atribuído a" do core), e não só os com
  atividade no período. Técnico sem atividade aparece com o cartão zerado, em vez de
  "nenhum técnico".

### Portado para o GLPI 11 — paridade restaurada (2026-08-26)
- Tudo o que este repositório ganhou em **25 e 26/08** foi portado para
  `instant-glpi11-plugins` (rodada **0.2.1** lá) e validado no **GLPI 11.0.8**: regra de
  período por data de fechamento, layout "Institucional", `Extratopdf` (TCPDF), remoção do
  relatório 4 e o relatório 60 com a supressão dos nós de agrupamento. Os dois repositórios
  voltam a estar em paridade funcional.
- O port foi **gerado** a partir dos arquivos daqui com as trocas mecânicas de sempre
  (namespace + `use`, SQL cru por `self::rows()`, assets em `public/`); a fidelidade foi
  conferida rodando a mesma transformação sobre a versão do **commit de paridade anterior**
  e comparando byte a byte com o arquivo que já estava lá.
- Armadilha nova registrada nos dois `CLAUDE.md`: no GLPI 11 o `$DB->request()` recusa SQL
  cru **também** na chamada quebrada em várias linhas e na consumida com `->current()` —
  não basta procurar `foreach ($DB->request("`.

### Adicionado — relatório "Entidade vs. Analistas" (2026-08-26)
- **Novo relatório id 60** no seletor de *Relatórios › Analistas* (`front/analysts.php` +
  `PluginServicereportsAnalysts::getEntityAnalystMatrix()`), a partir da planilha
  "Tecnicos e horas" da Instant: matriz **analista (linhas) × entidade (colunas)** com o
  tempo de tarefas, coluna **Total** por analista e linha **Total** por entidade.
- **A somatória segue a regra do Extrato financeiro**, não a dos relatórios 57/59: o
  período recorta o chamado pela **data de fechamento** (`glpi_tickets.closedate`) e, uma
  vez dentro, entram **todas** as tarefas dele — inclusive as de meses anteriores. Chamado
  em aberto (ou apenas *Solucionado*, com `closedate` NULL) não entra em período nenhum.
  Por isso a consulta **não** filtra por `tt.date` (que é o critério dos outros relatórios
  de analistas, onde a pergunta é "quem trabalhou quando").
- **Sem recorte por serviço gerenciado** (decisão da Instant): entram todos os chamados
  fechados no período, e não só os ligados a um serviço — o número é o total de horas do
  analista, não o faturável. Um analista pode, portanto, somar mais horas aqui do que no
  extrato da mesma entidade.
- **Colunas = as entidades visíveis na sessão que são folhas da árvore**
  (`getVisibleEntities()`, a partir de `$_SESSION['glpiactiveentities']`), mesmo as
  zeradas — como na planilha, que tem uma coluna fixa por cliente. Rótulo é o nome
  **curto** da entidade (completename no `title`). Na entidade raiz vendo tudo saem
  todas; com a sessão restrita, só a subárvore ativa.
- **Nós de agrupamento não viram coluna** (ajuste do mesmo dia, com dados de produção à
  vista): "Standard", "Premium" e a própria raiz são níveis da árvore, não clientes, e
  enchiam a tabela de colunas zeradas. Passam a ser omitidos — **a menos que tenham horas
  no período**, e aí a coluna fica: chamado lançado direto num nó intermediário é dele, e
  esconder a coluna faria a hora sumir e os totais deixarem de conferir. "Folha" = entidade
  sem filhas em `glpi_entities` (não só sem filhas visíveis na sessão).
- Filtro de técnico em **"Todos"** desenha a matriz inteira; com um analista escolhido,
  desenha só a linha dele — zerada, se ele não teve horas no período. Células em
  `HH:MM:SS` (`secToHms()`, padrão dos outros relatórios).
- Tabela larga com **rolagem horizontal**, cabeçalho e coluna do analista **fixos**
  (`position: sticky`, classes `sr-eva`). **Não pagina** e **exporta CSV**
  (`entidade_vs_analistas.csv`, mesma tabela com a linha de totais).
- Validado no GLPI 10.0.26 local nas duas condições de entidade (raiz vendo tudo e sessão
  restrita a uma filha — sem erro de `getEntitiesRestrictRequest`), com chamado fechado no
  período mas com tarefa de mês anterior (conta), chamado em aberto (não conta) e 24
  colunas de entidade (coluna fixa e rolagem OK). Sem mudança de schema.

### Alterado — Extrato financeiro vira PDF de verdade (2026-08-25)
- **A rota `&pdf=1` passa a gerar PDF com TCPDF** (`inc/extratopdf.class.php`,
  `PluginServicereportsExtratopdf`), no lugar da impressão do navegador. Motivo: a versão
  impressa **cortava dados** — as células da listagem usavam `text-overflow:ellipsis` para
  as colunas alinharem, e título/categoria longos chegavam ao papel pela metade.
- No PDF cada célula é um `MultiCell` que **quebra em linhas** (altura da linha = coluna que
  precisa de mais linhas, via `getNumLines()`), o cabeçalho da tabela **se repete** quando a
  listagem vira a folha, e o rodapé traz **"Página X de Y"** — que HTML impresso não faz.
- A tela não mudou: continua o layout "Institucional" em HTML. São dois renderizadores do
  mesmo desenho; `renderExtratoPrint()` e o parâmetro `$print` foram removidos.
- Validado no GLPI 10.0.26 local com 30 chamados de volume (9 páginas): nenhuma linha
  perdida, quebra de página no meio da tabela com cabeçalho repetido, e título de 100
  caracteres saindo inteiro em 4 linhas.
- Sem mudança de schema. O TCPDF já vem no vendor do GLPI 10 (o core usa em `GLPIPDF`).

### Alterado — novo layout do Extrato financeiro (2026-08-25)
- **Extrato redesenhado** (tela e impressão/PDF), a partir de três propostas apresentadas à
  Instant; escolhida a **"Institucional"**. Mantém todos os dados — resumo da entidade, os
  seis valores por serviço e as dez colunas da listagem — e muda a hierarquia:
  - faixa de cabeçalho ligando a logo ao título, com Empresa / Período / Emissão à direita;
  - resumo da entidade em **4 cartões** (total em destaque) + linha fina com categorias,
    extras e tempo de tarefas — antes eram sete frases de mesmo peso;
  - os seis valores do serviço saem de **seis linhas de texto para uma grade de 6 colunas**;
  - listagem de chamados com cabeçalho escuro, zebra e largura de coluna fixa (as tabelas
    de serviços diferentes passam a alinhar entre si);
  - rodapé "Impresso por … em …" em toda folha.
- **O extrato deixou de sair todo em negrito** (era pedido antigo do cliente): sem contraste
  de peso nada tinha prioridade. Agora o negrito marca números e títulos.
- O CSS saiu dos atributos `style` para um `<style>` único (`styles()`, classes `sr-`), o que
  permitiu zebra, `@media print` e repetir o `thead` da tabela na folha seguinte.
- Continua **HTML de impressão** (`&pdf=1` → `window.print()`), não PDF gerado: mantém tela e
  papel no mesmo renderizador. "Página X de Y" só sairia com TCPDF — o navegador consegue
  numerar pelas opções da própria caixa de impressão.
- `renderExtrato()` passou a receber `$start`/`$end` (o cabeçalho do documento mostra o
  período também na tela). `kv()`, que ficou órfã com a remoção do relatório 4, foi apagada.
- Sem mudança de schema.

### Alterado — regra de período dos relatórios financeiros (2026-08-25)
- **O chamado passa a ser faturado no período em que foi FECHADO**, e não mais no período
  em que foi aberto — e entra com **todas** as suas tarefas, sem filtro de data. Antes o
  extrato pegava os chamados por `glpi_tickets.date` (abertura) e somava só as tarefas com
  `tt.date` dentro do período, o que **partia um mesmo chamado entre vários meses**.
  Exemplo da Instant: chamado aberto em 09/10/2024, tarefas em 11/10 (0:30), 02/11 (0:40)
  e 04/11 (0:45), fechado em 14/11/2024 → **outubro sai vazio** e **novembro soma 1:55**
  (antes: outubro 0:30 e novembro 1:25). Chamado ainda **em aberto** — ou apenas
  *Solucionado*, com `closedate` NULL — não aparece em extrato nenhum até fechar.
- Vale para os **dois** relatórios financeiros: o 2 (Faturamento) deriva do mesmo
  `getExtrato()`. O bloco **Analistas não muda** (continua por `tt.date`).
- `taskTime()` foi removido: o total de horas do serviço agora é a **soma dos chamados
  listados**, o que elimina a incoerência de o cabeçalho mostrar horas enquanto a listagem
  vinha vazia (acontecia sempre que a tarefa caía num mês e a abertura em outro).
- Listagem rotulada como "(fechados no período)" e ordenada por data de fechamento.
- Consequência aceita: como o GLPI sobrescreve `closedate` na reabertura, um chamado
  reaberto e fechado de novo **migra de mês** — não há congelamento do período faturado.
- Sem mudança de schema.

### Removido — 2026-08-25
- **Relatório "Fatura de serviços detalhada" (id 4)** saiu do bloco Gestão financeira:
  sumiu do seletor de relatórios, da rota de impressão (`&pdf=1`) e o
  `renderFaturaDetalhada()` foi apagado de `inc/financial.class.php`. Decisão da Instant
  — a fatura do vReports original é um PDF de engine próprio e o relatório aqui não ia
  ser usado. Restam os relatórios **1 (Extrato financeiro)** e **2 (Faturamento
  financeiro)**. Sem mudança de schema: atualizar é `sync.sh`/`cp` + recarregar.
  **Pendente de porte para o repo GLPI 11** (`instant-glpi11-plugins`).

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
