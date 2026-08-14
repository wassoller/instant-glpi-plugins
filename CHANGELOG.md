# Changelog

Formato inspirado em [Keep a Changelog](https://keepachangelog.com/pt-BR/).
Alvo: **GLPI 10.0.x** (validado em 10.0.26). A versão para GLPI 11 tem changelog
próprio no repositório `instant-glpi11-plugins`.

## [0.1.0] — 2026-08-13 (não lançado)

Primeira versão funcional dos dois plugins, reconstruídos por engenharia reversa
da UI web da instância Verdana e **validados num GLPI 10.0.26 local**. Os plugins já
estão **em produção** no GLPI 10 da Instant (`suporte.instanttecnologia.com.br`).

### Correções pós-deploy — bloco Analistas (relatórios), diagnosticadas em produção
- **Relatório "Tarefas por Técnico" e Horas trabalhadas**: passam a filtrar o período
  por `tt.date` (data da tarefa) em vez de `tt.begin` (Início/planejamento, que fica
  **NULO** em tarefas não planejadas — a maioria). Sem isso, o relatório vinha **vazio**
  em produção mesmo havendo tarefas com duração. Vale para getTasksReport,
  getTechnicians, getPerformance e getOutOfHoursReport.
- Coluna **"Data"** adicionada ao relatório 57; a coluna **"Categoria"** passa a exibir a
  categoria do **chamado** (`glpi_tickets.itilcategories_id`), não a da tarefa (quase
  sempre vazia).
- **Número do chamado** vira link para o chamado (`/front/ticket.form.php?id=`).
- **Exportação CSV** nos relatórios 57 e 59 (botão "Exportar CSV"; delimitador `;`, BOM
  UTF-8, entidades HTML decodificadas; `$escape` explícito no `fputcsv` para PHP 8.4+).
- Sem mudança de banco → atualizar é só copiar os arquivos e recarregar (não reinstalar).

### Adicionado — `managedservices` ("Serviços Gerenciados", menu Ativos)
- Objeto principal `PluginManagedservicesManagedservice` (nome, cliente/`users_id`,
  contrato, categoria de chamado) com CRUD, busca (search options) e menu em Ativos.
- Integração de perfis/direitos (`plugin_managedservices`).
- Aba **Gerência**: gerentes usuário/grupo do serviço.
- Aba **Ativos cobertos pelo serviço**: itemtype+item + data de contrato, com lista
  de "removidos" (soft-delete).
- Aba **Composição do Serviço**: itemtype+item + impacto (Parcial/Total).
- Aba **Financeiro**: 8 dimensões de valor historizadas por data de contrato
  (mensal, hora, por classe de ativo, por usuário, por banco de dados, por
  armazenamento) + configs de horas de suporte e limite de horas.
- Aba **Configuração NMS**: URL do NMS por serviço.

### Adicionado — `servicereports` ("Relatórios", menu Gerência)
- Landing com os 3 blocos.
- **Central de serviços**: 8 KPIs de chamados do mês (contagens via SQL) +
  deep-links para `/front/ticket.php` com os critérios exatos do original.
- **Gestão financeira**: 6 KPIs + 2 gráficos de barras (HTML/CSS puro), lendo os
  dados financeiros do `managedservices`.
- **Analistas**: dashboard Técnicos (horas, chamados por tipo, satisfação, pontos)
  + Relatórios (Tarefas por Técnico e Horas fora de expediente) + Mapas.

### Adicionado — projeto
- `docs/recon/` — engenharia reversa (modelo de dados, KPIs, telas).
- `docs/INSTALL.md` + `docs/INSTALL.pdf` — instalação via `git clone`.
- `dist/managedservices.zip`, `dist/servicereports.zip` — pacotes prontos.
- Repositório privado `instant-glpi-plugins`.

### Correções pegas na validação local (ver histórico git)
- `getTabNameForItem()` precisa ser método de instância (não `static`).
- Dropdown múltiplo (`User`/`Group`/`Dropdown`) lê `'value'` (array), não `'values'`.
- Classe `.small` do tema quebrava o texto da landing (troca por `font-size` inline).

### Limitações conhecidas (partes Verdana-específicas)
- **Analistas › Deslocamentos** e **Mapas**: dependem de fontes de dados não-nativas
  do GLPI (distância de deslocamento; geolocalização). Entregues com estrutura + nota.
- **Analistas › Pontos** = chamados solucionados (aproximação).
- **Horas fora de expediente**: jornada fixa Seg–Sex 08:00–18:00.

### Pendente (Fase 7)
- Arquivos de tradução `.mo` (hoje os textos saem em pt-BR direto via `__()`).
- Refino de ícones/estilos.
- Teste de instalação na VM real da Instant.

### Notas para o próximo agente
- **Sempre teste num GLPI local** (ver `CLAUDE.md`), não só `php -l`.
- O `servicereports` depende do `managedservices` (dados financeiros) — instale o
  primeiro antes.
- Ao mexer na lógica, **replique no repositório GLPI 11** (`instant-glpi11-plugins`)
  para manter paridade.
