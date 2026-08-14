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

Recriar o ambiente do zero: ver [CHANGELOG.md](CHANGELOG.md) e os comandos acima.

## Armadilhas do GLPI 10 já descobertas (não repita)

- `getTabNameForItem()` é método de **instância** (NÃO `static`);
  `displayTabContentForItem()` é `static`.
- Dropdown **múltiplo** (`User::dropdown`/`Group::dropdown`/`Dropdown::show`):
  passe **`'value' => $array`**, não `'values'` (o GLPI faz `values = value`).
- Menu em seção do core: `$PLUGIN_HOOKS['menu_toadd'][$key] = ['assets' => Classe]`
  (ou `'management'`).
- A classe utilitária `.small` do tema quebra texto (largura mínima) em HTML
  próprio — use `font-size` inline.
- DB: `$DB->doQuery()`, `new Migration(VERSION)` + `executeMigration()`,
  `$DB->tableExists()`.

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
- **Export CSV** fica em `front/analysts.php`, antes do `Html::header` (com `exit`). No
  `fputcsv` passe o `$escape` explícito (`''`) — PHP 8.4+ deprecou o default — e rode os
  valores por `html_entity_decode` (o GLPI devolve texto HTML-escapado, ex.: `&#62;`).

## O que falta (Fase 7)

Traduções `.mo` (hoje os textos saem em pt-BR direto pelos `__()`), refino de
ícones, e o **teste de instalação na VM real** da Instant.

## Referências

- [docs/recon/](docs/recon/) — modelo de dados, KPIs e telas mapeados.
- [docs/INSTALL.md](docs/INSTALL.md) / `docs/INSTALL.pdf` — instalação via git clone.
- `dist/*.zip` — pacotes prontos dos plugins.
