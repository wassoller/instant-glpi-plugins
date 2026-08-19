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
 *   - Relatórios  → 3 relatórios:
 *       1  Extrato financeiro        (detalhamento por entidade/serviço)
 *       2  Faturamento financeiro    (resumo do total faturado no período)
 *       4  Fatura de serviços detalhada (documento de fatura imprimível)
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
        $ent = getEntitiesRestrictRequest('AND', $ms);
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

        $receitaPrevista = self::scalar("SELECT COALESCE(SUM(value),0) AS v FROM ($latest) l WHERE rn=1");
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
                FROM ($latest) l WHERE rn=1
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
     * e/ou por ativo coberto (glpi_items_tickets).
     *
     * @return int[]
     */
    private static function linkedTicketIds(int $sid, int $entity, int $catId): array
    {
        global $DB;
        $ids = [];

        $cats = self::categoryTreeIds($catId);
        if (!empty($cats)) {
            $res = $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['itilcategories_id' => $cats, 'entities_id' => $entity, 'is_deleted' => 0],
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
     * Chamados vinculados ao serviço dentro do período (por data do chamado).
     *
     * @param int[] $ticketIds
     * @return array<int,array{id:int,name:string,date:string,status:int,cat:int}>
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
        $res = $DB->doQuery(
            "SELECT id, name, date, status, itilcategories_id
             FROM `glpi_tickets`
             WHERE id IN ($in) AND is_deleted = 0 AND date BETWEEN '$s' AND '$e'
             ORDER BY date DESC"
        );
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = [
                'id'     => (int) $r['id'],
                'name'   => (string) $r['name'],
                'date'   => (string) $r['date'],
                'status' => (int) $r['status'],
                'cat'    => (int) $r['itilcategories_id'],
            ];
        }
        return $out;
    }

    /**
     * Tempo total de tarefas (segundos) dos chamados do serviço no período.
     * Filtra por tt.date (não tt.begin — a maioria das tarefas não é planejada).
     *
     * @param int[] $ticketIds
     */
    private static function taskTime(array $ticketIds, string $startDt, string $endDt): int
    {
        global $DB;
        if (empty($ticketIds)) {
            return 0;
        }
        $in = implode(',', array_map('intval', $ticketIds));
        $s  = $DB->escape($startDt);
        $e  = $DB->escape($endDt);
        $res = $DB->doQuery(
            "SELECT COALESCE(SUM(actiontime),0) AS t
             FROM `glpi_tickettasks`
             WHERE tickets_id IN ($in) AND date BETWEEN '$s' AND '$e'"
        );
        $row = $DB->fetchAssoc($res);
        return (int) ($row['t'] ?? 0);
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
     *   - tarefas  : tempo (Σ actiontime, por tt.date) × valor/hora ("hourly").
     *   - total    : soma dos componentes.
     *
     * @return array<int,array{name:string,summary:array<string,float>,services:array}>
     */
    public static function getExtrato(string $startDt, string $endDt): array
    {
        global $DB;

        $ent = getEntitiesRestrictRequest('AND', self::MS_TABLE);
        $res = $DB->doQuery(
            "SELECT id, name, entities_id, users_id, itilcategories_id
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

            $linked   = self::linkedTicketIds($sid, $entId, $catId);
            $tickets  = self::ticketsInPeriod($linked, $startDt, $endDt);
            $seconds  = self::taskTime($linked, $startDt, $endDt);
            $taskVal  = $hourly > 0 ? ($seconds / 3600.0) * $hourly : 0.0;

            $categoria = 0.0; // sem modelo de dados (ver docblock)
            $extras    = 0.0; // sem modelo de dados (ver docblock)
            $total     = $mensal + $ativos + $categoria + $extras + $taskVal;

            if (!isset($byEntity[$entId])) {
                $byEntity[$entId] = [
                    'name'     => Dropdown::getDropdownName('glpi_entities', $entId),
                    'summary'  => ['total' => 0.0, 'fixos' => 0.0, 'categorias' => 0.0, 'hora' => 0.0, 'extras' => 0.0, 'ativos' => 0.0],
                    'services' => [],
                ];
            }

            $byEntity[$entId]['services'][] = [
                'id'           => $sid,
                'name'         => (string) $svc['name'],
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
        }

        return $byEntity;
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
     * Relatório 1 — Extrato financeiro detalhado.
     *
     * @param array $extrato  saída de getExtrato()
     */
    public static function renderExtrato(array $extrato, string $exportCsvUrl, string $pdfUrl): void
    {
        echo "<div class='text-center mb-3'><h3 class='mb-0'>" . __('Extrato financeiro detalhado', 'servicereports') . "</h3></div>";

        echo "<div class='d-flex justify-content-end gap-2 mb-3'>";
        echo "<a href='" . Html::cleanInputText($exportCsvUrl) . "' class='btn btn-outline-success btn-sm'><i class='ti ti-file-spreadsheet me-1'></i>CSV</a>";
        echo "<a href='" . Html::cleanInputText($pdfUrl) . "' target='_blank' class='btn btn-outline-danger btn-sm'><i class='ti ti-file-type-pdf me-1'></i>PDF</a>";
        echo "</div>";

        if (empty($extrato)) {
            echo "<div class='alert alert-info'>" . __('Nenhum serviço encontrado para o período.', 'servicereports') . "</div>";
            return;
        }

        foreach ($extrato as $ent) {
            $s = $ent['summary'];
            echo "<div class='card shadow-sm mb-4'><div class='card-body'>";
            echo "<h4 class='mb-3'>" . __('Detalhamento financeiro da entidade', 'servicereports') . ": <span class='text-primary'>" . $ent['name'] . "</span></h4>";
            echo "<div class='mb-1'><strong>" . __('Valor monetário total', 'servicereports') . ":</strong> " . self::money($s['total']) . "</div>";
            echo "<div class='text-muted' style='font-size:.9rem'>";
            echo "<div>" . __('Somatório dos valores monetários fixos dos serviços contratados', 'servicereports') . ": " . self::money($s['fixos']) . "</div>";
            echo "<div>" . __('Somatório dos valores monetários das categorias dos serviços contratados', 'servicereports') . ": " . self::money($s['categorias']) . "</div>";
            echo "<div>" . __('Somatório dos valores monetários de hora dos serviços contratados', 'servicereports') . ": " . self::money($s['hora']) . "</div>";
            echo "<div>" . __('Somatório dos valores monetários extras relacionados a chamados', 'servicereports') . ": " . self::money($s['extras']) . "</div>";
            echo "<div>" . __('Somatório dos valores monetários dos ativos', 'servicereports') . ": " . self::money($s['ativos']) . "</div>";
            echo "</div>";

            foreach ($ent['services'] as $svc) {
                self::renderServiceBlock($svc);
            }
            echo "</div></div>";
        }
    }

    /** Bloco de um serviço dentro do extrato (também usado na fatura/print). */
    private static function renderServiceBlock(array $svc): void
    {
        global $CFG_GLPI;

        echo "<div class='border-top mt-3 pt-3'>";
        echo "<h5 class='mb-2'>" . __('Serviço', 'servicereports') . ": " . $svc['name'] . "</h5>";
        echo "<div class='row row-cols-1 row-cols-md-2 g-1' style='font-size:.9rem'>";
        self::kv(__('Valor monetário mensal', 'servicereports'), self::money($svc['mensal']));
        self::kv(__('Valor monetário de ativos', 'servicereports'), self::money($svc['ativos']));
        self::kv(__('Valor monetário por categoria de chamado', 'servicereports'), self::money($svc['categoria']));
        self::kv(__('Valor monetário extras relacionados a chamados', 'servicereports'), self::money($svc['extras']));
        self::kv(__('Tempo total de tarefas', 'servicereports'), self::hms($svc['task_seconds']));
        self::kv(__('Valor monetário total das tarefas', 'servicereports'), self::money($svc['task_value']));
        echo "</div>";
        echo "<div class='mt-1'><strong>" . __('Valor monetário total', 'servicereports') . ":</strong> " . self::money($svc['total']) . "</div>";

        // Ativos cobertos
        echo "<div class='mt-3'><strong>" . __('Detalhamento dos ativos cobertos pelo serviço', 'servicereports') . "</strong></div>";
        if (empty($svc['coveredassets'])) {
            echo "<div class='text-muted' style='font-size:.9rem'><i class='ti ti-alert-circle text-warning me-1'></i>" . __('Não há ativos cobertos pelo serviço', 'servicereports') . "</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm table-hover mb-0'>";
            echo "<thead><tr><th>" . __('Tipo', 'servicereports') . "</th><th>" . __('Ativo', 'servicereports') . "</th><th>" . __('Data de entrada em contrato', 'servicereports') . "</th></tr></thead><tbody>";
            foreach ($svc['coveredassets'] as $a) {
                echo "<tr><td>" . $a['typename'] . "</td><td>" . $a['name'] . "</td><td>" . ($a['entry_date'] ? Html::convDate($a['entry_date']) : '-') . "</td></tr>";
            }
            echo "</tbody></table></div>";
        }

        // Chamados vinculados
        echo "<div class='mt-3'><strong>" . __('Listagem dos chamados vinculados ao serviço', 'servicereports') . "</strong></div>";
        if (empty($svc['tickets'])) {
            echo "<div class='text-muted' style='font-size:.9rem'><i class='ti ti-alert-circle text-warning me-1'></i>" . __('Não há chamados vinculados ao serviço no período', 'servicereports') . "</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm table-hover mb-0'>";
            echo "<thead><tr><th>" . __('Chamado', 'servicereports') . "</th><th>" . __('Título', 'servicereports') . "</th><th>" . __('Categoria', 'servicereports') . "</th><th>" . __('Data', 'servicereports') . "</th><th>" . __('Status', 'servicereports') . "</th></tr></thead><tbody>";
            foreach ($svc['tickets'] as $t) {
                $url = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $t['id'];
                echo "<tr>";
                echo "<td><a href='" . Html::cleanInputText($url) . "'>" . $t['id'] . "</a></td>";
                echo "<td>" . $t['name'] . "</td>";
                echo "<td>" . ($t['cat'] ? Dropdown::getDropdownName('glpi_itilcategories', $t['cat']) : '-') . "</td>";
                echo "<td>" . Html::convDateTime($t['date']) . "</td>";
                echo "<td>" . Ticket::getStatus($t['status']) . "</td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div>";
    }

    private static function kv(string $k, string $v): void
    {
        echo "<div class='col'><span class='text-muted'>$k:</span> <strong>$v</strong></div>";
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

    /**
     * Relatório 4 — Fatura de serviços detalhada (documento imprimível).
     *
     * O vReports original renderiza um PDF por um gerador próprio; aqui
     * produzimos um documento de fatura em HTML (imprimível → "Salvar como PDF").
     */
    public static function renderFaturaDetalhada(array $extrato, string $start, string $end, string $printUrl = ''): void
    {
        echo "<div class='text-center mb-3'>";
        echo "<h3 class='mb-0'>" . __('Fatura de serviços detalhada', 'servicereports') . " ";
        $href = $printUrl !== '' ? Html::cleanInputText($printUrl) : 'javascript:window.print()';
        $tgt  = $printUrl !== '' ? " target='_blank'" : '';
        echo "<a href='" . $href . "'$tgt class='btn btn-link btn-sm p-0 align-baseline' title='" . __('Imprimir / Salvar PDF', 'servicereports') . "'><i class='ti ti-printer'></i></a>";
        echo "</h3></div>";

        echo "<div class='alert alert-secondary' style='font-size:.85rem'><i class='ti ti-info-circle me-1'></i>"
            . __('Documento imprimível (use Imprimir → Salvar como PDF). No vReports original a fatura era gerada por um engine PDF próprio.', 'servicereports')
            . "</div>";

        if (empty($extrato)) {
            echo "<div class='alert alert-info'>" . __('Nenhum serviço encontrado para o período.', 'servicereports') . "</div>";
            return;
        }

        foreach ($extrato as $ent) {
            echo "<div class='card shadow-sm mb-4'><div class='card-body'>";
            echo "<div class='d-flex justify-content-between align-items-start mb-3'>";
            echo "<div><div class='h4 mb-0'>" . __('Fatura de serviços', 'servicereports') . "</div>"
                . "<div class='text-muted'>" . $ent['name'] . "</div></div>";
            echo "<div class='text-end text-muted' style='font-size:.9rem'>"
                . __('Período', 'servicereports') . ": " . Html::convDate($start) . " – " . Html::convDate($end) . "</div>";
            echo "</div>";

            echo "<div class='table-responsive'><table class='table table-bordered'>";
            echo "<thead><tr><th>" . __('Serviço', 'servicereports') . "</th>"
                . "<th class='text-end'>" . __('Mensal', 'servicereports') . "</th>"
                . "<th class='text-end'>" . __('Ativos', 'servicereports') . "</th>"
                . "<th class='text-end'>" . __('Tarefas', 'servicereports') . "</th>"
                . "<th class='text-end'>" . __('Total', 'servicereports') . "</th></tr></thead><tbody>";
            foreach ($ent['services'] as $svc) {
                echo "<tr><td>" . $svc['name'] . "</td>"
                    . "<td class='text-end'>" . self::money($svc['mensal']) . "</td>"
                    . "<td class='text-end'>" . self::money($svc['ativos']) . "</td>"
                    . "<td class='text-end'>" . self::money($svc['task_value']) . "</td>"
                    . "<td class='text-end'>" . self::money($svc['total']) . "</td></tr>";
            }
            // Total da entidade = período inteiro (a listagem acima pode estar paginada).
            echo "</tbody><tfoot><tr><th>" . __('Total da entidade', 'servicereports')
                . " <span class='text-muted fw-normal' style='font-size:.8rem'>("
                . __('todos os serviços do período', 'servicereports') . ")</span></th>"
                . "<th colspan='3'></th><th class='text-end'>" . self::money($ent['summary']['total']) . "</th></tr></tfoot>";
            echo "</table></div>";
            echo "</div></div>";
        }
    }

    // =====================================================================
    //  Utilitários
    // =====================================================================

    public static function money(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
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
