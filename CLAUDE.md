# CLAUDE.md — guia para o próximo agente

> Instruções operacionais deste repositório. Objetivo e proveniência estão no
> [README.md](README.md); o histórico detalhado das mudanças está no
> [CHANGELOG.md](CHANGELOG.md).

## O que é este repositório

Dois plugins GLPI **para GLPI 10.0.x**, reimplementados por engenharia reversa da
UI web da instância gerenciada da Verdana (`https://instant.verdanadesk.com`),
para a migração do GLPI da **Instant Tecnologia** para uma VM própria:

- **`managedservices`** — "Serviços Gerenciados" (menu **Ativos**). Clone do plugin
  interno `vservices`. Objeto principal + abas Gerência, Ativos cobertos,
  Composição, Financeiro, Configuração NMS.
- **`servicereports`** — "Relatórios" (menu **Gerência**). 3 blocos do `vreports`:
  Central de serviços, Gestão financeira (lê o `managedservices`), Analistas.

> **A versão para GLPI 11** está no repositório separado
> **`instant-glpi11-plugins`** (classes namespaced em `src/`). Mantenha as duas
> em paridade funcional ao mexer na lógica.

## Convenção do projeto (GLPI 10)

- Classes **planas** `PluginManagedservicesX` / `PluginServicereportsX` em
  `inc/*.class.php` (autoload do GLPI 10). Tabelas `glpi_plugin_<key>_<classe_plural>`.
- Páginas em `front/*.php` com `include('../../../inc/includes.php')`.
- Direito por plugin registrado em `glpi_profilerights` (`plugin_managedservices`,
  `plugin_servicereports`); Super-Admin recebe acesso total na instalação.

## Como desenvolver e **TESTAR** (não pule o teste real)

Sempre valide num **GLPI local de verdade**, não só com `php -l`. Bugs reais
(assinatura de método, API de dropdown, CSS do tema) só aparecem rodando.

Ambiente local (na máquina de desenvolvimento — pasta `.testenv/`, **gitignored**):
- **MariaDB** via Homebrew: `brew services start mariadb`. Banco `glpitest`,
  usuário `glpi`/`glpi`.
- **GLPI 10.0.26** em `.testenv/glpi` (baixado de `glpi-project/glpi` releases).
- Servidor: `PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8088 -t .testenv/glpi`
  (GLPI 10 serve a partir da raiz). Login `glpi`/`glpi`.
- **Sync:** `.testenv/sync.sh` **copia** (não faz symlink — symlink quebra o
  `include` relativo) os plugins do repo para `.testenv/glpi/plugins/`.

Fluxo por mudança:
1. Editar código → `bash .testenv/sync.sh` → recarregar a página.
2. Mudança de **schema** (tabelas): reinstalar —
   `php bin/console plugin:deactivate managedservices`,
   `plugin:uninstall managedservices -n`, `plugin:install managedservices --username=glpi`,
   `plugin:activate managedservices`.
3. Para inspecionar telas, use um navegador apontando para `http://127.0.0.1:8088`.
4. **Sessão sem digitar senha** (útil para automação): crie um
   `.testenv/glpi/devlogin.php` (fora do repo) que faz
   `Session::init()` com um `Auth` marcado como `auth_succeded` e o usuário `glpi`
   carregado por `getFromDBbyName()`, e redirecione para a tela a testar. Para testar
   com escopo de entidade, chame `Session::changeActiveEntities($id, false)` ou
   `Session::changeActiveEntities('all')` antes do redirect.
5. **Teste sempre nas duas condições de entidade**: raiz vendo tudo *e* sessão restrita a
   uma entidade filha. Vários bugs (ver `getEntitiesRestrictRequest` abaixo) só aparecem
   na segunda. Dados de exemplo: `.testenv/seed-test-data.sql`.
6. Depois de mexer em entidades/categorias **por SQL direto**, rode
   `php bin/console cache:clear` — o GLPI cacheia as árvores (`sons_cache`) e você pode
   concluir errado que uma correção não funcionou.

Recriar o ambiente do zero: ver [CHANGELOG.md](CHANGELOG.md) e os comandos acima.

## Deploy em produção (Instant)

O GLPI de produção fica em **`/var/www/instant/glpi`** na VM `vm-glpi-02` — **não** em
`/var/www/glpi` nem `/var/www/html/glpi`. Atualizar é copiar as pastas do clone e
recarregar (sem mudança de schema, não reinstale nada):

```bash
cd /tmp/instant-glpi-plugins && git pull && sudo cp -r managedservices servicereports /var/www/instant/glpi/plugins/ && sudo chown -R www-data:www-data /var/www/instant/glpi/plugins/managedservices /var/www/instant/glpi/plugins/servicereports
```

**Lição cara (2026-08-19):** três correções seguidas pareceram "não fazer efeito" porque
o `cp` para o caminho errado falhava com `No such file or directory` enquanto o
`git pull` rodava normalmente e mascarava o problema. **Sempre confirme a cópia**
(`ls -l` no arquivo alterado) antes de investigar o código. Se o arquivo estiver certo e
a tela não mudar, o suspeito seguinte é o **opcache** (`sudo systemctl restart php8.1-fpm`).

## Armadilhas do GLPI 10 já descobertas (não repita)

- `getTabNameForItem()` é método de **instância** (NÃO `static`);
  `displayTabContentForItem()` é `static`.
- Dropdown **múltiplo** (`User::dropdown`/`Group::dropdown`/`Dropdown::show`):
  passe **`'value' => $array`**, não `'values'` (o GLPI faz `values = value`).
- Dropdown de **árvore** (`ITILCategory`, `Location`, …): sem
  **`'permit_select_parent' => true`** o GLPI marca as categorias-pai como `disabled`
  (viram só rótulo da hierarquia) e o usuário não consegue escolhê-las. Mantenha o
  dropdown nativo — trocá-lo por lista plana (`Dropdown::showFromArray` com
  `completename`) foge do padrão da UI e foi revertido em 2026-08-19.
- Menu em seção do core: `$PLUGIN_HOOKS['menu_toadd'][$key] = ['assets' => Classe]`
  (ou `'management'`).
- A classe utilitária `.small` do tema quebra texto (largura mínima) em HTML
  próprio — use `font-size` inline.
- DB: `$DB->doQuery()`, `new Migration(VERSION)` + `executeMigration()`,
  `$DB->tableExists()`.
- `getEntitiesRestrictRequest('AND', $x)`: `$x` tem de ser **o nome usado na consulta**
  — se o `FROM` aliasa a tabela (`FROM tabela ms`), passe o **alias**. Com sessão na
  entidade raiz vendo tudo o GLPI devolve string vazia e o erro **não aparece**; ele só
  surge quando a sessão está restrita a uma entidade. Teste sempre nas duas situações.

## Acoplamento

`servicereports` (Gestão financeira) lê `glpi_plugin_managedservices_financialvalues`
e `..._managedservices`. **Instale/desenvolva o `managedservices` primeiro.**

## Ressalvas (partes Verdana-específicas, não reproduzíveis do core)

- **Analistas › Deslocamentos** e **Mapas**: dependem de fontes de dados
  não-nativas (distância de deslocamento; geolocalização). Ficam com estrutura +
  nota honesta.
- **Analistas › Pontos** = chamados solucionados (aproximação; fórmula original
  desconhecida).
- **Horas fora de expediente**: jornada fixa **Seg–Sex 08:00–18:00** (bateu com o
  exemplo da Verdana); pode ser ligada ao calendário do GLPI depois.

## Relatórios de Analistas — cuidados (aprendido em produção)

Os plugins já rodam em **produção** no GLPI 10 da Instant
(`suporte.instanttecnologia.com.br`); estes ajustes vieram de dados reais:

- **Período por `tt.date`, não `tt.begin`**: tarefas do GLPI só têm `begin`/`end` quando
  *planejadas*; a maioria não é. Filtrar por `begin` deixa o relatório vazio em produção.
  Vale para todas as consultas de tarefa em `Analysts` (já corrigido).
- No relatório de tarefas, **"Categoria" = categoria do chamado** (`glpi_tickets.itilcategories_id`),
  não `taskcategories_id` (quase sempre vazia). Número do chamado é link.
- **Dropdown "Técnico"** lista **todos** os técnicos do GLPI via
  `getAllTechnicians()` (`User::getSqlSearchResult(false, 'own_ticket')`), não só os com
  atividade no período; `getTechnicians($start,$end)` continua sendo a base dos cartões
  de performance. Técnico escolhido sem atividade aparece com o cartão zerado.
- **Export CSV** fica em `front/analysts.php`, antes do `Html::header` (com `exit`). No
  `fputcsv` passe o `$escape` explícito (`''`) — PHP 8.4+ deprecou o default — e rode os
  valores por `html_entity_decode` (o GLPI devolve texto HTML-escapado, ex.: `&#62;`).

## Relatórios de Gestão financeira — cuidados

A sub-aba **Relatórios** (`front/financial.php` + `inc/financial.class.php`) tem 2
relatórios (ids do original **1/2**; o **4 — "Fatura de serviços detalhada" — foi
removido** em 2026-08-25 a pedido da Instant); segue o mesmo padrão do `analysts.php`
(seletor `report` com `on_change=this.form.submit()`, filtro `start_date`/`end_date`,
CSV antes do `Html::header` com `exit`).

- **Tempo de tarefas por `tt.date`** (mesma lição do Analistas), não `tt.begin`.
- **Chamados vinculados ao serviço** = por `glpi_tickets.itilcategories_id` = categoria
  do serviço **e/ou** por ativo coberto (`glpi_items_tickets` ↔ `coveredassets`).
- **Valor de ativos** = casa `perclass` (itemtype `ComputerType`/`MonitorType`/… + id do
  tipo) com o `…types_id` do ativo coberto (ver `assetTypeField()`).
- **Sem modelo de dados** (batem com os zeros do demo Verdana): "valor por **categoria**
  de chamado" e "**extras** relacionados a chamados" ficam R$ 0,00 — estrutura honesta.
- A rota `&pdf=1` (visão de impressão via `Html::popHeader`/`popFooter` +
  `window.print()`) hoje só atende o **Extrato**.
- **Categoria do serviço = subárvore inteira**: use `categoryTreeIds()` (baseado em
  `getSonsOf('glpi_itilcategories', $id)`), nunca igualdade simples — um serviço em
  "Suporte Avançado" tem de somar "Suporte Avançado > Active Directory > … > GPO".
- **Serviço `is_recursive` cobre as entidades filhas**: `linkedTicketIds()` usa
  `getSonsOf('glpi_entities', …)` nesse caso; sem isso um serviço na entidade-pai não
  enxerga chamado nenhum das filhas e o extrato sai zerado.
- **KPIs**: "Receita prevista" (e o gráfico por entidade) somam só valores **recorrentes**
  (`value_type <> 'hourly'`). Valor/hora é tarifa: vira dinheiro no extrato, multiplicado
  pelas horas de tarefa.
- **Tela × PDF**: `renderExtrato()` é a versão de tela (botões CSV/PDF, nº do chamado como
  link); `renderExtratoPrint()` é a de impressão (logo em `pics/instant-logo.png`, título
  centralizado em 3 linhas, `@page landscape`, uma empresa por página, **sem links**).
  Ambas compartilham `renderServiceBlock($svc, $print)` e `renderEntitySummary()`.
- **Nome da entidade** no extrato é o **curto** (`entityName()`, só a folha) — vale para
  tela, PDF e CSV. O gráfico do Dashboard segue com o completename.
- **Durações**: resumos por extenso (`duration()` → `Html::timestampToString(..., false, false)`,
  em horas, não dias); a coluna Horas da tabela de chamados fica em `HH:MM:SS` (`hms()`).
- Sem mudança de schema → atualizar é só `sync.sh` + recarregar (não reinstalar).

## Paginação dos relatórios

`PluginServicereportsPager` (`servicereports/inc/pager.class.php`) — 10 itens por página
(`PER_PAGE`), offset no parâmetro `start` da URL (convenção do core). `offset($total)`
normaliza o valor recebido; `show($base, $params, $offset, $total)` desenha a barra.
Regras: os **totais** do cabeçalho são sempre do período inteiro (no relatório 57 vêm de
uma consulta agregada separada, com `LIMIT/OFFSET` só nas linhas); **CSV e impressão/PDF
nunca paginam**; os formulários de filtro não enviam `start`, então filtrar volta à 1ª página.

## O que falta (Fase 7)

Traduções `.mo` (hoje os textos saem em pt-BR direto pelos `__()`), refino de ícones.
Rebuild dos `dist/*.zip` antes do deploy (o `zip -rq dist/<plugin>.zip <plugin>` já é o
suficiente). A **paridade com o repo GLPI 11** (`instant-glpi11-plugins`) estava em dia
desde 2026-08-19 (versão 0.2.0 lá também), mas **saiu de sincronia em 2026-08-25**: a
remoção do relatório 4 ("Fatura de serviços detalhada") ainda **não foi portada** para lá.
Ao mexer na lógica aqui, porte lá na sequência. Detalhe do port: no GLPI 11 os assets estáticos do plugin ficam em
`<plugin>/public/` (a logo do PDF virou `servicereports/public/pics/instant-logo.png`);
aqui continuam em `pics/`.

Sem modelo de dados no `managedservices` (e por isso sempre R$ 0,00): **valor por
categoria de chamado** e **extras relacionados a chamados**. Se a Instant quiser esses
números de verdade, é preciso criar a dimensão no plugin de Serviços Gerenciados.

## Referências

- [docs/recon/](docs/recon/) — modelo de dados, KPIs e telas mapeados.
- [docs/INSTALL.md](docs/INSTALL.md) / `docs/INSTALL.pdf` — instalação via git clone.
- `dist/*.zip` — pacotes prontos dos plugins.
