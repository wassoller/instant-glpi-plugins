# Recon — Plugin "Verdana vReports" (interno: `vreports`)

> Fonte: engenharia reversa da UI web de https://instant.verdanadesk.com (GLPI 10.0.26).
> Escopo desejado: apenas 3 blocos — **Central de serviços**, **Gestão financeira**, **Analistas**.

## Arquitetura
- Plugin interno: **`vreports`**. Menu: **Gerência > Verdana vReports** → `/plugins/vreports/front/vreports.form.php` (landing "Central Verdanadesk" com vários cards).
- Usa **roteamento modular próprio**: cada bloco é `/plugins/vreports/modules/<módulo>/view[/...]`. Ou seja, o vReports tem uma camada de controllers/rotas própria dentro do GLPI (não é o padrão `front/*.php`).
- Cards existentes (só 3 são desejados): messengerintegrator, assets, **servicecentral**, services, **analysts**, changes, problems, projects, license, **financial**, contract, metricas, dashboards, satisfaction, supplier, reservations, verdana.

Blocos desejados:
| Bloco | Módulo/URL |
|---|---|
| Central de serviços | `/plugins/vreports/modules/servicecentral/view/` |
| Gestão financeira | `/plugins/vreports/modules/financial/view` |
| Analistas | `/plugins/vreports/modules/analysts/view/` |

---

## 1) Central de serviços (`servicecentral`)
Título: "Central de Serviços — Dados do mês corrente". Sub-abas: **Dashboard** e **Relatórios**.

### Dashboard — 8 cards KPI (contagens via Search Engine em `Ticket`)
Campos de busca (search options do Ticket): 12=Status, 19=Data última atualização, 5=Técnico atribuído, 4=Requerente, 6=Fornecedor atribuído, 62=Satisfação. "Mês corrente" = campo 19 entre início e fim do mês.

| KPI | Definição (critérios) | Ação |
|---|---|---|
| Incidentes | Incidentes em atendimento | → sub-dashboard `servicecentral/incidentsmanagement/view/index.php` |
| Requisições | Requisições em atendimento | → sub-dashboard `servicecentral/requestmanagement/view/index.php` |
| Aguardando retorno dos usuários | Status = Pendente (12 equals 4) + mês | → `/front/ticket.php` (deep-link) |
| Analistas em atendimento | 12 `notold` + 5 ≠ 0 + mês | → ticket.php |
| Usuários em atendimento | 12 `notold` + 4 ≠ 0 + mês | → ticket.php |
| Satisfação dos usuários (%) | 62 notcontains NULL + mês | → ticket.php |
| Chamados envolvendo fornecedores | 6 ≠ 0 + mês | → ticket.php |
| Artigos publicados | Artigos KB publicados no mês | (KnowbaseItem) |

Sub-dashboards `incidentsmanagement` e `requestmanagement`: dashboards com gráficos (a mapear no build — provavelmente por status/categoria/técnico ao longo do tempo).

### Relatórios (sub-aba) — a mapear no build (geradores de relatório com filtros + export).

---

## 2) Gestão financeira (`financial`)
Título: "Dashboard financeiro — Em tempo real". Sub-abas: **Dashboards** e **Relatórios**.
**Depende dos dados financeiros do plugin vservices** (Serviços Gerenciados). "Clientes: 1 / Serviços: 2" batem com o vservices.

### Dashboard — KPIs
- RECEITA PREVISTA (R$)
- RECEITA DE ATIVOS COBERTOS (R$)
- VALOR MÉDIO MENSAL DOS SERVIÇOS (R$)
- RECEITA MENSAL DOS SERVIÇOS (R$)
- CLIENTES (contagem de clientes distintos dos serviços)
- SERVIÇOS (contagem de serviços gerenciados)

### Gráficos
- "Top 10 receitas previstas por entidade" (barras, por entidade)
- "Top 10 valor médio por tipo de ativo" (barras, por tipo de ativo)

### Relatórios (sub-aba) — a mapear no build.

> Fonte dos números: tabelas financeiras do vservices (valor mensal, valor/hora, valor por classe de ativo, valor por usuário/BD/armazenamento) + `managedservices` (cliente/entidade).

---

## 3) Analistas (`analysts`)
Título: "Desempenho de Analistas". Sub-abas: **Técnicos**, **Relatórios**, **Mapas**.

- **Técnicos** ("Performance de técnicos"): filtro `tecnichanFilter[]` (multi) + um card por técnico com métricas: **Horas trabalhadas** (soma `actiontime` das tarefas), **Pontos**, **Satisfação**, **Chamados atendidos**, **Incidentes** (+%), **Requisições** (+%).
- **Relatórios**: seletor `report` (IDs 57/58/59). Filtros de cada: intervalo de datas (`start_date`/`end_date`), `technician_id`, `ticket_id`.
  1. **57 — Tarefas por Técnico**: lista de `glpi_tickettasks`. Colunas: Chamado, Autor, Entidade, Data de criação, Categoria, Descrição, Grupo, Técnico, Início, Fim, Duração. Totais: nº de tarefas, tempo total.
  2. **58 — Deslocamentos por Técnico**: Distância (Km) + Tempo total. **Fonte não-nativa do GLPI** (Verdana) — sem dados.
  3. **59 — Horas fora de expediente por chamado**: colunas Técnico, ID do chamado, Tempo total de tarefas no chamado, Tempo de tarefas fora do expediente, Entidade. Expediente observado termina **18:00** (ex.: tarefa 15:39→18:39 = 00:39:31 fora). Usa jornada Seg–Sex 08:00–18:00.
- **Mapas** ("Posicionamento geográfico de técnicos"): mapa **Leaflet** com posição dos técnicos. **Fonte de geolocalização não-nativa** (Verdana).

---

## Pendências de recon (a capturar no build, iterativamente)
- Sub-dashboards de incidentes/requisições: tipos de gráfico, séries, queries.
- Conteúdo das abas "Relatórios" de cada bloco: filtros, colunas, formatos de export.
- Aba "Mapas" de Analistas: fonte de coordenadas.
- Biblioteca de gráficos usada (provável Chart.js/ApexCharts embutida no plugin).
- Fórmulas exatas de "Receita prevista / média / mensal" a partir das tabelas do vservices.

## Decisão de arquitetura para o clone (recomendada)
Reimplementar os 3 blocos como um plugin GLPI **padrão** (landing + `front/*.php` por bloco + sub-abas), reproduzindo KPIs (search counts), gráficos e relatórios — em vez de replicar o roteador modular proprietário. Mais simples, manutenível e suficiente já que não há migração de dados.
