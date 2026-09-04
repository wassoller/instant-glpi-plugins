# Correções de segurança — setembro/2026

> Registro detalhado das correções aplicadas aos plugins **`managedservices`**
> (Serviços Gerenciados) e **`servicereports`** (Relatórios), nas versões **GLPI 10**
> (`instant-glpi-plugins`) e **GLPI 11** (`instant-glpi11-plugins`).
>
> Período: **03/09/2026**. Versões resultantes: **0.5.9** nos dois plugins, nos dois
> repositórios. Histórico completo em [CHANGELOG.md](../CHANGELOG.md).

## Sumário

| # | Severidade | Plugin | Problema | Explorado em teste? |
|---|---|---|---|---|
| 1 | Alta | ambos | Aba de direitos do Perfil quebrada — ninguém conseguia conceder acesso | — (defeito funcional) |
| 2 | Alta | `servicereports` | XSS armazenado nas telas de relatório | **Sim** (GLPI 11) |
| 3 | Alta | `managedservices` | Escrita entre entidades pelas abas do serviço | **Sim** (GLPI 10 e 11) |
| 4 | Alta | `managedservices` | Leitura entre entidades pela API REST | Confirmado por inspeção |
| 5 | Alta | `servicereports` | Tarefa privada exposta no relatório de Analistas | **Sim** (GLPI 10 e 11) |
| 6 | Alta | `servicereports` | Relatórios per-chamado sem a ACL de chamado do perfil | **Sim** (GLPI 10 e 11) |
| 7 | Alta | `servicereports` | Extrato financeiro atravessando entidades | **Sim** (GLPI 10) |
| 8 | Média | `servicereports` | Fórmula maliciosa nos CSV exportados | **Sim** (GLPI 10 e 11) |

Origem dos achados: **1** veio de uma pergunta do cliente sobre permissões; **2** de uma
auditoria minha; **3, 4** de um scan externo sobre `managedservices.zip`; **5, 6, 7, 8**
de um scan externo sobre `servicereports.zip`. Os quatro achados dos scans **se
confirmaram**, e cinco dos oito foram reproduzidos com exploração real em ambiente local.

---

## 1. Aba de direitos do Perfil quebrada (ambos os plugins)

**O que acontecia.** A aba *Serviços Gerenciados* / *Relatórios* no formulário de Perfil
abria **em branco**, sem mensagem de erro. Consequência prática: **não havia como conceder
o direito do plugin a outro perfil pela interface** — só por `UPDATE` direto no banco.

**Causas, uma por plugin e por versão do GLPI:**

- **GLPI 10, nos dois plugins:** `showRightsForm()` chamava
  `Profile::getFormURL()->__toString()`, mas no GLPI 10 esse método já devolve **string**
  (`Toolbox::getItemTypeFormURL()`). O fatal acontecia **depois** de imprimir a `<div>` de
  abertura, então a aba saía vazia e o erro só aparecia em `files/_log/php-errors.log`.
- **GLPI 11, `servicereports`:** a matriz usava `'itemtype' => Menu::class`, e
  `Profile::getRightsFor()` chama `getRights()` — método de **`CommonDBTM`**, que a `Menu`
  (um `CommonGLPI`) não tem. Resultado: **HTTP 500**.
- **GLPI 11, `managedservices`:** a aba **abria**, mas o `action` do formulário apontava
  para `/plugins/managedservices/front/profile.form.php`, que não existe.
  `self::getFormURL()` é chamada **forwarding** e resolve para a classe do plugin; o
  `GenericFormController` do GLPI 11 tentava resolver o itemtype e estourava procurando a
  tabela `glpi_plugin_managedservices_profiles`. Salvar não funcionava.

**Correção.**

- `\Profile::getFormURL()` (não-forwarding) nos dois plugins e nas duas versões.
- No `servicereports`, a matriz declara os direitos explicitamente
  (`'rights' => [READ => __('Read')]`) em vez de derivá-los do itemtype.
- `front/managedservice.form.php` passou a checar `READ` (com id) ou `CREATE` (novo) no
  ramo de exibição — o `display()` do core só verifica o direito quando há `id` na URL, e
  sem isso o formulário de **criação** abria para quem não tinha direito nenhum.

**Lição registrada.** Aba que "não abre" pede olhar `files/_log/php-errors.log` antes do
código. E o mesmo código pode falhar de formas diferentes nas duas versões do GLPI: aqui
foram 500, formulário quebrado e tela em branco, para o mesmo par de defeitos.

---

## 2. XSS armazenado nas telas de relatório (`servicereports`)

**O que acontecia.** As tabelas HTML dos relatórios imprimiam texto vindo do banco — nome
de entidade, grupo, categoria, usuário, título de chamado — **sem escapar**, apoiadas na
premissa "o GLPI devolve o texto já escapado".

**Por que era falso.** No **GLPI 10** a premissa vale: o `Toolbox\Sanitizer` escapa na
entrada (100 arquivos do core o citam). **O GLPI 11 removeu o `Sanitizer`** (3 citações
residuais) e guarda o texto **cru**. O código portado herdou a premissa junto.

**Exploração confirmada (GLPI 11).** Uma entidade renomeada para `<b>PROBE_ENT</b>` saía
crua no `<td>` do relatório "Chamados por entidade":

```html
<td>Instant > Standard > <b>PROBE_ENT</b></td>
```

Trocando `<b>` por `<script>`, executaria no navegador de **todo mundo que abrisse o
relatório**. Quem explora: qualquer perfil que possa **nomear** uma entidade, grupo ou
categoria — administração, não usuário comum. As vítimas incluem administradores.

**Correção.** `Chart::esc()` virou pública e é agora a **única** forma de imprimir texto do
banco numa tela do plugin:

```php
esc($v)   = htmlspecialchars(plain($v), ENT_QUOTES, 'UTF-8')
plain($v) = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')
```

É **idempotente** com o texto já escapado do GLPI 10 (decodifica e reescapa: mesma saída),
então o mesmo código serve nos dois repositórios sem escape duplo. Aplicada em ~20 pontos:
tabelas dos relatórios 4, 5, 57, 59, 60 e 61, cartões de técnico, legenda dos gráficos e,
no Extrato, entidade, serviço, título do chamado, requerente, categoria e "Impresso por".

Também: os atributos `data-tip-*`, `data-tech` e `aria-label` dos gráficos deixaram de
usar `Html::cleanInputText`, que escapa **só as aspas** (`preg_replace` de `'` e `"`) e
deixava `<` cru dentro do atributo. Não era explorável — sem aspas não se escapa do
atributo, e o tooltip monta o conteúdo com `textContent` — mas `esc()` fecha a categoria
inteira em vez de depender disso.

**Falso negativo que quase passou.** A primeira varredura procurou linhas com `echo` e
variável sem escape, e **deixou passar** um caso: `<th title='<escapado>'>` com
`$e['name']` **cru** no corpo da célula. A linha continha `cleanInputText`, então o filtro
a considerou segura. A varredura correta avalia **ocorrência a ocorrência**, não a linha.

---

## 3. Escrita entre entidades pelas abas do serviço (`managedservices`)

**O que acontecia.** Os cinco handlers de aba — `manager.form.php`, `nmsconfig.form.php`,
`financialvalue.form.php`, `composition.form.php`, `coveredasset.form.php` — checavam
**só o direito global do plugin** (`Session::checkRight('plugin_managedservices', …)`) e
agiam sobre o id recebido no formulário, sem validar a que serviço e entidade ele pertence.

**Exploração confirmada (GLPI 10 e 11).** Sessão restrita à entidade *Uniletra*:

```
POST /plugins/managedservices/front/financialvalue.form.php
     delete_value=1&id=<id de um serviço de outra entidade>
     → HTTP 302, linha apagada
```

O mesmo valia para gerentes, NMS, composição e ativos cobertos. Detalhe traiçoeiro: o
objeto **pai já era protegido** — abrir `managedservice.form.php?id=<outra entidade>` dava
"Acesso negado" corretamente. Quem olhasse por cima concluiria que a separação funcionava.
O buraco eram as cinco abas penduradas nele.

**Correção.** Duas guardas novas em `PluginManagedservicesManagedservice`:

- **`checkService($sid, $right)`** — é o `check()` do core, que aplica direito **e**
  entidade e morre com "Acesso negado". Usada nas operações endereçadas pelo id do serviço.
- **`checkChild($obj, $id, $right, $fk)`** — carrega a linha filha e tira o serviço-pai
  **do banco**. Usada nas operações endereçadas pelo id da linha.

A distinção importa: se a validação usasse o id de serviço que veio no POST, bastaria
enviar o id da linha de um cliente junto do id do serviço de outro e a checagem validaria o
serviço errado.

---

## 4. Leitura entre entidades pela API REST (`managedservices`)

**O que acontecia.** Nenhuma das cinco tabelas filhas tinha `entities_id`. Sem a coluna,
`CommonDBTM::isEntityAssign()` devolve `false` e **o core não aplica restrição nenhuma** —
nem na API REST, nem no `Search`, nem no `can()`. Medido antes da correção:

```
PluginManagedservicesManagedservice   isEntityAssign=SIM
PluginManagedservicesFinancialvalue   isEntityAssign=NAO
PluginManagedservicesNmsconfig        isEntityAssign=NAO
PluginManagedservicesManager          isEntityAssign=NAO
PluginManagedservicesComposition      isEntityAssign=NAO
PluginManagedservicesCoveredasset     isEntityAssign=NAO
```

Com a API habilitada, um `GET /apirest.php/PluginManagedservicesFinancialvalue/` devolveria
os valores financeiros de **todos** os clientes.

**Por que não `CommonDBChild`.** O scan sugeriu converter as filhas para `CommonDBChild`.
Não foi o caminho: o `isEntityAssign()` do `CommonDBChild` delega ao pai e devolve `true`,
mas a API tem um desvio explícito para "filhas sem `entities_id`" e **pula** a restrição
nesse caso — a listagem continuaria vazando. Além disso, trocar a classe-base de cinco
classes arriscava as abas inteiras.

**Correção.** As cinco tabelas ganharam `entities_id`/`is_recursive` como **espelho** do
serviço, e as seis classes passaram a responder `isEntityAssign=SIM`. A fonte da verdade
continua sendo o serviço:

- `stampEntity()` carimba a entidade do pai em toda escrita
  (`prepareInputForAdd`/`prepareInputForUpdate` das cinco filhas);
- `Managedservice::post_updateItem()` propaga para as filhas quando o serviço **muda de
  entidade** (testado);
- `inheritEntity()` faz a migração no `install()` — acrescenta as colunas e preenche a
  partir do serviço; **idempotente**, roda também na atualização do plugin.

**Isto é mudança de schema** e foi o motivo da subida obrigatória de versão.

---

## 5. Tarefa privada exposta no relatório de Analistas (`servicereports`)

**O que acontecia.** As consultas sobre `glpi_tickettasks` recortavam por entidade e
ignoravam **`is_private`**. O conteúdo de tarefa privada de qualquer chamado da entidade
aparecia na tela e no CSV do relatório 57 — e é justamente ali que vai a anotação interna
(senha, observação sobre o cliente, combinação comercial).

**Correção.** `PluginServicereportsAnalysts::privateTaskRestrict()`, aplicada às **seis**
consultas de tarefa do plugin, replicando a regra do core (`TicketTask::canViewItem`):

```php
if (Session::haveRight(TicketTask::$rightname, CommonITILTask::SEEPRIVATE)) {
    return '';                       // vê todas
}
return " AND ($alias.is_private = 0 OR $alias.users_id = $me)";
```

**Decisão consciente:** o somatório de horas do **extrato financeiro** (`Financial`)
**não** filtra tarefa privada. Ali o dado é o `actiontime`, não o conteúdo, e esconder
horas produziria silenciosamente uma **fatura errada**. O controle correto para o extrato é
o portão do item 6.

---

## 6. Relatórios per-chamado sem a ACL de chamado do perfil (`servicereports`)

**O que acontecia.** O plugin recortava por entidade, mas não pela ACL de chamado do
perfil. Um perfil com `READMY` ("ver apenas os próprios chamados") lia, pelo relatório, os
chamados de todos os colegas da entidade — com título, requerente, autor e conteúdo de
tarefa.

**Por que não replicamos a ACL na consulta.** `Search::addDefaultWhere('Ticket')` monta o
`WHERE` da visibilidade de chamado, mas depende dos aliases de join gerados pelo próprio
`Search` (`glpi_tickets_users_<hash>`) — não é reaproveitável em SQL cru. Replicar a regra
à mão seria duplicar lógica de segurança do core, que muda entre versões.

**Correção.** `front/analysts.php` e `front/financial.php` — as duas telas que listam
chamado a chamado — passaram a exigir **`Ticket::READALL`** além do direito do bloco:

```php
Session::checkRight('ticket', Ticket::READALL);
```

**Isto muda quem consegue abrir esses dois relatórios.** Se alguém legítimo passar a
receber "Acesso negado", o ajuste é conceder *Ver todos os chamados* ao perfil, **não**
remover a linha. A **Central de serviços não foi restringida**: ela mostra agregados
(contagens, top categorias, gráficos), não o chamado individual.

---

## 7. Extrato financeiro atravessando entidades (`servicereports`)

**O que acontecia.** Em `Financial::linkedTicketIds()`:

- a cobertura de um serviço **recursivo** vinha de `getSonsOf('glpi_entities', $entity)`
  **sem cruzar com as entidades da sessão**;
- a busca por **ativo coberto** casava `itemtype`/`items_id` e **não tinha recorte de
  entidade nenhum**.

**Exploração confirmada (GLPI 10).** Serviço recursivo em *Instant > Standard*, com
chamados fechados em *Uniletra* e em *Cliente Sem Horas*. Sessão restrita a *Standard*
(sem a subárvore) via os chamados das duas filhas — requerente, horas e valores incluídos.

**Correção.** A cobertura é intersectada com `$_SESSION['glpiactiveentities']`, e a
consulta por ativo entrou no `glpi_tickets` com filtro de entidade.

**Consequência visível, e é intencional:** o extrato de um serviço recursivo passou a
seguir o **seletor de entidade do GLPI**. Medido depois da correção:

| Escopo da sessão | Vê chamado de Uniletra | Vê chamado de Cliente Sem Horas |
|---|---|---|
| *Standard* (só a entidade) | não | não |
| *Standard* **com subárvore** | sim | sim |
| *Uniletra* | sim | não |

Ou seja: para faturar a subárvore inteira, selecione a entidade **com** as filhas — como em
qualquer outra tela do GLPI. **Isto pode mudar totais de extrato** conforme o escopo
escolhido; vale avisar quem emite.

---

## 8. Fórmula maliciosa nos CSV exportados (`servicereports`)

**O que acontecia.** Os 24 `fputcsv()` das três telas exportavam o campo cru. Excel e
LibreOffice **executam** a célula que começa com `=`, `+`, `-` ou `@` (e ignoram TAB/CR
antes do gatilho). Um chamado intitulado `=HYPERLINK("http://atacante","clique")` vira
ataque quando alguém abre o relatório na planilha.

**Correção.** Toda a saída passou a sair por uma função central,
`PluginServicereportsCsv::row()` (`inc/csv.class.php` / `src/Csv.php`), que:

1. desescapa o HTML — o GLPI 10 devolve texto escapado e o CSV não é HTML;
2. prefixa `'` na célula que começa com `=`, `+`, `-`, `@`, TAB ou CR;
3. **deixa número em paz** (`is_numeric`), para a planilha continuar somando as colunas de
   horas e dinheiro — `-5` não vira `'-5`.

Resultado medido no CSV do relatório 57:

```
1;glpi;"Entidade raiz";"2026-08-15 11:00:00";"Suporte > Rede";"'=HYPERLINK(""http://ataque"")…"
↑ id intacto                                                  ↑ fórmula neutralizada
```

---

## O que **não** foi corrigido

- **Listagem de diretório e log baixável no servidor web.** Um scan encontrou a pasta do
  plugin navegável em produção. Isso é **configuração do Apache**, não código: com o
  `DocumentRoot` na raiz do GLPI, `/files/_log/php-errors.log` é baixável por qualquer um
  (verificado localmente: **HTTP 200**). A correção é apontar o `DocumentRoot` para
  `glpi/public` — testado, com os plugins funcionando e os caminhos sensíveis passando a
  responder **403**. **Depende de acesso ao servidor.**
- **Traduções `.mo` e refino de ícones** — adiados deliberadamente; a interface é entregue
  em pt-BR pelos `__()`.
- **GLPI 11 nunca instalado numa VM real** — só validado em ambiente local.

## Como aplicar em produção

Houve **mudança de schema** (item 4) e a versão dos dois plugins subiu para `0.5.9`:

```bash
cd /tmp/instant-glpi-plugins && git pull && sudo cp -r managedservices servicereports /var/www/instant/glpi/plugins/ && sudo chown -R www-data:www-data /var/www/instant/glpi/plugins/managedservices /var/www/instant/glpi/plugins/servicereports
```

Depois, em **Configuração > Plugins**, clique **Atualizar** no *Serviços Gerenciados*
**primeiro** e depois no *Relatórios*, e reative os dois. É no passo do `managedservices`
que a migração de entidade roda. Confira:

```bash
sudo mysql glpi -e "SELECT COUNT(*) total, COUNT(DISTINCT entities_id) entidades FROM glpi_plugin_managedservices_financialvalues;"
```

E revise os perfis: quem precisa dos relatórios de **Analistas** e **Gestão financeira**
tem de ter *Ver todos os chamados*; quem precisa ver tarefa privada nos relatórios tem de
ter *Tarefas › Ver as privadas*.

## Ressalva sobre este documento

Quatro dos oito achados vieram de **scans externos**, não da minha revisão — e a minha
revisão do dia anterior, que olhou SQL, escape e direitos, **não** olhou separação por
entidade nas abas nem ACL de chamado. Auditoria feita pelo autor do código tem ponto cego;
manter o scan externo no pipeline vale mais que confiar nesta lista.
