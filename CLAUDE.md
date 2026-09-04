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
  Central de serviços (Dashboard + Relatórios), Gestão financeira (lê o `managedservices`),
  Analistas.

> **A versão para GLPI 11** está no repositório separado
> **`instant-glpi11-plugins`** (classes namespaced em `src/`). Mantenha as duas
> em paridade funcional ao mexer na lógica.

## Convenção do projeto (GLPI 10)

- Classes **planas** `PluginManagedservicesX` / `PluginServicereportsX` em
  `inc/*.class.php` (autoload do GLPI 10). Tabelas `glpi_plugin_<key>_<classe_plural>`.
- Páginas em `front/*.php` com `include('../../../inc/includes.php')`.
- Direitos registrados em `glpi_profilerights`: **um** no `managedservices`
  (`plugin_managedservices`, com a matriz padrão Ler/Atualizar/Criar/Expurgar) e
  **três** no `servicereports`, um por bloco (`plugin_servicereports_central`,
  `..._financial`, `..._analysts`, só **Ler**) — ver "Direitos do servicereports".
  Super-Admin recebe tudo na instalação.

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
   **Cuidado com o `to=`:** `Html::redirect()` escapa o `&` como `&#38;`, então uma URL de
   destino com **vários** parâmetros chega truncada (só o primeiro sobrevive) e você acaba
   olhando a tela errada achando que o filtro não funciona. Faça em duas requisições:
   primeiro o `devlogin.php` (só para criar a sessão), depois a URL completa do relatório,
   reaproveitando o cookie (`curl -c/-b`).
5. **Teste sempre nas duas condições de entidade**: raiz vendo tudo *e* sessão restrita a
   uma entidade filha. Vários bugs (ver `getEntitiesRestrictRequest` abaixo) só aparecem
   na segunda. Dados de exemplo (todos gitignored, em `.testenv/`): `seed-test-data.sql`
   (base), `seed-status-tech.sql` (chamados com técnico atribuído, para os relatórios 61 e
   60), `seed-update-report.sql` e `seed-groups.sql` (grupos + variação de tipo
   Incidente/Requisição, para os relatórios 4 e 61). **Teste também o caso vazio** — um
   período sem chamado nenhum já revelou um PDF quebrado que a tela não mostrava.
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

**Metadados do plugin (nome, autor, versão) não se atualizam sozinhos** (descoberto em
2026-08-28): o GLPI só reescreve a linha de `glpi_plugins` quando a **versão** muda
(`Plugin::checkPluginState()`). Trocar só o `author` no `setup.php` não muda nada na lista
de plugins de quem já tem o plugin instalado — ou você atualiza a linha na mão
(`UPDATE glpi_plugins SET author='…' WHERE directory IN ('managedservices','servicereports');`)
ou sobe a versão, e aí o GLPI **desativa** o plugin e exige o processo de atualização.
Instalação nova lê tudo do `setup.php`. (Desde 03/09 **os dois plugins estão em
`0.5.8`**, alinhados com o CHANGELOG: a migração dos direitos por bloco exigiu a subida
no `servicereports`, e o `managedservices` foi junto — o que, de brinde, faz o campo
`author` finalmente aparecer sem `UPDATE` manual. Da próxima vez que subir a versão,
lembre do efeito colateral: o GLPI desativa o plugin e exige o passo "Atualizar".)

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
- `Profile::getFormURL()` devolve **string** (`Toolbox::getItemTypeFormURL()`), não
  objeto: `getFormURL()->__toString()` é fatal. Como a aba de direitos já tinha impresso
  a `<div>` de abertura, ela ficava **em branco, sem erro na tela** — a única pista era o
  `files/_log/php-errors.log`. Aba que "não abre" pede olhar esse log antes do código.
- Matriz de direitos: `Profile::displayRightsChoiceMatrix()` com `'itemtype' => X` chama
  `X::getRights()`, que é de **`CommonDBTM`**. Para uma classe que estende só
  `CommonGLPI` (como a `PluginServicereportsMenu`), passe **`'rights' => [READ => …]`**.
- `CommonGLPI::display()` só checa o direito quando há **`id` na URL** — o formulário de
  criação (`form.php` sem id) abre para qualquer um. Nos `front/*.form.php`, faça o
  `check($id, READ)` / `check(-1, CREATE)` explícito, como nos formulários do core.

## Direitos do `servicereports` (um por bloco)

Cada bloco tem o **seu** direito, então um perfil pode ver só "Analistas", só a
"Gestão financeira", etc. (pedido de 03/09; até a 0.5.5 era um direito só,
`plugin_servicereports`, tudo ou nada).

- A fonte da verdade é **`PluginServicereportsMenu::getBlocks()`**: cada bloco carrega
  o seu `'right'`. Quem consome: o menu lateral (`getMenuContent()` usa
  `getVisibleBlocks()`), a grade de cards em `front/central.php` e o
  `Session::checkRight()` no topo de cada `front/*.php`. **Bloco novo = mais uma entrada
  ali e mais um direito** em `PluginServicereportsMenu::rights()`; a matriz de perfis se
  monta sozinha a partir do `getBlocks()`.
- `PluginServicereportsMenu::canView()` é "**pelo menos um** dos três" — é o que decide
  se a entrada "Relatórios" aparece em Gerência e se `central.php` abre.
  `$rightname` da classe fica **vazio de propósito**: não existe um direito único que
  responda pelo menu.
- **Migração (`Profile::install()`)**: o GLPI chama a mesma função na atualização do
  plugin, então ela é idempotente — lê quem tinha o direito antigo (**antes** de mexer
  em qualquer coisa), registra só os direitos que faltam (`addProfileRights()` **não**
  protege contra duplicata e a tabela tem `UNIQUE (profiles_id, name)`), copia o acesso
  para os três e **apaga** o direito antigo. Instalação nova (sem o direito antigo) cai
  no outro ramo e concede ao Super-Admin. **Não remova esse ramo de migração** enquanto
  houver instalação em produção que ainda não passou pela 0.5.6.
- Por causa disso a **versão do `servicereports` subiu** (hoje os dois estão em
  `0.5.8`): o GLPI só roda a atualização quando a versão muda — e, ao mudar, **desativa o plugin** e exige o passo "Atualizar" na tela de
  plugins (ou `plugin:install servicereports -f` + `plugin:activate` no console).

## Separação por entidade nas abas do serviço (regra de ouro nº 2)

O GLPI é **multi-entidade** e a Instant atende vários clientes no mesmo GLPI: o direito
`plugin_managedservices` diz *se* a pessoa mexe em serviço gerenciado, **não em qual**.

- **`Session::checkRight()` sozinho num handler de aba é bug de segurança.** Toda escrita
  passa por **`PluginManagedservicesManagedservice::checkService($sid, $right)`** — que é o
  `check()` do core e aplica direito **e** entidade — ou, quando a operação endereça uma
  **linha filha**, por **`checkChild($obj, $id, $right, $fk)`**, que carrega a linha e tira
  o serviço-pai **do banco**. Nunca confie no id de serviço que veio no POST junto do id da
  linha: são dois valores independentes e o atacante controla os dois.
- Em 03/09 isso foi **explorado** no ambiente local: sessão restrita a uma entidade apagou
  o valor financeiro de um serviço de outra, via `POST` direto no
  `financialvalue.form.php`. O objeto **pai** já estava protegido — o buraco eram as cinco
  abas.
- **Tabela filha precisa de `entities_id`.** Sem a coluna, `isEntityAssign()` devolve
  `false` e o core **não restringe nada** — inclusive a **API REST**, que lista a tabela
  inteira para qualquer entidade. As 5 filhas ganharam `entities_id`/`is_recursive` como
  **espelho** do serviço: `stampEntity()` carimba em toda escrita
  (`prepareInputForAdd/Update`), `post_updateItem()` do pai propaga quando o serviço muda
  de entidade, e `inheritEntity()` faz a migração no `install()` (idempotente, roda também
  na atualização). A fonte da verdade continua sendo o serviço — se um dia divergirem,
  reinstale/atualize o plugin e o `inheritEntity()` ressincroniza.
- **Como testar** (é o teste que pegou o bug): duas entidades irmãs com um serviço cada,
  `devlogin.php?entity=<A>`, e um `POST` com o id do objeto de **B**. Tem de dar "Acesso
  negado" nas cinco operações — apagar valor, criar valor, trocar gerentes, trocar NMS,
  expurgar ativo coberto — e o dado de B tem de continuar lá.

## Escape de saída (regra de ouro, aprendida com um XSS real)

**Todo texto vindo do banco que vai para o HTML passa por
`PluginServicereportsChart::esc()`** — nome de entidade, grupo, categoria, usuário,
título de chamado, `getUserName()`, `Dropdown::getDropdownName()`. Sem exceção, inclusive
dentro de atributo.

- **Por que não confiar no banco:** o GLPI 10 escapa na entrada (`Toolbox\Sanitizer`), e
  por isso durante muito tempo imprimir `$row['name']` cru "funcionou". **O GLPI 11
  removeu o `Sanitizer`** e guarda o texto cru — o mesmo código virou **XSS armazenado
  lá** (03/09: uma entidade chamada `<b>x</b>` saía crua no `<td>` do relatório 5). A
  correção foi aplicada nos **dois** repos: aqui é defesa em profundidade, lá era falha
  de verdade.
- `esc()` = `htmlspecialchars(plain($v), ENT_QUOTES)`, e `plain()` faz
  `html_entity_decode(strip_tags($v))`. É **idempotente** com o texto já escapado do
  GLPI 10 (decodifica e reescapa: mesma saída), então dá para usar nos dois sem escape
  duplo — foi conferido (`&amp;lt;` = 0) e o `>` do completename continua saindo como `>`.
- **`Html::cleanInputText()` não serve para isto**: ele escapa **só as aspas**
  (`preg_replace` de `'` e `"`), deixando `<` cru. Servia nos `data-*` porque sem aspas
  não se escapa do atributo — mas é uma garantia frágil demais para se apoiar. Nos
  gráficos os `data-tip-*`/`data-tech`/`aria-label` passaram a usar `esc()`.
- **Onde não se aplica:** PDF (TCPDF recebe texto puro, via `plain()`) e CSV (não é HTML —
  lá o cuidado é o `html_entity_decode`, e fica **pendente** neutralizar fórmula, um campo
  iniciado por `=`/`+`/`-`/`@` é executado pelo Excel).
- Ao criar uma tela nova, a varredura que pega isso é procurar `echo` com `. $var` sem
  `esc(`/`cleanInputText`/`(int)` — mas **cuidado com falso negativo**: uma linha pode ter
  o `cleanInputText` no atributo e a variável crua no corpo
  (`<th title='<escapado>'>` + `$e['name']` cru foi exatamente o caso que escapou da
  primeira varredura). Confira ocorrência a ocorrência, não a linha inteira.

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
- **Relatório 60 — "Entidade vs. Analistas"** (matriz analista × entidade, id 60) segue a
  regra do `tt.date` acima: o período recorta a **tarefa** pela data dela, e entram **todas**
  as tarefas do intervalo — chamado **fechado ou ainda em aberto** (a Instant pediu em
  2026-08-27; antes o recorte era pela `closedate`, na lógica do Extrato, e as tarefas de
  chamado aberto ficavam de fora). Não recorta por serviço gerenciado, então o número
  **não** é o faturável do extrato — a pergunta aqui é "quanto de tarefa foi lançado no
  período". A **última linha** da tela e do CSV imprime o período pedido no filtro
  (`$periodLabel`, em `d/m/Y`); na tela ela fica **fora** da tabela, porque o `<tfoot>` é
  `position: sticky` e cobriria uma segunda linha. Colunas = as
  entidades visíveis na sessão que são **folhas** da árvore (`getVisibleEntities()`,
  campo `is_leaf`), inclusive as zeradas; os nós de **agrupamento** ("Standard",
  "Premium", a raiz) são nível de árvore e não cliente, e ficam de fora — **exceto** se
  tiverem horas no período, e aí a coluna fica para não sumir com hora nenhuma e manter
  os totais conferindo. Não pagina; exporta CSV. Ao mexer nele, lembre que a tela usa `position: sticky` (classes
  `sr-eva`) para fixar cabeçalho e coluna do analista.
- **Relatório 61 — "Chamados por Status e Técnico"** (id 61) é o único do bloco que
  **conta chamados, não tarefas**: o vínculo com o técnico é o ator **Atribuído**
  (`glpi_tickets_users` tipo `ASSIGN`), o recorte do período é a **data de abertura**
  (`glpi_tickets.date`) e o status é o **atual** — regra do card nativo do GLPI
  que a Instant usou de modelo (27/08). Chamado com dois atribuídos conta para **cada um**;
  a soma das barras pode passar o nº de chamados, e o texto de ajuda diz isso. O eixo X
  traz só os técnicos com chamados no período.
  São **dois gráficos de barras empilhadas** com a mesma cara, um embaixo do outro
  (28/08): por **status** e por **tipo** (Incidente × Requisição). Os dois contam os
  **mesmos chamados** — mesmo recorte, mesmo vínculo, mesmo filtro de status —, só muda a
  quebra da pilha, então **os totais têm de bater** entre eles; se pararem de bater, o bug
  está no `countByTechnician()`. As duas consultas são a mesma:
  `getStatusByTechnician()`/`getTypeByTechnician()` são cascas de **`countByTechnician()`**,
  que recebe a coluna de `glpi_tickets` que vira a pilha e devolve
  `keys`/`legend`/`labels`/`colors` junto dos números — **não** duplique a consulta para
  criar uma terceira quebra.
  O gráfico é **SVG montado no PHP** (**`renderStackedChart($data, $aria)`**, genérico:
  lê a pilha do próprio `$data`), sem biblioteca — o tooltip é um punhado de JS lendo os
  `data-*` dos `<rect>` (monte o conteúdo com `textContent`; `innerHTML` com nome de
  usuário é convite a XSS) e se prende a **todos** os `.sr-cst-wrap` da página, não só ao
  primeiro. Cores e ordem das pilhas ficam em `STATUS_ORDER`/`STATUS_COLORS` e
  `TYPE_ORDER`/`TYPE_COLORS` (estas últimas são a NAVY/STEEL do "Relatório de atualização
  - Cliente", de propósito), usadas **também** pelo PDF — mexeu numa, a outra acompanha.
- **PDF do 61** (`inc/statustechpdf.class.php`, rota `&pdf=1` em `front/analysts.php`):
  A4 paisagem, **uma seção por folha** (status e depois tipo), cada uma com o gráfico
  redesenhado com primitivas do TCPDF + tabela. As larguras da tabela saem de `cols($n)`
  (6 status de 29mm ou 2 tipos de 87mm, sempre somando os 277mm da folha). O **título da
  seção** vai no cabeçalho e tem de ser trocado **antes** do `AddPage`; o **rodapé** fica
  com o nome do relatório de propósito — o TCPDF chama o `Footer` da folha anterior
  *dentro* do `AddPage`, ou seja depois da troca, e a folha do status sairia com o rótulo
  do tipo. Três armadilhas já pagas: **`SetXY()` com x negativo** no TCPDF quer dizer "a partir da direita" (o rótulo
  girado da 1ª barra sumia — a célula vai de `x=0` até o pé da barra, alinhada à direita);
  **`Cell()` não quebra linha** (o cabeçalho da tabela precisa de `MultiCell`, senão
  "EM ATENDIMENTO (ATRIBUÍDO)" invade a coluna vizinha); e o `ob_start()`/`ob_end_clean()`
  em volta do `build()`, igual ao extrato.
- **Dropdown "Técnico"** lista **todos** os técnicos do GLPI via
  `getAllTechnicians()` (`User::getSqlSearchResult(false, 'own_ticket')`), não só os com
  atividade no período; `getTechnicians($start,$end)` continua sendo a base dos cartões
  de performance. Técnico escolhido sem atividade aparece com o cartão zerado.
- **Export CSV** fica em `front/analysts.php`, antes do `Html::header` (com `exit`). No
  `fputcsv` passe o `$escape` explícito (`''`) — PHP 8.4+ deprecou o default — e rode os
  valores por `html_entity_decode` (o GLPI devolve texto HTML-escapado, ex.: `&#62;`).

## Central de serviços — sub-aba Relatórios

`front/servicecentral.php` tem duas sub-abas: **Dashboard** (os KPIs do mês, que já
existiam) e **Relatórios**, com **quatro** relatórios no seletor:
**1 — "Relatório central de serviços"**,
**2 — "Relatório de atualização - Cliente - ANUAL"**,
**3 — "Relatório de atualização - Cliente - MENSAL"**,
**4 — "Chamados por grupo"** e
**5 — "Chamados por entidade"**.
Os três primeiros têm 7 seções na tela e 7 páginas no PDF; o 4 tem 3 (capa, gráfico e
tabela) e o 5 tem 3 na tela e 1 no PDF. Todos exportam CSV e PDF.

### Relatório central de serviços (id 1)

Capa, total de atendimento, atendimento diário, top 7 categorias, SLA não conformidade,
SLA nível de serviço e top 10 requerentes.

- **Os gráficos são SVG montados no PHP** por `PluginServicereportsChart`
  (`inc/chart.class.php`): `line()`, `bars()` (agrupadas), `hbars()` e `donut()`. O
  tooltip é único para todos — qualquer elemento com `data-tip-title`/`data-tip-body`
  acende a caixinha; **monte o conteúdo com `textContent`**, nunca `innerHTML` (tem nome
  de usuário ali dentro). O `assets()` só emite CSS/JS uma vez por página.
- **`PluginServicereportsCentralpdf`** (`inc/centralpdf.class.php`) **redesenha** os
  mesmos gráficos com primitivas do TCPDF a partir do mesmo array
  (`Servicecentral::getReport()`) — mexeu num, mexa no outro. Armadilhas próprias:
  `PieSector()` precisa de **`$cw=true`** (com `false` a rosca sai anti-horária e o rótulo
  cai na fatia errada); o período sai em **`d/m/Y` fixo**, não `Html::convDate()`, que é
  preferência de cada usuário; e o `SetXY()` com x negativo do rótulo girado é a mesma
  armadilha do relatório 61.
- **Definições** (fechadas com a Instant em 27/08): "aberto" = `glpi_tickets.date`;
  "encerrado" = tem `solvedate` no período (**inclui os já Fechados** — por isso encerrados
  pode passar abertos); "fora do SLA de solução" = `solvedate > time_to_resolve`, **sem**
  somar `sla_waiting_duration` (o GLPI já empurrou o `time_to_resolve` ao sair do
  Pendente — somar de novo conta o mesmo tempo duas vezes), chamado **sem SLA** conta como
  dentro do prazo; "fora do SLA de atendimento" = assumido depois do `time_to_own` (ou não
  assumido com prazo vencido), pelo `takeintoaccountdate` com fallback em
  `takeintoaccount_delay_stat`. **Requerente** no Top 10 não é só usuário cadastrado:
  entra também quem abriu por e-mail sem cadastro, pelo
  `glpi_tickets_users.alternative_email` com `users_id=0` (pedido da Instant, 27/08).
- Período longo: `dayLabels()` trava em 800 dias e os gráficos diários passam a mostrar
  1 rótulo a cada N. Não pagina; exporta CSV (um arquivo, seções separadas por linha em
  branco) e PDF.

### Relatório de atualização - Cliente — ANUAL (id 2) e MENSAL (id 3)

Reimplementação do deck que a Instant entrega ao cliente
(`files/Atualização - <Cliente> <data>.pptx`), em **duas variantes que são o mesmo
código**: 7 seções na tela, 7 páginas no PDF — capa, relatório de atendimentos (tabela de
totais por status + legenda), chamados por mês/dia (Incidente × Requisição), chamados por
tipo (tabela do bucket + rosca), top 5 categorias, abertos × fechados por mês/dia e
chamados por horário.

- **A diferença entre ANUAL e MENSAL é só a granularidade** das duas séries temporais:
  `PluginServicereportsUpdatereport::GRAIN_MONTH` × `GRAIN_DAY`. Toda consulta de série
  agrupa pela expressão de `bucketExpr()` — **não** crie uma segunda classe para a
  variante nova. O ANUAL abre com os últimos 12 meses; o MENSAL, no mês corrente.
- **"Fechado" aqui é `closedate`**, não `solvedate`. O relatório central, na mesma tela,
  usa `solvedate` para "encerrado". Os dois convivem de propósito — lá a pergunta é
  "quando foi resolvido", aqui é "quando saiu da fila". Não unifique sem falar com a
  Instant.
- **A tabela de status tem de fechar com o total da capa.** Os quatro status do deck
  (Atribuído, Pendente, Solucionado, Fechado) saem sempre, mesmo zerados; "Novo" e "Em
  atendimento (planejado)" entram **só quando têm chamado**. A grafia dos nomes vem de
  `statusNames()` (a do deck, não a do core), porque a legenda ao lado usa as mesmas
  palavras.
- **O deck tem uma linha de backlog** sobre as barras de abertos × fechados; ela foi
  **retirada** a pedido da Instant (confundia a leitura), e com ela saíram o `comboLine()`
  do `chart.class.php` e o `drawCombo()` do PDF. Se voltar: era a fila acumulada a partir
  dos chamados abertos antes do período e ainda não fechados na véspera — e, com essa
  definição, ela não desce de zero (o −9 do deck vinha de outra base de cálculo).
- **`PluginServicereportsUpdatepdf` estende `PluginServicereportsCentralpdf`**: cabeçalho,
  rodapé, `startSection()`, `grid()`, `drawBars()`, `drawHBars()` e `drawDonut()` vêm de
  lá (por isso são `protected`, e o título é o `$reportTitle` do construtor). Ao mexer no
  `centralpdf`, lembre que os **três** relatórios usam aquele código.
- O `drawDonut()` herdado põe a legenda em x=40 e passaria por cima da tabela do bucket:
  a rosca desta seção é o `drawTypeDonut()`, na metade direita, com legenda por baixo.
  A tabela do bucket tem **altura de linha adaptativa** — no MENSAL são até 31 linhas.
- O eixo X de "Chamados por horário" traz **só as horas com chamado** (as 24 deixariam
  metade do eixo vazio).

### Chamados por grupo (id 4)

Pedido da Instant em 28/08: "em determinado intervalo, os chamados por grupo". Capa,
gráfico de barras horizontais e tabela (grupo, chamados, % do total) — `inc/groupreport.class.php`
(dados) e `inc/groupreportpdf.class.php` (PDF, que **estende o `centralpdf`**: mexeu lá,
lembre que são **quatro** relatórios usando aquele código).

- **Grupo = ator *Atribuído*** (`glpi_groups_tickets` com `type = ASSIGN`), o mesmo campo
  que aparece no chamado ao lado do técnico. Grupo **requerente** e **observador** não
  entram — se um dia precisarem, é outra seção, não um filtro a mais nesta.
- **Período pela data de abertura** (`glpi_tickets.date`), como no relatório 61 e no
  "aberto" do relatório central; o status não filtra nada.
- **Chamado com dois grupos conta nos dois**, então a **soma das linhas** pode passar os
  chamados do período. Por isso a capa mostra os chamados **distintos** e o rodapé da
  tabela mostra a **soma** — e o **percentual é sobre a soma**, para a coluna fechar em
  100%. Se alguém reclamar que "o total não bate com a capa", é isso.
- **"Sem grupo atribuído"** é sempre a **última** linha, fora da ordenação: ordenada junto
  costuma ser a maior barra e esconde o ranking das filas de verdade.
- O nome é o **completename** do grupo e **subgrupo não soma no pai** ("Projetos > Redes"
  é uma linha própria) — vale a fila que está no chamado.
- Os títulos das duas seções (`chartTitle()`/`tableTitle()`, na classe de dados) são
  compartilhados por tela e PDF e **não repetem o nome do relatório**: no PDF ele já sai
  no subtítulo do cabeçalho de toda folha.
- O `centralpdf` tem `SetAutoPageBreak(false)` (uma seção por folha), então a tabela quebra
  **na mão** — com muitos grupos ela continua na folha seguinte com o cabeçalho repetido.

### Chamados por entidade (id 5)

Pedido da Instant em 28/08, com um print do relatório 61 e "Entidade A / Entidade B"
escritos por cima do eixo: **é o gráfico do relatório 61 com entidade no lugar de
técnico**. Por isso ele **não** tem código de gráfico próprio —
`inc/entityreport.class.php` só monta os dados no formato que o
`PluginServicereportsAnalysts::renderStackedChart()` consome (`keys`/`legend`/`labels`/
`colors`/`rows`), e o PDF (`inc/entityreportpdf.class.php`) **estende o
`statustechpdf`**. Mexeu na aparência do 61, este acompanha de graça — e vice-versa.

- **Todos os chamados, em qualquer status**: não há filtro de status na consulta (a pilha
  é só a quebra visual). Recorte pela **data de abertura**.
- **Sem soma na árvore**: o chamado conta **uma vez**, na entidade em que foi aberto
  (`glpi_tickets.entities_id`) — "Instant > Standard > Uniletra" **não** soma em
  "Standard". Assim a soma das barras é o total de chamados do período. Se a Instant
  pedir o acumulado da árvore, é `getSonsOf('glpi_entities', …)` — mas aí a soma passa do
  total e o rodapé precisa dizer isso.
- Entram **só as entidades com chamado** (não é a lista de entidades visíveis, como no
  relatório 60), ordenadas pelo **completename** — irmãs saem juntas no eixo, como no
  modelo da Instant (que não ordena por tamanho de barra).
- O eixo usa o nome **curto** da entidade (`glpi_entities.name`) e a tabela o **completo**
  (`completename`): no SVG o rótulo é cortado em 22 caracteres e "Instant > Standard >
  Uniletra" viraria "Instant > Standard > U…". No PDF isso é o `$rowLabelKey` do
  `statustechpdf` (a tabela usa `fullname`, o gráfico usa `name`).
- **`PluginServicereportsStatustechpdf` virou base de dois relatórios**: o que é do 61
  está em propriedades (`$metaLabel`, `$metaValue`, `$reportName`, `$subtitle`,
  `$firstCol`, `$rowLabelKey`) e os métodos de desenho são `protected`. A entrada da
  subclasse se chama **`buildEntity()`** e não `build()` porque o PHP não deixa
  sobrescrever um método estático com assinatura incompatível (o pai recebe os **dois**
  conjuntos do 61). **As constantes precisam ser `protected`** — com `private` a subclasse
  não as enxerga, e o erro só aparece no caminho que as usa (aqui, o "período sem
  chamados", que ficou um PDF quebrado até o teste pegar).
- O CSS e o tooltip do gráfico saem de **`PluginServicereportsAnalysts::stackedAssets()`**
  (uma vez por página, chamado pelo `renderStackedChart()`), e não mais da tela: são duas
  telas usando o mesmo gráfico. O tooltip escuta no `document` (delegação), então não
  depende de quantos gráficos existem nem da ordem em que o script é emitido.

## Relatórios de Gestão financeira — cuidados

A sub-aba **Relatórios** (`front/financial.php` + `inc/financial.class.php`) tem 2
relatórios (ids do original **1/2**; o **4 — "Fatura de serviços detalhada" — foi
removido** em 2026-08-25 a pedido da Instant); segue o mesmo padrão do `analysts.php`
(seletor `report` com `on_change=this.form.submit()`, filtro `start_date`/`end_date`,
CSV antes do `Html::header` com `exit`).

- **Período = data de FECHAMENTO do chamado** (regra da Instant, 2026-08-25): o chamado
  entra no extrato do período em que `glpi_tickets.closedate` cai, e leva junto **todas**
  as suas tarefas, sem filtro de data — inclusive as de meses anteriores. Exemplo real:
  chamado aberto em 09/10, tarefas em 11/10 (0:30), 02/11 (0:40) e 04/11 (0:45), fechado
  em 14/11 → **outubro sai vazio** e **novembro soma 1:55**. Chamado em aberto (ou apenas
  *Solucionado*, com `closedate` NULL) não entra em extrato nenhum até fechar. Vale para
  os relatórios **1 e 2** (o 2 deriva do mesmo `getExtrato()`); o bloco **Analistas
  continua por `tt.date`** — lá a pergunta é "quem trabalhou quando", não "o que faturar".
- O total de horas do serviço é a **soma dos chamados listados** (não uma consulta à
  parte) — antes o cabeçalho podia mostrar horas sem nenhum chamado na lista.
- Reabertura: o GLPI sobrescreve `closedate`, então um chamado reaberto e fechado de novo
  **migra de mês**; reemitir um extrato antigo pode dar outro número. Aceito pela Instant
  (não há "congelamento" de período faturado).
- **Chamados vinculados ao serviço** = por `glpi_tickets.itilcategories_id` = categoria
  do serviço **e/ou** por ativo coberto (`glpi_items_tickets` ↔ `coveredassets`).
- **Valor de ativos** = casa `perclass` (itemtype `ComputerType`/`MonitorType`/… + id do
  tipo) com o `…types_id` do ativo coberto (ver `assetTypeField()`).
- **Sem modelo de dados** (batem com os zeros do demo Verdana): "valor por **categoria**
  de chamado" e "**extras** relacionados a chamados" ficam R$ 0,00 — estrutura honesta.
- A rota `&pdf=1` só atende o **Extrato** e devolve um **PDF de verdade** (TCPDF).
- **Categoria do serviço = subárvore inteira**: use `categoryTreeIds()` (baseado em
  `getSonsOf('glpi_itilcategories', $id)`), nunca igualdade simples — um serviço em
  "Suporte Avançado" tem de somar "Suporte Avançado > Active Directory > … > GPO".
- **Serviço `is_recursive` cobre as entidades filhas**: `linkedTicketIds()` usa
  `getSonsOf('glpi_entities', …)` nesse caso; sem isso um serviço na entidade-pai não
  enxerga chamado nenhum das filhas e o extrato sai zerado.
- **KPIs**: "Receita prevista" (e o gráfico por entidade) somam só valores **recorrentes**
  (`value_type <> 'hourly'`). Valor/hora é tarifa: vira dinheiro no extrato, multiplicado
  pelas horas de tarefa.
- **Layout "Institucional"** (escolhido pela Instant em 2026-08-25, entre três propostas):
  faixa de cabeçalho com logo + Empresa/Período/Emissão (`docBand()`), resumo da entidade
  em 4 cartões com o total destacado (`renderEntitySummary()`), os seis valores do serviço
  numa **grade de 6 colunas** (`renderServiceBlock()`) e a listagem com cabeçalho escuro e
  zebra (`renderTicketList()`). Rodapé "Impresso por … em …" (`docFoot()`).
  **O extrato deixou de sair todo em negrito** — o negrito marca só números e títulos.
- **Tela × PDF são dois renderizadores** (mesmo layout, tecnologias diferentes):
  `renderExtrato($extrato, $start, $end, $csv, $pdf)` desenha a tela em HTML (papel branco
  fixo em `.sr-ext`, para não depender do tema do GLPI); **`PluginServicereportsExtratopdf`
  (`inc/extratopdf.class.php`) monta o PDF com TCPDF**. Ao mexer no layout, mexa nos dois.
- **Por que TCPDF e não a impressão do navegador** (trocado em 2026-08-25, no mesmo dia em
  que a versão impressa foi entregue): a rota antiga era `Html::popHeader` + `window.print()`
  e **cortava dados** — as células da listagem tinham `text-overflow:ellipsis` para as
  colunas alinharem, então título e categoria longos chegavam ao papel pela metade. No
  TCPDF cada célula é um `MultiCell` que **quebra em linhas** (a altura da linha vem da
  coluna que precisa de mais linhas, medida com `getNumLines()`), e o cabeçalho/rodapé
  saem em toda folha com **"Página X de Y"** (`getAliasNumPage()`/`getAliasNbPages()`).
  Armadilhas dessa classe, todas já pagas:
  - **`ob_start()`/`ob_end_clean()` em volta do `build()`** em `front/financial.php`: com
    `display_errors` ligado, um aviso do PHP impresso durante a montagem entra **dentro**
    do binário e o PDF não abre. O TCPDF dispara um `Deprecated: imagedestroy()` no PHP 8.5.
  - **Larguras de coluna somam 277mm** (A4 paisagem menos as margens) e as três últimas são
    largas de propósito: `Cell()` **não corta** o que não cabe, transborda — "CUSTO CHAMADO"
    em caixa alta invadia a coluna vizinha.
  - **`plain()`** antes de escrever: o GLPI devolve texto HTML-escapado (`&#62;` nas
    categorias em árvore) e o TCPDF imprimiria a entidade literal.
  - `mb_strtoupper` para caixa alta (o `strtoupper` erra em UTF-8) e `Image()` com o
    **caminho no disco** (o TCPDF não lê URL).
- **CSS num `<style>` só** (`styles()`, emitido uma vez por página, classes com prefixo
  `sr-`): precisa de `:nth-child` (zebra), `@media print` e `thead {display:table-header-group}`
  (repete o cabeçalho da tabela na folha seguinte), que atributo `style` não faz. Três
  detalhes de impressão que não são óbvios: **`print-color-adjust:exact`** ou o Chrome
  descarta o cabeçalho escuro e a zebra; a margem de baixo do `@page` **reserva o espaço**
  do rodapé `position:fixed`; e `table-layout:fixed` + larguras por `th:nth-child()`, senão
  cada tabela da folha escolhe larguras diferentes e as colunas não alinham entre serviços.
- A logo continua em `pics/instant-logo.png` (`logoUrl()` aceita também `logo.png/jpg/svg`).
- **Nome da entidade** no extrato é o **curto** (`entityName()`, só a folha) — vale para
  tela, PDF e CSV. O gráfico do Dashboard segue com o completename.
- **Durações**: resumos por extenso (`duration()` → `Html::timestampToString(..., false, false)`,
  em horas, não dias); a coluna Horas da tabela de chamados fica em `HH:MM:SS` (`hms()`).
- Sem mudança de schema → atualizar é só `sync.sh` + recarregar (não reinstalar).

## Paginação dos relatórios

Os relatórios **60** e **61** não paginam (a matriz inteira / o gráfico *é* o relatório).

`PluginServicereportsPager` (`servicereports/inc/pager.class.php`) — 10 itens por página
(`PER_PAGE`), offset no parâmetro `start` da URL (convenção do core). `offset($total)`
normaliza o valor recebido; `show($base, $params, $offset, $total)` desenha a barra.
Regras: os **totais** do cabeçalho são sempre do período inteiro (no relatório 57 vêm de
uma consulta agregada separada, com `LIMIT/OFFSET` só nas linhas); **CSV e impressão/PDF
nunca paginam**; os formulários de filtro não enviam `start`, então filtrar volta à 1ª página.

## O que falta (Fase 7)

Traduções `.mo` (hoje os textos saem em pt-BR direto pelos `__()`) e refino de ícones —
**os dois deliberadamente adiados**: a interface é entregue em pt-BR e os `.mo` só
importam quando alguém usar o GLPI em outro idioma.
Rebuild dos `dist/*.zip` antes do deploy (`zip -rq dist/<plugin>.zip <plugin>`; confira
descompactando e comparando com a pasta do plugin — os do repo são da 0.5.8, 03/09). O
`docs/INSTALL.pdf` sai do `INSTALL.md` por
**`python3 tools/build-install-pdf.py docs/INSTALL.md docs/INSTALL.pdf`** (ReportLab; o
script cobre só o Markdown que o guia usa — recurso novo, ajuste lá). As **versões** dos
dois plugins já estão alinhadas com o CHANGELOG (`0.5.8`). O GLPI 11 nunca foi instalado numa **VM
real**, só validado local.

**Paridade com o repo GLPI 11** (`instant-glpi11-plugins`): **em dia desde 2026-09-03**,
e agora os **dois repos usam o mesmo número de versão** (`0.5.8`) — mesmo número, mesmo
conjunto de funcionalidades, validado no GLPI 11.0.8. Na leva de 03/09 foram a correção
da aba de direitos e o acesso por bloco; antes disso (28/08, 0.5.0 lá) o "Relatório de
atualização - Cliente" (ids 2 e 3), o 2º gráfico do 61, o "Chamados por grupo" e o
"Chamados por entidade".
**A fila de port fica em [docs/PORT-GLPI11.md](docs/PORT-GLPI11.md)** — acrescente um item
lá a cada mudança aqui e risque quando portar; ela também registra **como** o port é feito
(transformação mecânica validada sobre o commit de paridade e aplicada ao *patch*, não ao
arquivo inteiro).
**Ao mexer na lógica aqui, porte lá na sequência** — divergência entre os dois repos é o
principal risco do projeto.

Detalhes do port (todos mecânicos): no GLPI 11 os assets estáticos do plugin ficam em
`<plugin>/public/` (a logo do PDF virou `servicereports/public/pics/instant-logo.png`;
aqui continuam em `pics/`), as classes são namespaced em `src/` e **SQL cru não passa
mais por `$DB->request()`** — vai por `$DB->doQuery()`/`self::rows()`. Nessa última
armadilha, procure **todas** as formas de chamada, não só `foreach ($DB->request("`: a
quebrada em várias linhas e a consumida com `->current()` também estouram (foi o que
aconteceu no primeiro teste do relatório 60 lá). Uma forma barata de garantir que a
transformação é fiel: rode-a sobre a versão **do commit de paridade anterior** e confira
que o resultado bate byte a byte com o arquivo que já está no repo 11.

Sem modelo de dados no `managedservices` (e por isso sempre R$ 0,00): **valor por
categoria de chamado** e **extras relacionados a chamados**. Se a Instant quiser esses
números de verdade, é preciso criar a dimensão no plugin de Serviços Gerenciados.

## Referências

- [docs/SEGURANCA-2026-09.md](docs/SEGURANCA-2026-09.md) — **os oito achados de segurança
  de setembro/2026**, com causa, exploração e correção de cada um. Leia antes de mexer em
  escape de saída, direitos ou qualquer consulta que atravesse entidade.
- [docs/recon/](docs/recon/) — modelo de dados, KPIs e telas mapeados.
- [docs/INSTALL.md](docs/INSTALL.md) / `docs/INSTALL.pdf` — instalação via git clone.
- `dist/*.zip` — pacotes prontos dos plugins.
