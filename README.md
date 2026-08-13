# Instant GLPI Plugins

Reimplementação funcional livre de dois plugins usados hoje numa instância GLPI
gerenciada (Verdanadesk), para permitir a migração do GLPI da **Instant Tecnologia**
para uma VM própria mantendo as funcionalidades.

> **Proveniência / licença.** Estes plugins **não** contêm código-fonte de terceiros.
> São uma reimplementação limpa, feita a partir do comportamento observável da
> interface web, reproduzindo funcionalidades e modelo de dados — não o código
> original. GLPI é GPL e plugins são obras derivadas GPL; este repositório é
> distribuído sob **GPL-2.0-or-later** (ver [LICENSE](LICENSE)).

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

## Estrutura do repositório
```
managedservices/     # plugin GLPI (copiar/symlink para glpi/plugins/managedservices)
servicereports/      # plugin GLPI (copiar/symlink para glpi/plugins/servicereports)
docs/recon/          # notas de engenharia reversa (modelo de dados, KPIs, telas)
```

## Instalação
1. Copie (ou faça symlink de) cada pasta de plugin para `glpi/plugins/`.
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
- [ ] **Fase 7** — i18n (pt_BR), polish, testes de instalação na VM.

Ver [docs/recon](docs/recon) para o mapeamento detalhado das telas e do modelo de dados.
