# Fila de port para o GLPI 11 (`instant-glpi11-plugins`)

> Este arquivo é a **lista de tudo o que foi feito aqui (GLPI 10) e ainda não foi levado**
> para o repositório `instant-glpi11-plugins`. Ao terminar uma mudança neste repo,
> **acrescente um item aqui**; ao portar, **risque o item** (mova para "Já portado") no
> mesmo commit do port. Divergência entre os dois repos é o principal risco do projeto.

## Como portar (mecânico, mas com armadilhas)

- As classes lá são **namespaced em `src/`**, não `inc/*.class.php` com nome plano.
- Os assets estáticos ficam em **`<plugin>/public/`** (a logo do PDF é
  `servicereports/public/pics/instant-logo.png`; aqui continua em `pics/`).
- **SQL cru não passa por `$DB->request()`** no GLPI 11 — vai por `$DB->doQuery()` /
  `self::rows()`. Procure **todas** as formas de chamada, não só `foreach ($DB->request("`:
  a quebrada em várias linhas e a consumida com `->current()` também estouram (foi o que
  aconteceu no primeiro teste do relatório 60 lá).
- Forma barata de garantir que a transformação é fiel: rode-a sobre a versão do **commit
  de paridade anterior** e confira que o resultado bate byte a byte com o arquivo que já
  está no repo 11.
- Testar sempre nas **duas condições de entidade** (raiz vendo tudo e sessão restrita a
  uma entidade filha) e nos **três formatos** (tela, CSV e PDF).

## Pendente

### 1. Relatório 61 — 2º gráfico "Chamados por tipo e técnico" (28/08, commit `f09bd16`)

Analistas › Relatórios › "Chamados por Status e Técnico" ganhou um **segundo** gráfico de
barras empilhadas + tabela, quebrando os mesmos chamados em **Incidente × Requisição**.

- `inc/analysts.class.php`: consts `TYPE_ORDER`/`TYPE_COLORS` (NAVY `#2b3a54` /
  STEEL `#93a9c6`), `typeLabels()`, **`countByTechnician()`** genérico (a coluna da pilha
  — `status`/`type` — é parâmetro) com `getStatusByTechnician()`/`getTypeByTechnician()`
  como cascas, e `renderStatusChart()` → **`renderStackedChart($data, $aria)`**, que lê
  `keys`/`legend`/`labels`/`colors` do próprio array. O atributo `data-status` dos
  segmentos virou `data-series`.
- `front/analysts.php`: bloco do relatório 61 com os dois gráficos e as duas tabelas
  (closure `$renderCstTable`), tooltip preso a **todos** os `.sr-cst-wrap` da página
  (era `querySelector`, virou `querySelectorAll`), CSV em duas seções separadas por linha
  em branco e rota `&pdf=1` passando os dois conjuntos.
- `inc/statustechpdf.class.php`: `cols($n)` no lugar da const `COLS` (6 status de 29mm ou
  2 tipos de 87mm, somando 277mm), `drawSection()`, e **título de seção no cabeçalho**,
  trocado **antes** do `AddPage`. O rodapé fica com o nome do relatório de propósito: o
  TCPDF chama o `Footer` da folha anterior *dentro* do `AddPage`, depois da troca.

### 2. Relatório 4 — "Chamados por grupo" (28/08, commit `359cff6`)

Novo relatório em Central de serviços › Relatórios: capa, barras horizontais e tabela.

- Arquivos novos: `inc/groupreport.class.php` (dados, `hint()`, `chartTitle()`/
  `tableTitle()` compartilhados por tela e PDF) e `inc/groupreportpdf.class.php`
  (**estende o `centralpdf`**).
- `front/servicecentral.php`: item 4 no seletor, `in_array($report, [1,2,3,4])`, branch de
  CSV, rota `&pdf=1` e o bloco da tela.
- Regras: grupo do ator **Atribuído** (`glpi_groups_tickets` tipo `ASSIGN`), período pela
  **data de abertura**, chamado em dois grupos conta nos dois (capa = distintos, rodapé =
  soma, percentual sobre a soma), **"Sem grupo atribuído"** como última linha fora da
  ordenação, **completename** sem somar subgrupo no pai.
- No PDF, o bloco usa `SetAutoPageBreak(false)`, então a tabela quebra **na mão**.

### 3. Relatório 5 — "Chamados por entidade" (28/08)

Novo relatório em Central de serviços › Relatórios: é o **gráfico do relatório 61 com
entidade no eixo X**, então quase tudo é reúso.

- Arquivos novos: `inc/entityreport.class.php` (dados no formato que o
  `renderStackedChart()` consome + `title()`/`hint()`) e `inc/entityreportpdf.class.php`
  (**estende o `statustechpdf`**, entrada `buildEntity()` — o nome é diferente de `build()`
  porque o PHP não deixa sobrescrever estático com assinatura incompatível).
- `front/servicecentral.php`: item 5 no seletor, `in_array($report, [1,2,3,4,5])`, branch
  de CSV, rota `&pdf=1` e o bloco da tela (que chama
  `PluginServicereportsAnalysts::renderStackedChart()`).
- **Refatorações que vão junto** (sem elas o relatório não funciona):
  - `PluginServicereportsAnalysts::stackedAssets()` — CSS + tooltip do gráfico empilhado,
    emitidos uma vez por página pelo `renderStackedChart()`; saíram do
    `front/analysts.php` (que perdeu o `<style>` e o `scriptBlock`). O tooltip passou a
    usar **delegação no `document`**.
  - `PluginServicereportsStatustechpdf`: propriedades `$metaLabel`/`$metaValue`/
    `$reportName`/`$subtitle`/`$firstCol`/`$rowLabelKey`, métodos de desenho `protected` e
    **constantes `protected`** (com `private` a subclasse não as enxerga — o PDF do
    "período sem chamados" quebrava).
- Regras: todos os status, período pela **data de abertura**, **sem soma na árvore**, só
  entidades com chamado, ordenadas pelo **completename**; eixo com o nome curto
  (`name`), tabela com o completo (`fullname`).

### 4. Relatório de atualização - Cliente — ANUAL e MENSAL (27/08)

Central de serviços › Relatórios, ids **2 e 3** (`inc/updatereport.class.php`,
`inc/updatepdf.class.php` e o bloco da tela em `front/servicecentral.php`). Pendente desde
27/08 — ver a seção correspondente do [CLAUDE.md](../CLAUDE.md) para as regras
(granularidade `GRAIN_MONTH`/`GRAIN_DAY`, "fechado" por `closedate`, tabela de status que
tem de fechar com a capa, rosca do bucket em `drawTypeDonut()`).

## Já portado

- Mudanças de 25/08 (período por data de fechamento, layout "Institucional", PDF via
  TCPDF, remoção do relatório 4 da Gestão financeira).
- Relatório 60 — "Entidade vs. Analistas".
- Relatório 61 — "Chamados por Status e Técnico" (o gráfico de status e o PDF paisagem;
  o 2º gráfico, de tipo, está **pendente** — item 1 acima).
- "Relatório central de serviços" (Central de serviços › Relatórios, id 1).
