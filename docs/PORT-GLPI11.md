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

- **03/09 — correção dos direitos por perfil** (commit daqui: ver `CHANGELOG` 0.5.6).
  Três mudanças pequenas, mas **confira se o repo 11 tem os mesmos defeitos** antes de
  portar cegamente — o `Profile` do GLPI 11 pode devolver outro tipo em `getFormURL()`:
  - `Profile::getFormURL()->__toString()` → `Profile::getFormURL()` nos **dois**
    `Profile` (lá `src/Profile.php` de cada plugin). No GLPI 10 o método devolve
    **string** e o `->__toString()` era fatal, deixando a aba de direitos em branco.
  - `servicereports`: a matriz deixou de usar `'itemtype' => Menu::class` (a `Menu` é um
    `CommonGLPI` e **não tem `getRights()`**, que é de `CommonDBTM`) e passou a declarar
    `'rights' => [READ => __('Read')]`; a instalação concede `READ` (não `READ|UPDATE`).
  - `managedservices/front/managedservice.form.php`: o ramo de exibição passou a chamar
    `check($id, READ)` / `check(-1, CREATE)` — sem isso o formulário de **criação** abria
    para quem não tem direito nenhum (o `display()` do core só checa quando há `id`).
  - Ao portar, repita o teste de ponta a ponta: abrir a aba num perfil, **salvar**,
    conferir `glpi_profilerights` e depois zerar o direito e ver menu sumido + `front/`
    negando.
- **03/09 — acesso por bloco no `servicereports`** (mesmo commit acima é o pré-requisito;
  este vem logo depois). O direito único virou **três**
  (`plugin_servicereports_central` / `_financial` / `_analysts`, só `READ`):
  - `Menu` (lá `src/Menu.php`) ganha as constantes `RIGHT_*` e `RIGHT_LEGACY`, o
    helper `rights()`, `getVisibleBlocks()`, um `'right'` por bloco em `getBlocks()`,
    `canView()` = "pelo menos um" e `$rightname = ''`.
  - `Profile::install()` monta a matriz a partir do `getBlocks()` e faz a **migração**:
    lê quem tinha o direito antigo (>0) **antes** de mexer, adiciona só os direitos que
    faltam, copia o acesso para os três e apaga o antigo; o ramo "instalação nova"
    concede ao Super-Admin. Idempotente, porque o GLPI chama a mesma `install()` na
    atualização.
  - `front/central.php` filtra os cards e usa `Menu::canView()`; os outros três
    `front/*.php` checam o direito do seu bloco.
  - **A versão lá também tem de subir** (o repo 11 está em 0.5.0): sem isso o GLPI não
    roda a atualização e a migração dos direitos nunca acontece.
  - Teste: as seis combinações de direito (três, cada um sozinho, dois e nenhum) contra
    menu + submenu + cards + acesso direto; a migração com o direito antigo em vários
    perfis; e uma instalação limpa.

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
