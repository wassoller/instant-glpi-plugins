<?php
/**
 * Lógica do bloco "Gestão financeira": lê os dados financeiros do plugin
 * managedservices (valores historizados) e agrega em KPIs, rankings e relatórios.
 *
 * "Valor atual" de uma dimensão = registro mais recente (por record_date)
 * de cada (serviço, tipo, ativo, usuário).
 *
 * Sub-abas (paridade com o vReports original):
 *   - Dashboards  → getKpis() + gráficos.
 *   - Relatórios  → 2 relatórios:
 *       1  Extrato financeiro        (detalhamento por entidade/serviço)
 *       2  Faturamento financeiro    (resumo do total faturado no período)
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsFinancial
{
    private const MS_TABLE  = 'glpi_plugin_managedservices_managedservices';
    private const FV_TABLE  = 'glpi_plugin_managedservices_financialvalues';
    private const CA_TABLE  = 'glpi_plugin_managedservices_coveredassets';
    private const MS_FK     = 'plugin_managedservices_managedservices_id';

    /** O plugin managedservices está instalado? */
    public static function isAvailable(): bool
    {
        global $DB;
        return $DB->tableExists(self::MS_TABLE) && $DB->tableExists(self::FV_TABLE);
    }

    /**
     * Subconsulta com o valor mais recente de cada dimensão financeira,
     * já restrita às entidades ativas (via join ao serviço).
     */
    private static function latestValuesSql(): string
    {
        $ms  = self::MS_TABLE;
        $fv  = self::FV_TABLE;
        // A restrição precisa referenciar o **alias** `ms` (e não o nome da tabela):
        // com a sessão restrita a uma entidade o GLPI gera `tabela`.`entities_id`,
        // que não existe na consulta aliasada — e a query inteira falhava.
        $ent = getEntitiesRestrictRequest('AND', 'ms');
        return "SELECT fv.*, ms.entities_id AS ms_entity, ms.users_id AS ms_client,
                       ROW_NUMBER() OVER (
                           PARTITION BY fv.plugin_managedservices_managedservices_id, fv.value_type, fv.itemtype, fv.items_id, fv.users_id
                           ORDER BY fv.record_date DESC, fv.id DESC
                       ) AS rn
                FROM `$fv` fv
                INNER JOIN `$ms` ms ON ms.id = fv.plugin_managedservices_managedservices_id
                WHERE 1 $ent";
    }

    private static function scalar(string $sql): float
    {
        global $DB;
        $res = $DB->doQuery($sql);
        $row = $DB->fetchAssoc($res);
        return (float) ($row['v'] ?? 0);
    }

    // =====================================================================
    //  Dashboards
    // =====================================================================

    /**
     * @return array<int,array{title:string,value:string,icon:string,accent:string}>
     */
    public static function getKpis(): array
    {
        global $DB;

        $ms  = self::MS_TABLE;
        $ent = getEntitiesRestrictRequest('AND', $ms);
        $latest = self::latestValuesSql();

        // Receita prevista = valores recorrentes (mensal, por classe de ativo, por
        // usuário). O valor/hora é uma tarifa, não receita — só vira dinheiro no
        // extrato, multiplicado pelas horas de tarefa; fica de fora aqui.
        $receitaPrevista = self::scalar("SELECT COALESCE(SUM(value),0) AS v FROM ($latest) l WHERE rn=1 AND value_type <> 'hourly'");
        $receitaMensal   = self::scalar("SELECT COALESCE(SUM(value),0) AS v FROM ($latest) l WHERE rn=1 AND value_type='monthly'");
        $mediaMensal     = self::scalar("SELECT COALESCE(AVG(value),0) AS v FROM ($latest) l WHERE rn=1 AND value_type='monthly'");
        $receitaAtivos   = self::scalar("SELECT COALESCE(SUM(value),0) AS v FROM ($latest) l WHERE rn=1 AND value_type='perclass'");
        $clientes        = self::scalar("SELECT COUNT(DISTINCT users_id) AS v FROM `$ms` WHERE users_id>0 $ent");
        $servicos        = self::scalar("SELECT COUNT(*) AS v FROM `$ms` WHERE 1 $ent");

        return [
            ['title' => __('Receita prevista', 'servicereports'), 'value' => self::money($receitaPrevista), 'icon' => 'ti ti-trending-up', 'accent' => 'primary'],
            ['title' => __('Receita de ativos cobertos', 'servicereports'), 'value' => self::money($receitaAtivos), 'icon' => 'ti ti-device-desktop', 'accent' => 'primary'],
            ['title' => __('Valor médio mensal dos serviços', 'servicereports'), 'value' => self::money($mediaMensal), 'icon' => 'ti ti-calculator', 'accent' => 'primary'],
            ['title' => __('Receita mensal dos serviços', 'servicereports'), 'value' => self::money($receitaMensal), 'icon' => 'ti ti-cash', 'accent' => 'primary'],
            ['title' => __('Clientes', 'servicereports'), 'value' => (string) (int) $clientes, 'icon' => 'ti ti-users', 'accent' => 'info'],
            ['title' => __('Serviços', 'servicereports'), 'value' => (string) (int) $servicos, 'icon' => 'ti ti-server-cog', 'accent' => 'info'],
        ];
    }

    /**
     * Top 10 receita prevista por entidade.
     *
     * @return array<int,array{label:string,value:float}>
     */
    public static function getRevenueByEntity(): array
    {
        global $DB;
        $latest = self::latestValuesSql();
        $sql = "SELECT ms_entity AS entity, SUM(value) AS total
                FROM ($latest) l WHERE rn=1 AND value_type <> 'hourly'
                GROUP BY ms_entity ORDER BY total DESC LIMIT 10";
        $out = [];
        foreach ($DB->request($sql) as $row) {
            $out[] = ['label' => Dropdown::getDropdownName('glpi_entities', (int) $row['entity']), 'value' => (float) $row['total']];
        }
        return $out;
    }

    /**
     * Top 10 valor médio por tipo de ativo (dimensão perclass).
     *
     * @return array<int,array{label:string,value:float}>
     */
    public static function getAvgByAssetType(): array
    {
        global $DB;
        $latest = self::latestValuesSql();
        $sql = "SELECT itemtype, AVG(value) AS media
                FROM ($latest) l WHERE rn=1 AND value_type='perclass' AND itemtype IS NOT NULL AND itemtype<>''
                GROUP BY itemtype ORDER BY media DESC LIMIT 10";
        $out = [];
        foreach ($DB->request($sql) as $row) {
            $itemtype = $row['itemtype'];
            $label = (class_exists($itemtype)) ? $itemtype::getTypeName(1) : $itemtype;
            $out[] = ['label' => $label, 'value' => (float) $row['media']];
        }
        return $out;
    }

    // =====================================================================
    //  Relatórios — coleta (Extrato financeiro / Faturamento)
    // =====================================================================

    /** Valor mais recente de um value_type escalar (monthly/hourly) do serviço. */
    private static function latestScalarValue(int $sid, string $type): float
    {
        global $DB;
        $fv = self::FV_TABLE;
        $type = $DB->escape($type);
        $res = $DB->doQuery(
            "SELECT value FROM `$fv`
             WHERE " . self::MS_FK . " = $sid AND value_type = '$type'
             ORDER BY record_date DESC, id DESC LIMIT 1"
        );
        $row = $DB->fetchAssoc($res);
        return (float) ($row['value'] ?? 0);
    }

    /** Coluna do "tipo" (…types_id) para casar um ativo coberto a um valor perclass. */
    private static function assetTypeField(string $itemtype): ?string
    {
        $map = [
            'Computer'           => 'computertypes_id',
            'Monitor'            => 'monitortypes_id',
            'Printer'            => 'printertypes_id',
            'NetworkEquipment'   => 'networkequipmenttypes_id',
            'Peripheral'         => 'peripheraltypes_id',
            'Phone'              => 'phonetypes_id',
            'Rack'               => 'racktypes_id',
            'PDU'                => 'pdutypes_id',
            'Passivedcequipment' => 'passivedcequipmenttypes_id',
        ];
        return $map[$itemtype] ?? null;
    }

    /**
     * Valor monetário de ativos do serviço: para cada ativo coberto, casa o
     * valor "perclass" (por tipo) com o tipo do ativo e soma.
     */
    private static function serviceAssetValue(int $sid): float
    {
        global $DB;

        // Últimos valores perclass do serviço, indexados por "ItemtypeType#idDoTipo".
        $fv = self::FV_TABLE;
        $perclass = [];
        $res = $DB->doQuery(
            "SELECT itemtype, items_id, value FROM (
                SELECT itemtype, items_id, value,
                       ROW_NUMBER() OVER (PARTITION BY itemtype, items_id ORDER BY record_date DESC, id DESC) rn
                FROM `$fv`
                WHERE " . self::MS_FK . " = $sid AND value_type = 'perclass'
             ) t WHERE rn = 1"
        );
        while ($r = $DB->fetchAssoc($res)) {
            $perclass[$r['itemtype'] . '#' . (int) $r['items_id']] = (float) $r['value'];
        }
        if (empty($perclass)) {
            return 0.0;
        }

        $total = 0.0;
        $assets = $DB->request([
            'FROM'  => self::CA_TABLE,
            'WHERE' => [self::MS_FK => $sid, 'is_deleted' => 0],
        ]);
        foreach ($assets as $a) {
            $itemtype = (string) $a['itemtype'];
            $field    = self::assetTypeField($itemtype);
            if (!$field || !class_exists($itemtype)) {
                continue;
            }
            $tbl = getTableForItemType($itemtype);
            $row = $DB->request(['SELECT' => $field, 'FROM' => $tbl, 'WHERE' => ['id' => (int) $a['items_id']]])->current();
            $typeId = (int) ($row[$field] ?? 0);
            $key    = $itemtype . 'Type#' . $typeId;
            $total += $perclass[$key] ?? 0.0;
        }
        return $total;
    }

    /**
     * Categoria do serviço + **toda a sua descendência** na árvore de categorias.
     * Ex.: "Suporte Avançado" traz também "Suporte Avançado > Active Directory >
     * Criação / Alteração de GPO".
     *
     * @return int[]
     */
    public static function categoryTreeIds(int $catId): array
    {
        if ($catId <= 0) {
            return [];
        }
        // getSonsOf() devolve a própria categoria + todas as filhas (recursivo).
        $ids = array_map('intval', array_values(getSonsOf('glpi_itilcategories', $catId)));
        return $ids ?: [$catId];
    }

    /**
     * IDs de chamados vinculados ao serviço: por categoria do serviço — incluindo
     * as subcategorias (glpi_tickets.itilcategories_id ∈ árvore da categoria) —
     * e/ou por ativo coberto (glpi_items_tickets). A busca por categoria respeita
     * a entidade do serviço (mais as filhas, se o serviço for recursivo).
     *
     * @return int[]
     */
    private static function linkedTicketIds(int $sid, int $entity, int $catId, bool $recursive = false): array
    {
        global $DB;
        $ids = [];

        // Serviço recursivo cobre também as entidades filhas (mesma semântica
        // do `is_recursive` do GLPI); senão, só a entidade do próprio serviço.
        $entities = $recursive
            ? array_map('intval', array_values(getSonsOf('glpi_entities', $entity)))
            : [$entity];

        $cats = self::categoryTreeIds($catId);
        if (!empty($cats)) {
            $res = $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['itilcategories_id' => $cats, 'entities_id' => $entities, 'is_deleted' => 0],
            ]);
            foreach ($res as $r) {
                $ids[(int) $r['id']] = true;
            }
        }

        $ca = self::CA_TABLE;
        $res = $DB->doQuery(
            "SELECT DISTINCT it.tickets_id AS id
             FROM `glpi_items_tickets` it
             INNER JOIN `$ca` ca ON ca.itemtype = it.itemtype AND ca.items_id = it.items_id
             WHERE ca.`" . self::MS_FK . "` = $sid AND ca.is_deleted = 0"
        );
        while ($r = $DB->fetchAssoc($res)) {
            $ids[(int) $r['id']] = true;
        }

        return array_keys($ids);
    }

    /**
     * Chamados do serviço faturáveis no período: os **fechados** dentro dele
     * (`glpi_tickets.closedate` entre início e fim). Regra de negócio da Instant
     * (2026-08-25): o chamado só vira dinheiro quando encerra, e aí entra com
     * **todas** as suas tarefas, inclusive as de meses anteriores — um chamado
     * aberto em 09/10, com tarefas em 11/10, 02/11 e 04/11 e fechado em 14/11
     * não aparece no extrato de outubro e soma as três horas no de novembro.
     * Chamado em aberto (ou Solucionado sem fechar) não entra em extrato nenhum.
     *
     * Devolve o que a listagem do extrato exibe: tipo, requerente, abertura,
     * fechamento e o tempo **total** de tarefas do chamado.
     *
     * @param int[] $ticketIds
     * @return array<int,array<string,mixed>>
     */
    private static function ticketsInPeriod(array $ticketIds, string $startDt, string $endDt): array
    {
        global $DB;
        if (empty($ticketIds)) {
            return [];
        }
        $in = implode(',', array_map('intval', $ticketIds));
        $s  = $DB->escape($startDt);
        $e  = $DB->escape($endDt);

        // Tempo de tarefas por chamado: **todas** as tarefas, sem filtro de data —
        // quem delimita o período é o fechamento do chamado (ver docblock).
        $seconds = [];
        $res = $DB->doQuery(
            "SELECT tickets_id, COALESCE(SUM(actiontime),0) AS t
             FROM `glpi_tickettasks`
             WHERE tickets_id IN ($in)
             GROUP BY tickets_id"
        );
        while ($r = $DB->fetchAssoc($res)) {
            $seconds[(int) $r['tickets_id']] = (int) $r['t'];
        }

        // Requerentes (pode haver mais de um por chamado).
        $requesters = [];
        $res = $DB->doQuery(
            "SELECT tickets_id, users_id
             FROM `glpi_tickets_users`
             WHERE tickets_id IN ($in) AND type = " . CommonITILActor::REQUESTER . " AND users_id > 0"
        );
        while ($r = $DB->fetchAssoc($res)) {
            $requesters[(int) $r['tickets_id']][] = getUserName((int) $r['users_id']);
        }

        // Período = data de **fechamento** (não a de abertura): o chamado é
        // faturado no mês em que encerrou. `closedate` NULL (aberto/pendente/
        // apenas solucionado) fica de fora — o BETWEEN já descarta.
        $res = $DB->doQuery(
            "SELECT id, name, date, closedate, solvedate, status, type, itilcategories_id
             FROM `glpi_tickets`
             WHERE id IN ($in) AND is_deleted = 0 AND closedate BETWEEN '$s' AND '$e'
             ORDER BY closedate DESC"
        );
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $tid = (int) $r['id'];
            $out[] = [
                'id'        => $tid,
                'name'      => (string) $r['name'],
                'type'      => (int) $r['type'],
                'date'      => (string) $r['date'],
                'closedate' => (string) ($r['closedate'] ?: $r['solvedate'] ?: ''),
                'status'    => (int) $r['status'],
                'cat'       => (int) $r['itilcategories_id'],
                'requester' => implode(', ', $requesters[$tid] ?? []),
                'seconds'   => $seconds[$tid] ?? 0,
            ];
        }
        return $out;
    }

    /** Ativos cobertos do serviço (para a listagem do extrato). */
    private static function serviceCoveredAssets(int $sid): array
    {
        global $DB;
        $out = [];
        $assets = $DB->request([
            'FROM'  => self::CA_TABLE,
            'WHERE' => [self::MS_FK => $sid, 'is_deleted' => 0],
            'ORDER' => 'itemtype',
        ]);
        foreach ($assets as $a) {
            $itemtype = (string) $a['itemtype'];
            $name     = "#{$a['items_id']}";
            if (class_exists($itemtype)) {
                $obj = new $itemtype();
                if ($obj->getFromDB((int) $a['items_id'])) {
                    $name = $obj->getName();
                }
            }
            $typename = class_exists($itemtype) ? $itemtype::getTypeName(1) : $itemtype;
            $out[] = [
                'itemtype'   => $itemtype,
                'items_id'   => (int) $a['items_id'],
                'typename'   => $typename,
                'name'       => $name,
                'entry_date' => (string) ($a['contract_entry_date'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Extrato financeiro completo: por entidade → por serviço.
     *
     * Componentes por serviço:
     *   - mensal   : último valor "monthly".
     *   - ativos   : soma dos valores "perclass" casados aos ativos cobertos.
     *   - categoria: valor por categoria de chamado — sem modelo de dados no
     *                managedservices (o vReports original tinha fonte própria); 0.
     *   - extras   : valores extras ligados a chamados — idem; 0.
     *   - tarefas  : tempo (Σ actiontime de **todas** as tarefas dos chamados
     *                fechados no período) × valor/hora ("hourly").
     *   - total    : soma dos componentes.
     *
     * @return array<int,array{name:string,summary:array<string,float>,services:array}>
     */
    public static function getExtrato(string $startDt, string $endDt): array
    {
        global $DB;

        $ent = getEntitiesRestrictRequest('AND', self::MS_TABLE);
        $res = $DB->doQuery(
            "SELECT id, name, entities_id, is_recursive, users_id, itilcategories_id
             FROM `" . self::MS_TABLE . "`
             WHERE 1 $ent
             ORDER BY entities_id, name"
        );

        $byEntity = [];
        while ($svc = $DB->fetchAssoc($res)) {
            $sid    = (int) $svc['id'];
            $entId  = (int) $svc['entities_id'];
            $catId  = (int) $svc['itilcategories_id'];

            $mensal = self::latestScalarValue($sid, 'monthly');
            $ativos = self::serviceAssetValue($sid);
            $hourly = self::latestScalarValue($sid, 'hourly');

            $linked   = self::linkedTicketIds($sid, $entId, $catId, (bool) $svc['is_recursive']);
            $tickets  = self::ticketsInPeriod($linked, $startDt, $endDt);
            // Custos por chamado: hora = tempo de tarefas × valor/hora do serviço;
            // categoria = sem modelo de dados no managedservices (ver docblock) → 0.
            $seconds = 0;
            foreach ($tickets as &$t) {
                $t['cost_hour']  = $hourly > 0 ? ($t['seconds'] / 3600.0) * $hourly : 0.0;
                $t['cost_cat']   = 0.0;
                $t['cost_total'] = $t['cost_hour'] + $t['cost_cat'];
                $seconds        += (int) $t['seconds'];
            }
            unset($t);
            // O total sai da soma dos chamados listados (e não de uma consulta
            // própria): garante que o cabeçalho bata com a listagem.
            $taskVal = $hourly > 0 ? ($seconds / 3600.0) * $hourly : 0.0;

            $categoria = 0.0; // sem modelo de dados (ver docblock)
            $extras    = 0.0; // sem modelo de dados (ver docblock)
            $total     = $mensal + $ativos + $categoria + $extras + $taskVal;

            if (!isset($byEntity[$entId])) {
                $byEntity[$entId] = [
                    'name'     => self::entityName($entId),
                    'summary'  => ['total' => 0.0, 'fixos' => 0.0, 'categorias' => 0.0, 'hora' => 0.0, 'extras' => 0.0, 'ativos' => 0.0, 'segundos' => 0],
                    'services' => [],
                ];
            }

            $byEntity[$entId]['services'][] = [
                'id'           => $sid,
                'name'         => (string) $svc['name'],
                'cat'          => $catId,
                'mensal'       => $mensal,
                'ativos'       => $ativos,
                'categoria'    => $categoria,
                'extras'       => $extras,
                'task_seconds' => $seconds,
                'task_value'   => $taskVal,
                'total'        => $total,
                'coveredassets'=> self::serviceCoveredAssets($sid),
                'tickets'      => $tickets,
            ];

            $byEntity[$entId]['summary']['fixos']      += $mensal;
            $byEntity[$entId]['summary']['ativos']     += $ativos;
            $byEntity[$entId]['summary']['categorias'] += $categoria;
            $byEntity[$entId]['summary']['hora']       += $taskVal;
            $byEntity[$entId]['summary']['extras']     += $extras;
            $byEntity[$entId]['summary']['total']      += $total;
            $byEntity[$entId]['summary']['segundos']   += $seconds;
        }

        return $byEntity;
    }

    /**
     * Nome **curto** da entidade (só a folha, sem a árvore de pais).
     * O `Dropdown::getDropdownName` devolveria o completename
     * ("Instant > Standard > Uniletra"); no extrato queremos só "Uniletra".
     */
    public static function entityName(int $entityId): string
    {
        global $DB;

        if ($entityId <= 0) {
            // Entidade raiz: deixa o core resolver (nome traduzido).
            return Dropdown::getDropdownName('glpi_entities', $entityId);
        }
        $row  = $DB->request(['SELECT' => 'name', 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entityId]])->current();
        $name = (string) ($row['name'] ?? '');
        return $name !== '' ? $name : Dropdown::getDropdownName('glpi_entities', $entityId);
    }

    /** Nº total de serviços do extrato (base da paginação). */
    public static function countServices(array $extrato): int
    {
        $n = 0;
        foreach ($extrato as $ent) {
            $n += count($ent['services']);
        }
        return $n;
    }

    /**
     * Recorta o extrato numa página de serviços (10 em 10), preservando a ordem
     * e os totais por entidade; entidades sem serviço na página saem da lista.
     */
    public static function sliceExtrato(array $extrato, int $offset, int $perPage): array
    {
        $out = [];
        $i   = 0;
        $end = $offset + $perPage;
        foreach ($extrato as $entId => $ent) {
            $keep = [];
            foreach ($ent['services'] as $svc) {
                if ($i >= $offset && $i < $end) {
                    $keep[] = $svc;
                }
                $i++;
            }
            if (!empty($keep)) {
                $ent['services'] = $keep;
                $out[$entId] = $ent;
            }
            if ($i >= $end) {
                break;
            }
        }
        return $out;
    }

    /** Total geral faturado no período (Faturamento financeiro). */
    public static function getFaturamentoTotal(string $startDt, string $endDt): float
    {
        $total = 0.0;
        foreach (self::getExtrato($startDt, $endDt) as $ent) {
            $total += $ent['summary']['total'];
        }
        return $total;
    }

    // =====================================================================
    //  Relatórios — renderização
    // =====================================================================

    /**
     * CSS do extrato (layout "Institucional", escolhido pela Instant em 2026-08-25).
     *
     * Fica num `<style>` próprio, emitido uma vez por página, em vez de estilo
     * inline em cada `echo`: precisamos de `:nth-child` (zebra), `thead`
     * repetido a cada folha e um bloco `@media print`, que atributo `style` não
     * faz. Todas as classes têm prefixo `sr-` — as utilitárias do tema do GLPI
     * não servem aqui (a `.small`, por exemplo, quebra o texto).
     */
    private static function styles(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        echo "<style>
/* O extrato é um documento: papel branco, independente do tema do GLPI. */
.sr-ext { background:#fff; color:#16202A; font-variant-numeric:tabular-nums;
          -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.sr-ext-sheet { padding:18px 20px 20px; }
@media screen { .sr-ext { border:1px solid #D8DEE4; border-radius:2px;
                          box-shadow:0 1px 2px rgba(23,33,43,.06),0 8px 22px rgba(23,33,43,.08); } }

/* Faixa de cabeçalho: logo + título à esquerda, empresa/período/emissão à direita. */
.sr-band { display:flex; align-items:flex-end; justify-content:space-between; gap:24px;
           padding-bottom:10px; border-bottom:2px solid #223140; }
.sr-brand { display:flex; align-items:center; gap:12px; }
.sr-brand img { height:34px; width:auto; display:block; }
.sr-doc-title { font-size:1.15rem; font-weight:700; line-height:1.15; }
.sr-doc-sub { font-size:.72rem; color:#5C6B79; margin-top:1px; }
.sr-meta { display:grid; grid-template-columns:auto auto; gap:1px 14px; font-size:.72rem; margin:0; }
.sr-meta dt { color:#8B98A5; text-transform:uppercase; letter-spacing:.08em; font-weight:600; }
.sr-meta dd { margin:0; text-align:right; font-weight:600; }

/* Resumo da entidade: o total em destaque + três indicadores; o resto numa linha fina. */
.sr-kpis { display:grid; grid-template-columns:1.25fr 1fr 1fr 1fr; gap:8px; margin-top:14px; }
.sr-kpi { border:1px solid #D8DEE4; border-top:3px solid #D8DEE4; padding:7px 10px 8px; }
.sr-kpi-lead { border-top-color:#0F6F8C; background:#F0F4F7; }
.sr-kpi span { display:block; font-size:.62rem; letter-spacing:.1em; text-transform:uppercase;
               color:#8B98A5; font-weight:600; }
.sr-kpi strong { display:block; font-size:1.3rem; font-weight:600; margin-top:2px; line-height:1.2; }
.sr-kpi-lead strong { color:#0F6F8C; font-size:1.5rem; }
.sr-subline { margin-top:7px; font-size:.72rem; color:#5C6B79; display:flex; flex-wrap:wrap; gap:3px 18px; }
.sr-subline b { color:#16202A; }

/* Serviço: barra com filete, e os seis valores em grade (antes eram seis frases). */
.sr-svc { margin-top:16px; break-inside:avoid; }
.sr-svc-bar { display:flex; align-items:baseline; justify-content:space-between; gap:14px;
              border-left:4px solid #0F6F8C; background:#F0F4F7; padding:6px 10px; }
.sr-svc-bar h4 { margin:0; font-size:.92rem; font-weight:600; }
.sr-svc-tot { font-size:.68rem; color:#5C6B79; text-transform:uppercase; letter-spacing:.08em;
              font-weight:600; white-space:nowrap; }
.sr-svc-tot b { font-size:.95rem; color:#16202A; margin-left:6px; letter-spacing:0; }
.sr-vals { display:grid; grid-template-columns:repeat(6,1fr); margin:8px 0 10px; }
.sr-vals div { padding:0 10px; border-left:1px solid #EAEEF2;
                display:flex; flex-direction:column; justify-content:space-between; }
.sr-vals div:first-child { border-left:0; }
.sr-vals span { display:block; font-size:.6rem; letter-spacing:.07em; text-transform:uppercase;
                color:#8B98A5; font-weight:600; }
.sr-vals b { display:block; font-size:.82rem; font-weight:600; margin-top:1px; }

/* Listagem de chamados: cabeçalho escuro, zebra e largura de coluna fixa —
   sem `table-layout:fixed` cada tabela da folha escolhe larguras diferentes. */
.sr-list-cap { font-size:.68rem; letter-spacing:.08em; text-transform:uppercase; color:#5C6B79;
               font-weight:600; margin:0 0 4px; padding-left:10px; }
.sr-tk { width:100%; border-collapse:collapse; table-layout:fixed; font-size:.7rem; }
.sr-tk thead th { background:#223140; color:#fff; font-weight:600; text-align:left; padding:5px 7px;
                  font-size:.62rem; letter-spacing:.04em; text-transform:uppercase; }
.sr-tk tbody td { padding:5px 7px; border-bottom:1px solid #EAEEF2;
                  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.sr-tk tbody tr:nth-child(even) td { background:#F5F8FA; }
.sr-tk .r { text-align:right; }
.sr-tk a { color:#0F6F8C; text-decoration:none; }
.sr-tk th:nth-child(1) { width:4.5%; }
.sr-tk th:nth-child(2) { width:19%; }
.sr-tk th:nth-child(3) { width:7.5%; }
.sr-tk th:nth-child(4) { width:22%; }
.sr-tk th:nth-child(5) { width:9.5%; }
.sr-tk th:nth-child(6) { width:9.5%; }
.sr-tk th:nth-child(7) { width:9.5%; }
.sr-tk th:nth-child(8) { width:6%; }
.sr-tk th:nth-child(9) { width:6.5%; }
.sr-tk th:nth-child(10) { width:6%; }

.sr-empty { font-size:.74rem; color:#5C6B79; padding-left:10px; }
.sr-warn { margin:6px 0 0 10px; padding:6px 10px; border-left:3px solid #C77700; background:#FDF6E8;
           font-size:.72rem; color:#5C4A22; }
.sr-actions { display:flex; justify-content:flex-end; gap:8px; margin-bottom:12px; }

/* Rodapé: no papel gruda no pé de toda folha (a margem de baixo reserva o espaço). */
.sr-foot { display:flex; justify-content:space-between; gap:16px; font-size:.62rem; color:#8B98A5;
           border-top:1px solid #D8DEE4; padding-top:6px; margin-top:14px; }

@media print {
  @page { size:A4 landscape; margin:10mm 10mm 16mm; }
  .sr-foot { position:fixed; left:0; right:0; bottom:-11mm; margin:0; background:#fff; }
  .sr-ext-sheet { padding:0; }
  .sr-tk thead { display:table-header-group; }  /* repete o cabeçalho da tabela na folha seguinte */
  .sr-tk tbody tr { break-inside:avoid; }
}
</style>";
    }

    /**
     * URL da logo usada no cabeçalho do extrato (vazio se o arquivo não existir).
     * Basta trocar `pics/instant-logo.png` para personalizar.
     */
    private static function logoUrl(): string
    {
        global $CFG_GLPI;

        foreach (['instant-logo.png', 'logo.png', 'logo.jpg', 'logo.svg'] as $file) {
            if (file_exists(GLPI_ROOT . '/plugins/servicereports/pics/' . $file)) {
                return $CFG_GLPI['root_doc'] . '/plugins/servicereports/pics/' . $file;
            }
        }
        return '';
    }

    /** Faixa de cabeçalho do documento (mesma na tela e no papel). */
    private static function docBand(string $entityName, string $start, string $end): void
    {
        $logo = self::logoUrl();

        echo "<div class='sr-band'>";
        echo "<div class='sr-brand'>";
        if ($logo !== '') {
            echo "<img src='" . Html::cleanInputText($logo) . "' alt=''>";
        }
        echo "<div><div class='sr-doc-title'>" . __('Extrato de consumo de serviços', 'servicereports') . "</div>";
        echo "<div class='sr-doc-sub'>" . __('Serviços gerenciados', 'servicereports') . "</div></div>";
        echo "</div>";

        echo "<dl class='sr-meta'>";
        echo "<dt>" . __('Empresa', 'servicereports') . "</dt><dd>" . $entityName . "</dd>";
        echo "<dt>" . __('Período', 'servicereports') . "</dt><dd>"
            . Html::convDate($start) . " – " . Html::convDate($end) . "</dd>";
        echo "<dt>" . __('Emissão', 'servicereports') . "</dt><dd>"
            . Html::convDateTime(date('Y-m-d H:i:s')) . "</dd>";
        echo "</dl>";
        echo "</div>";
    }

    /** Rodapé do documento: quem imprimiu e quando. */
    private static function docFoot(string $entityName): void
    {
        $user = getUserName(Session::getLoginUserID());

        echo "<div class='sr-foot'>";
        echo "<span>" . __('Extrato de consumo de serviços', 'servicereports') . " · " . $entityName . "</span>";
        echo "<span>" . sprintf(
            __('Impresso por %1$s em %2$s', 'servicereports'),
            $user,
            Html::convDateTime(date('Y-m-d H:i:s'))
        ) . "</span>";
        echo "</div>";
    }

    /**
     * Resumo financeiro da entidade (mesmo bloco na tela e no papel).
     *
     * O total ganha um cartão destacado; fixos, hora e ativos vêm ao lado.
     * Categorias, extras e tempo de tarefas caem numa linha fina abaixo — os
     * dois primeiros são sempre R$ 0,00 (sem modelo de dados no managedservices)
     * e não merecem o mesmo peso visual do que é dinheiro de verdade.
     */
    private static function renderEntitySummary(array $s): void
    {
        echo "<div class='sr-kpis'>";
        echo "<div class='sr-kpi sr-kpi-lead'><span>" . __('Valor monetário total', 'servicereports')
            . "</span><strong>" . self::money($s['total']) . "</strong></div>";
        echo "<div class='sr-kpi'><span>" . __('Valores fixos', 'servicereports')
            . "</span><strong>" . self::money($s['fixos']) . "</strong></div>";
        echo "<div class='sr-kpi'><span>" . __('Valores de hora', 'servicereports')
            . "</span><strong>" . self::money($s['hora']) . "</strong></div>";
        echo "<div class='sr-kpi'><span>" . __('Valores de ativos', 'servicereports')
            . "</span><strong>" . self::money($s['ativos']) . "</strong></div>";
        echo "</div>";

        echo "<div class='sr-subline'>";
        echo "<span>" . __('Categorias de chamado', 'servicereports') . " <b>" . self::money($s['categorias']) . "</b></span>";
        echo "<span>" . __('Extras relacionados a chamados', 'servicereports') . " <b>" . self::money($s['extras']) . "</b></span>";
        echo "<span>" . __('Tempo total de tarefas', 'servicereports') . " <b>" . self::duration((int) ($s['segundos'] ?? 0)) . "</b></span>";
        echo "</div>";
    }

    /**
     * Bloco de um serviço: barra com nome e custo total, os seis valores em
     * grade e a listagem de chamados. Mesmo bloco na tela e no papel — só o
     * número do chamado muda (link fora do PDF).
     */
    private static function renderServiceBlock(array $svc, bool $print = false): void
    {
        echo "<div class='sr-svc'>";
        echo "<div class='sr-svc-bar'>";
        echo "<h4>" . $svc['name'] . "</h4>";
        echo "<div class='sr-svc-tot'>" . __('Custo total', 'servicereports')
            . " <b>" . self::money($svc['total']) . "</b></div>";
        echo "</div>";

        echo "<div class='sr-vals'>";
        foreach ([
            [__('Mensal', 'servicereports'),          self::money($svc['mensal'])],
            [__('Ativos', 'servicereports'),          self::money($svc['ativos'])],
            [__('Categoria', 'servicereports'),       self::money($svc['categoria'])],
            [__('Extras', 'servicereports'),          self::money($svc['extras'])],
            [__('Tarefas', 'servicereports'),         self::money($svc['task_value'])],
            [__('Tempo de tarefas', 'servicereports'), self::duration((int) $svc['task_seconds'])],
        ] as [$label, $value]) {
            echo "<div><span>$label</span><b>$value</b></div>";
        }
        echo "</div>";

        self::renderTicketList($svc, $print);
        echo "</div>";
    }

    /**
     * Listagem dos chamados do serviço faturados no período — os **fechados**
     * dentro dele, com o tempo total de tarefas (nº vira link só fora do PDF).
     */
    private static function renderTicketList(array $svc, bool $print = false): void
    {
        global $CFG_GLPI;

        $n = count($svc['tickets']);
        echo "<p class='sr-list-cap'>" . __('Chamados vinculados ao serviço, fechados no período', 'servicereports')
            . ($n > 0 ? " — $n" : '') . "</p>";

        if ($n === 0) {
            echo "<div class='sr-empty'>" . __('Não há chamados vinculados ao serviço fechados no período', 'servicereports') . "</div>";
            // Causa mais comum de relatório zerado: não há por onde vincular chamados.
            if (empty($svc['cat']) && empty($svc['coveredassets'])) {
                echo "<div class='sr-warn'>"
                    . __('O serviço não tem "Categoria de chamado" definida em Serviços Gerenciados nem ativos cobertos — sem um dos dois não há como vincular chamados, e os valores de hora/tarefa ficam zerados.', 'servicereports')
                    . "</div>";
            }
            return;
        }

        echo "<table class='sr-tk'>";
        echo "<thead><tr>"
            . "<th>" . __('Nº', 'servicereports') . "</th>"
            . "<th>" . __('Título', 'servicereports') . "</th>"
            . "<th>" . __('Tipo', 'servicereports') . "</th>"
            . "<th>" . __('Categoria', 'servicereports') . "</th>"
            . "<th>" . __('Requerente', 'servicereports') . "</th>"
            . "<th>" . __('Abertura', 'servicereports') . "</th>"
            . "<th>" . __('Fechamento', 'servicereports') . "</th>"
            . "<th class='r'>" . __('Horas', 'servicereports') . "</th>"
            . "<th class='r'>" . __('Custo hora', 'servicereports') . "</th>"
            . "<th class='r'>" . __('Custo chamado', 'servicereports') . "</th>"
            . "</tr></thead><tbody>";
        foreach ($svc['tickets'] as $t) {
            echo "<tr>";
            if ($print) {
                // No PDF o nº do chamado sai como texto (link não serve no papel).
                echo "<td>" . $t['id'] . "</td>";
            } else {
                $url = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $t['id'];
                echo "<td><a href='" . Html::cleanInputText($url) . "'>" . $t['id'] . "</a></td>";
            }
            echo "<td title='" . Html::cleanInputText($t['name']) . "'>" . $t['name'] . "</td>";
            echo "<td>" . Ticket::getTicketTypeName($t['type']) . "</td>";
            $cat = $t['cat'] ? Dropdown::getDropdownName('glpi_itilcategories', $t['cat']) : '-';
            echo "<td title='" . Html::cleanInputText($cat) . "'>" . $cat . "</td>";
            echo "<td>" . ($t['requester'] !== '' ? $t['requester'] : '-') . "</td>";
            echo "<td>" . Html::convDateTime($t['date']) . "</td>";
            echo "<td>" . ($t['closedate'] !== '' ? Html::convDateTime($t['closedate']) : '-') . "</td>";
            echo "<td class='r'>" . self::hms((int) $t['seconds']) . "</td>";
            echo "<td class='r'>" . self::money((float) $t['cost_hour']) . "</td>";
            echo "<td class='r'>" . self::money((float) $t['cost_total']) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

    /**
     * Relatório 1 — Extrato financeiro detalhado, versão de tela.
     *
     * Mesmo documento do PDF (layout "Institucional"), com os botões de CSV/PDF
     * e o nº do chamado como link.
     *
     * @param array $extrato  saída de getExtrato()
     */
    public static function renderExtrato(
        array $extrato,
        string $start,
        string $end,
        string $exportCsvUrl,
        string $pdfUrl
    ): void {
        self::styles();

        echo "<div class='sr-actions'>";
        echo "<a href='" . Html::cleanInputText($exportCsvUrl) . "' class='btn btn-outline-success btn-sm'><i class='ti ti-file-spreadsheet me-1'></i>CSV</a>";
        echo "<a href='" . Html::cleanInputText($pdfUrl) . "' target='_blank' class='btn btn-outline-danger btn-sm'><i class='ti ti-file-type-pdf me-1'></i>PDF</a>";
        echo "</div>";

        if (empty($extrato)) {
            echo "<div class='alert alert-info'>" . __('Nenhum serviço encontrado para o período.', 'servicereports') . "</div>";
            return;
        }

        foreach ($extrato as $ent) {
            echo "<div class='sr-ext mb-4'><div class='sr-ext-sheet'>";
            self::docBand($ent['name'], $start, $end);
            self::renderEntitySummary($ent['summary']);
            foreach ($ent['services'] as $svc) {
                self::renderServiceBlock($svc);
            }
            self::docFoot($ent['name']);
            echo "</div></div>";
        }
    }

    /**
     * Extrato na visão de impressão/PDF: uma empresa por página, sem os botões
     * da tela e sem links. Paisagem — a listagem de chamados tem 10 colunas.
     */
    public static function renderExtratoPrint(array $extrato, string $start, string $end): void
    {
        self::styles();

        if (empty($extrato)) {
            echo "<div class='sr-ext'><div class='sr-ext-sheet'>";
            self::docBand('-', $start, $end);
            echo "<div class='sr-empty' style='margin-top:16px'>" . __('Nenhum serviço encontrado para o período.', 'servicereports') . "</div>";
            echo "</div></div>";
            return;
        }

        $first = true;
        foreach ($extrato as $ent) {
            echo "<div class='sr-ext'" . ($first ? '' : " style='page-break-before:always'") . "><div class='sr-ext-sheet'>";
            self::docBand($ent['name'], $start, $end);
            self::renderEntitySummary($ent['summary']);
            foreach ($ent['services'] as $svc) {
                self::renderServiceBlock($svc, true);
            }
            self::docFoot($ent['name']);
            echo "</div></div>";
            $first = false;
        }
    }

    /**
     * Relatório 2 — Faturamento financeiro (resumo do período).
     */
    public static function renderFaturamento(string $start, string $end, string $startDt, string $endDt, string $exportCsvUrl): void
    {
        $total = self::getFaturamentoTotal($startDt, $endDt);

        echo "<div class='text-center mb-3'>";
        echo "<h3 class='mb-0'>" . __('Faturamento financeiro', 'servicereports') . " ";
        echo "<a href='" . Html::cleanInputText($exportCsvUrl) . "' class='btn btn-link btn-sm p-0 align-baseline' title='CSV'><i class='ti ti-download'></i></a>";
        echo "</h3></div>";

        echo "<div class='card shadow-sm'><div class='card-body'>";
        echo "<h5 class='mb-2'>" . __('Resumo financeiro geral', 'servicereports') . "</h5>";
        echo "<div class='text-muted mb-2'>" . __('Período de pesquisa', 'servicereports') . ": "
            . Html::convDate($start) . " " . __('a', 'servicereports') . " " . Html::convDate($end) . "</div>";
        echo "<div><strong>" . __('Valor monetário total faturado', 'servicereports') . ":</strong> " . self::money($total) . "</div>";
        echo "</div></div>";
    }

    // =====================================================================
    //  Utilitários
    // =====================================================================

    public static function money(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    /**
     * Segundos por extenso ("42 horas 0 minutos"), como no relatório original.
     * `use_days = false`: num extrato de serviços conta-se em horas, não em dias.
     */
    public static function duration(int $seconds): string
    {
        return Html::timestampToString(max(0, $seconds), false, false);
    }

    /** Segundos → HH:MM:SS. */
    public static function hms(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Renderiza um gráfico de barras horizontais simples (HTML/CSS, sem JS).
     *
     * @param array<int,array{label:string,value:float}> $data
     */
    public static function renderBarChart(array $data, string $title): void
    {
        echo "<div class='card h-100 shadow-sm'><div class='card-body'>";
        echo "<h3 class='h6 mb-3'>" . $title . "</h3>";
        if (empty($data)) {
            echo "<div class='text-muted text-center py-4'><i class='ti ti-alert-circle'></i> " . __('Sem dados para construir o gráfico.', 'servicereports') . "</div>";
        } else {
            $max = 0.0;
            foreach ($data as $d) {
                $max = max($max, $d['value']);
            }
            $max = $max > 0 ? $max : 1;
            foreach ($data as $d) {
                $pct = round($d['value'] / $max * 100);
                echo "<div class='mb-2'>";
                echo "<div class='d-flex justify-content-between' style='font-size:.8rem'>";
                echo "<span class='text-truncate' style='max-width:60%'>" . $d['label'] . "</span>";
                echo "<span class='text-muted'>" . self::money($d['value']) . "</span>";
                echo "</div>";
                echo "<div class='progress' style='height:10px'>";
                echo "<div class='progress-bar' role='progressbar' style='width:{$pct}%'></div>";
                echo "</div></div>";
            }
        }
        echo "</div></div>";
    }
}
