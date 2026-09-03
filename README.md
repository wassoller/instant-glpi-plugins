# Instant GLPI Plugins

Reimplementação funcional livre de dois plugins usados hoje numa instância GLPI
gerenciada (Verdanadesk), para permitir a migração do GLPI da **Instant Tecnologia**
para uma VM própria mantendo as funcionalidades.

> **Proveniência / licença.** Estes plugins **não** contêm código-fonte de terceiros.
> São uma reimplementação limpa, feita a partir do comportamento observável da
> interface web, reproduzindo funcionalidades e modelo de dados — não o código
> original. GLPI é GPL e plugins são obras derivadas GPL; este repositório é
> distribuído sob **GPL-2.0-or-later** (ver [LICENSE](LICENSE)).

## Status
Em **produção** no GLPI 10 da Instant (`suporte.instanttecnologia.com.br`), instalado em
`/var/www/instant/glpi`. A rodada **0.2.0** (2026-08-19) trouxe o Extrato financeiro no
formato pedido pelo cliente (listagem de chamados com custos, PDF em paisagem com logo e
título próprios), **paginação de 10 em 10** nos relatórios e a correção de três bugs
relevantes — dashboard em branco quando a sessão está restrita a uma entidade, categoria-pai
não selecionável em Serviços Gerenciados e chamados invisíveis para serviço recursivo.

Depois disso, ainda sem mudança de schema (atualizar é copiar os arquivos e recarregar):
- **2026-08-25** — o período dos relatórios financeiros passou a ser a **data de fechamento**
  do chamado (que leva junto todas as tarefas dele); o Extrato ganhou o layout
  "Institucional" e virou **PDF de verdade** (TCPDF, no lugar da impressão do navegador,
  que cortava dados); o relatório "Fatura de serviços detalhada" foi **removido**.
- **2026-08-26** — novo relatório **"Entidade vs. Analistas"** em Analistas › Relatórios:
  matriz analista × entidade com o tempo de tarefas, somado pela **mesma regra do Extrato**.
  As colunas são as entidades **folha**; os nós de agrupamento da árvore ("Standard",
  "Premium") só aparecem se tiverem horas no período.
- **2026-08-27** — a **Central de serviços** ganhou a sub-aba **Relatórios**, com o
  **"Relatório central de serviços"** e o **"Relatório de atualização - Cliente"**
  (reimplementação do deck entregue ao cliente: totais por status, chamados por tipo,
  top 5 categorias, abertos × fechados e chamados por horário) em duas variantes —
  **ANUAL**, com as séries por mês, e **MENSAL**, com as mesmas métricas por dia.
  Todos exportam CSV e PDF. Analistas ganhou o **"Chamados por Status e Técnico"**
  (barras empilhadas + PDF paisagem).
- **2026-08-28** — três relatórios a mais, todos com CSV e PDF:
  - Analistas › "Chamados por Status e Técnico" ganhou um **segundo gráfico**, "Chamados
    por tipo e técnico", quebrando os **mesmos** chamados em **Incidente × Requisição**.
  - Central de serviços › **"Chamados por grupo"**: os chamados do período pelo **grupo
    atribuído** ao chamado (a fila que aparece ao lado do técnico).
  - Central de serviços › **"Chamados por entidade"**: o gráfico de barras empilhadas por
    status, com **entidade** no eixo X, englobando os chamados em qualquer status.
  No mesmo dia o **autor** dos plugins passou a ser Lyon Wassoller — numa instalação que
  já existe, o nome novo só aparece depois de atualizar a linha do plugin em
  `glpi_plugins` (ver o CHANGELOG).

Detalhes no [CHANGELOG.md](CHANGELOG.md). A versão para **GLPI 11** está no repositório
`instant-glpi11-plugins` e está **em paridade** com esta desde 2026-08-28 (0.5.0 lá,
validada no GLPI 11.0.8); a fila de port fica em
[docs/PORT-GLPI11.md](docs/PORT-GLPI11.md).

## Alvo
- **GLPI 10.0.x** (validado contra 10.0.26), PHP 8.x.

## Plugins

### 1. `managedservices` — "Serviços Gerenciados"
Equivalente ao plugin *Serviços Gerenciados* (interno `vservices`).
Menu: **Ativos > Serviços Gerenciados**.
Objeto principal + abas: Gerência (gerentes), Ativos cobertos pelo serviço,
Composição do serviço, Financeiro (valores historizados) e Configuração NMS.

### 2. `servicereports` — "Relatórios"
Reimplementação de 3 blocos do *Verdana vReports* (interno `vreports`):
**Central de serviços**, **Gestão financeira** (lê dados do `managedservices`) e
**Analistas**. Menu: **Gerência > Relatórios**.
Central de serviços tem **Dashboard** (KPIs do mês) e **Relatórios** — cinco: Relatório
central de serviços, Relatório de atualização - Cliente (ANUAL e MENSAL), Chamados por
grupo e Chamados por entidade, todos com export CSV e PDF;
Gestão financeira tem **Dashboards** (KPIs + gráficos) e **Relatórios** (Extrato
financeiro e Faturamento, com export CSV e PDF gerado por TCPDF);
Analistas tem Técnicos, Relatórios (tarefas por técnico, deslocamentos, horas fora de
expediente, **Entidade vs. Analistas** e **Chamados por Status e Técnico**, este com um
segundo gráfico por tipo) e Mapas. As listagens paginam de 10 em 10;
CSV e PDF saem completos — a matriz de "Entidade vs. Analistas" também não pagina.

## Versão GLPI 11
Este repositório é a versão **GLPI 10.0.x**. O port para **GLPI 11.0.x** está no
repositório separado **`instant-glpi11-plugins`** (classes namespaced em `src/`).

## Estrutura do repositório
```
managedservices/     # plugin GLPI 10 (copiar para glpi/plugins/managedservices)
servicereports/      # plugin GLPI 10 (copiar para glpi/plugins/servicereports)
  inc/pager.class.php     # paginação (10 por página) das listagens de relatório
  inc/extratopdf.class.php# renderizador TCPDF do Extrato financeiro (par da tela)
  pics/instant-logo.png   # logo usada no cabeçalho do PDF do Extrato (trocável)
docs/recon/          # notas de engenharia reversa (modelo de dados, KPIs, telas)
docs/INSTALL.md      # passo a passo de instalação via git clone (também em .pdf)
tools/               # build-install-pdf.py — gera o docs/INSTALL.pdf a partir do .md
dist/*.zip           # pacotes prontos dos plugins (descompactar em glpi/plugins/)
CLAUDE.md            # guia operacional para o próximo agente
CHANGELOG.md         # histórico das mudanças
```

## Instalação
Guia completo em [docs/INSTALL.md](docs/INSTALL.md) (e `docs/INSTALL.pdf`). Resumo:
1. **Copie** cada pasta de plugin para `glpi/plugins/` (ou use os `dist/*.zip`).
   *Não use symlink* — quebra o `include` relativo quando o GLPI é servido.
2. Em **Configuração > Plugins**, instale e ative na ordem:
   `Serviços Gerenciados` → `Relatórios` (o de relatórios depende do primeiro).

## Roadmap (fases)
- [x] **Fase 0** — Scaffolding dos 2 plugins (estrutura GLPI 10, install/uninstall, menus).
- [x] **Fase 1** — `managedservices`: objeto principal (CRUD + busca + menu). *Validado em GLPI 10.0.26.*
- [x] **Fase 2** — `managedservices`: abas Gerência / Cobertos / Composição / NMS. *Validado em GLPI 10.0.26.*
- [x] **Fase 3** — `managedservices`: Financeiro (todas as dimensões, historização). *Validado em GLPI 10.0.26.*
- [x] **Fase 4** — `servicereports`: landing + Central de Serviços (KPIs + deep-links). *Validado em GLPI 10.0.26.*
- [x] **Fase 5** — `servicereports`: Gestão Financeira (lê `managedservices`) + gráficos. *Validado em GLPI 10.0.26.*
- [x] **Fase 6** — `servicereports`: Analistas (Técnicos, Relatórios, Mapas). *Validado em GLPI 10.0.26. Deslocamentos e Mapas dependem de fontes de dados não-nativas (Verdana) — estrutura + nota.*
- [~] **Fase 7** — polish: **feito** os pacotes `dist/*.zip`, o guia de instalação, o
  deploy na VM real (`/var/www/instant/glpi`) e a **paridade da versão GLPI 11** (em dia
  desde 2026-09-03, com o **mesmo número de versão** nos dois repos); **pendente** as traduções `.mo` (os textos já saem
  em pt-BR via `__()`), o refino de ícones e instalar a versão GLPI 11 numa **VM real** —
  ela só foi validada em ambiente local. As **versões** dos dois plugins já estão em
  `0.5.6`, alinhadas com o CHANGELOG, e os `dist/*.zip` e o `INSTALL.pdf` foram regerados
  em 03/09.

Também disponível: **port para GLPI 11** no repositório `instant-glpi11-plugins`.

Ver [docs/recon](docs/recon) para o mapeamento detalhado das telas e do modelo de
dados, [CHANGELOG.md](CHANGELOG.md) para o histórico e [CLAUDE.md](CLAUDE.md) para o
guia operacional (como testar num GLPI local).
