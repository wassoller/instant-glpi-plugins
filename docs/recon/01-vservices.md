# Recon — Plugin "Serviços Gerenciados" (interno: `vservices`)

> Fonte: engenharia reversa da UI web de https://instant.verdanadesk.com (GLPI 10.0.26).
> Objetivo: clonar **tudo** deste plugin para um plugin novo (só funcionalidade; sem migrar dados).

## Ambiente
- GLPI **10.0.26**, PHP 8.x, tema/marca "Verdanadesk".
- Plugin interno: **`vservices`**. Prefixo de classe: `PluginVservices...`. Prefixo de tabela: `glpi_plugin_vservices_...`.
- Menu: **Ativos > Serviços Gerenciados** → `/plugins/vservices/front/managedservice.php`.

## Objeto principal — `PluginVservicesManagedservice`
Tabela: `glpi_plugin_vservices_managedservices`
Front: `managedservice.php` (lista), `managedservice.form.php` (form).

Campos do formulário principal:
| Rótulo | Coluna | Tipo |
|---|---|---|
| Nome | `name` | varchar |
| Comentários | `comment` | text |
| Cliente | `users_id` | FK glpi_users |
| Contrato | `contracts_id` | FK glpi_contracts |
| Ticket category (Categoria, obrigatório) | `itilcategories_id` | FK glpi_itilcategories |
| Entidade | `entities_id` / `is_recursive` | padrão GLPI |
| (padrão) | `date_creation`, `date_mod` | datetime |

Search options (colunas de busca) observadas na lista: ID, Nome, Entidade, Entidade Filhas, Comentários, Cliente, Categoria, Contrato, Número de chamados, Número de problemas, Número de mudanças.
- Os contadores de chamados/problemas/mudanças são derivados (provavelmente via `itilcategories_id`). **A confirmar**.

Registros de exemplo no ambiente: "Suporte Avançado" (id=17), "Suporte Básico" (id=16).

## Abas do registro (todas customizadas, exceto Documentos/Histórico/Todos)

### 1. Gerência — `PluginVservicesManagement`
Seção "Gerentes". Liga gerentes ao serviço.
- `users_managers[]` (multi-select de usuários) → Usuário Gerente
- `groups_managers[]` (multi-select de grupos) → Grupo Gerente
- `service_id` (hidden, FK managedservice)
Modelagem sugerida: tabela(s) de vínculo serviço↔usuário e serviço↔grupo (ou uma tabela `managements` com `users_id`/`groups_id`).

### 2. Ativos cobertos pelo serviço — `PluginVservicesCobertosservico`
Tabela: `glpi_plugin_vservices_cobertosservicos`
"Adicionar ativos cobertos pelo serviço":
- `itemtype` (select de classes de ativo — ver lista abaixo)
- `items_id` (select do item, depende do itemtype)
- `contract_entry_date` (data de entrada em contrato)
- `plugin_vservices_managedservices_id` (FK)
- `ics_type` (uso a confirmar — provável discriminador)
- `impact` (presente hidden aqui; usado de fato na Composição)
Listas exibidas: "Ativos cobertos pelo serviço - N" e "Ativos removidos - N" (soft-delete/histórico de remoção). Há também uma seção "Banco de Dados".

Classes de ativo aceitas (itemtype): Ativo gerenciado (o próprio serviço), Cabo, Cartão SIM item, Chassis, Computador, Dispositivo de rede, Dispositivo passivo, Impressora, Modelo de cartucho, Modelo de insumo, Monitor, PDU, Periférico, Rack, Serviços Gerenciados, Software, Telefone.

### 3. Composição do Serviço — `PluginVservicesComposicaoservico`
Tabela: `glpi_plugin_vservices_composicaoservicos`
"Ativos que compõem o serviço":
- `itemtype_composicao` (select de classes de ativo)
- `items_id_composicao` (select do item)
- `impact` (select: **Parcial** / **Total**)
- `plugin_vservices_managedservices_id` (FK)
- `ics_type` (hidden)
Lista: "Listagem de ativos que compõem o serviço - N".

### 4. Financeiro — `PluginVservicesFinancialmanagedservice`
Aba controladora que renderiza várias sub-seções; cada valor é historizado por data de entrada em contrato (`record_date`/`date`). Campos por seção:

1. **Valor monetário mensal**: `value_month`, `record_date`, `description_mensal` (save `salvar_mensal`)
2. **Valor monetário por hora**: `value_hour`, `record_date` (save `salvar_hora`)
3. **Horas de suporte**: `is_supporthours` (toggle), `support_hours` (número), `users_id` (save `save_supporthours`)
4. **Limite de horas**: `is_hourslimit` (toggle), `hours_limit` (número) (save `save_config_limit_hours`)
5. **Valor monetário por classe de ativos cobertos**: `itemtype` (classe), `itemtypefinancial`/`items_id` (tipo/subtipo), `value_active`, `date` (add)
6. **Valor monetário por usuário**: `service_type`, `service_value`, `record_date` (save `salvar_usuario`)
7. **Valor monetário por banco de dados**: `service_type`, `service_value`, `record_date` (save `salvar_banco_de_dados`)
8. **Valor monetário por espaço de armazenamento**: `service_type`, `service_value`, `record_date` (save `salvar_armazenamento`)

Campos comuns: `plugin_vservices_managedservices_id` (FK), `itemtype`/`items_id`, `service_type` (discriminador para usuário/banco de dados/armazenamento).
Classe de ativo (seção 5) inclui: Tipo de computador, monitor, equipamento de rede, dispositivo, impressora, cartucho, insumo, telefone, rack, PDU, dispositivo passivo, cabo.

Modelagem sugerida (a refinar): tabelas de valores datados
- `..._financialmonthly` (value_month, description, record_date, service_id)
- `..._financialhourly` (value_hour, record_date, service_id)
- `..._financialperclass` (itemtype, items_id, value_active, date, service_id)
- `..._financialservice` (service_type ∈ {user, database, storage}, service_value, record_date, service_id, users_id)
- config: `is_supporthours`/`support_hours`, `is_hourslimit`/`hours_limit` (no próprio serviço ou tabela de config).

### 5. Configuração NMS — `PluginVservicesConfigurationnms`
Tabela: `glpi_plugin_vservices_configurationnms`
- `url_nms` (texto — Endereço NMS)
- `service_id` (FK)
Armazena a URL do NMS (Network Management System) do serviço.

### Abas nativas
- Documentos (`Document_Item`), Histórico (`Log`), Todos.

## Pendências de recon (a confirmar durante o build)
- Colunas exatas das listas de "cobertos"/"composição" (ambiente atual tem 0 registros nessas abas do id=17). Checar id=16 ou criar registro de teste.
- Semântica de `ics_type` e da seção "Banco de Dados" na aba de cobertos.
- Como os contadores de chamados/problemas/mudanças são calculados.
- Direitos/perfis (rights) e registro de menu (`getMenuContent`).
- Massive actions, search options completas, ícones.
