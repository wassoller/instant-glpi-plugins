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

*(vazio — os dois repos ficaram em paridade em 2026-08-28; o port foi validado no
GLPI 11.0.8 local, com sessão na entidade raiz e restrita a uma entidade filha.)*

Ao acrescentar um item aqui, copie o formato dos que estão em "Já portado": o que mudou,
em que arquivos, e as regras que não dá para adivinhar lendo o código.

## Já portado

- Mudanças de 25/08 (período por data de fechamento, layout "Institucional", PDF via
  TCPDF, remoção do relatório 4 da Gestão financeira).
- Relatório 60 — "Entidade vs. Analistas".
- Relatório 61 — "Chamados por Status e Técnico" (gráfico de status e PDF paisagem).
- "Relatório central de serviços" (Central de serviços › Relatórios, id 1).
- **28/08 — a leva toda, no repo 11 como 0.5.0** (commit de paridade lá: `20be39b`;
  aqui: `2ecfbd0`):
  - **2º gráfico do relatório 61** ("Chamados por tipo e técnico"), com o
    `countByTechnician()` genérico, o `renderStackedChart()` e o **`stackedAssets()`**
    (CSS + tooltip por delegação no `document`).
  - **4 — "Chamados por grupo"** (`src/Groupreport.php`, `src/Groupreportpdf.php`).
  - **5 — "Chamados por entidade"** (`src/Entityreport.php`, `src/Entityreportpdf.php`),
    com o **`Statustechpdf` parametrizado** (`$metaLabel`/`$metaValue`/`$reportName`/
    `$subtitle`/`$firstCol`/`$rowLabelKey`, métodos **e constantes** `protected`).
  - **"Relatório de atualização - Cliente" ANUAL e MENSAL** (`src/Updatereport.php`,
    `src/Updatepdf.php`), com o `Centralpdf` abrindo `$isCover` e os métodos de desenho.

### Como este port foi feito (repita)

A transformação mecânica (namespace + `use`, `PluginServicereportsX` → `X`,
`$DB->request()` com SQL cru → `self::rows()`, `'PluginServicereportsMenu'` →
`Menu::class`, logo em `public/pics/`) foi **validada antes de usar**: rodada sobre os
arquivos do commit de paridade daqui, ela reproduz **byte a byte** o corpo dos arquivos
que já estavam no repo 11 (o único delta é o helper `rows()` do `Analysts`). Com isso:

- **arquivos já portados** → transformei o **próprio patch** (`git diff <paridade>..HEAD`)
  e apliquei com `patch -p1` lá, em vez de recopiar o arquivo inteiro;
- **arquivos novos** → transformei o arquivo inteiro e troquei a guarda `GLPI_ROOT` pelo
  `namespace` + `use`.
