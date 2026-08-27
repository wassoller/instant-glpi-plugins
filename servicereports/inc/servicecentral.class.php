<?php
/**
 * Lógica do bloco "Central de serviços":
 *  - Dashboard: KPIs de chamados do mês corrente (contagens via SQL) e
 *    deep-links para a busca de chamados do GLPI.
 *  - Relatórios: o "Relatório central de serviços" (7 seções: atendimento por
 *    dia, abertos × encerrados, top categorias, SLA e top requerentes).
 *
 * Definições derivadas do plugin original (docs/recon/02-vreports.md).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsServicecentral
{
    /** Status de chamado que contam como "em atendimento" (não solucionado/fechado). */
    private const OPEN_STATUS = [
        CommonITILObject::INCOMING,
        CommonITILObject::ASSIGNED,
        CommonITILObject::PLANNED,
        CommonITILObject::WAITING,
    ];

    /**
     * @return array{0:string,1:string} [início, fim] do mês corrente
     */
    public static function getMonthRange(): array
    {
        return [date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')];
    }

    private static function runCount(string $sql): int
    {
        global $DB;
        $res = $DB->doQuery($sql);
        $row = $DB->fetchAssoc($res);
        return (int) ($row['cpt'] ?? 0);
    }

    private static function ticketUrl(array $criteria): string
    {
        global $CFG_GLPI;
        $params = ['is_deleted=0', 'itemtype=Ticket', 'start=0'];
        foreach ($criteria as $i => $c) {
            $params[] = "criteria[$i][link]=AND";
            $params[] = "criteria[$i][field]=" . $c['field'];
            $params[] = "criteria[$i][searchtype]=" . $c['searchtype'];
            $params[] = "criteria[$i][value]=" . rawurlencode((string) $c['value']);
        }
        return $CFG_GLPI['root_doc'] . '/front/ticket.php?' . implode('&', $params);
    }

    // =====================================================================
    //  Relatório central de serviços (sub-aba Relatórios)
    // =====================================================================

    /**
     * Momento em que o chamado foi **assumido** (take into account), do ponto
     * de vista do SLA de atendimento.
     *
     * O GLPI 10 grava `takeintoaccountdate`, mas chamados antigos (ou migrados)
     * só têm o `takeintoaccount_delay_stat` em segundos — daí o COALESCE.
     */
    private const TAKEN_EXPR = "COALESCE(glpi_tickets.takeintoaccountdate,
        IF(glpi_tickets.takeintoaccount_delay_stat > 0,
           glpi_tickets.date + INTERVAL glpi_tickets.takeintoaccount_delay_stat SECOND, NULL))";

    /**
     * Chamado que **estourou o SLA de atendimento** (tempo até o analista
     * assumir): assumido depois do prazo, ou ainda não assumido com o prazo
     * já vencido.
     */
    private const LATE_TTO = "(glpi_tickets.time_to_own IS NOT NULL AND (
           (" . self::TAKEN_EXPR . " IS NOT NULL AND " . self::TAKEN_EXPR . " > glpi_tickets.time_to_own)
        OR (" . self::TAKEN_EXPR . " IS NULL AND NOW() > glpi_tickets.time_to_own)))";

    /**
     * Chamado que **estourou o SLA de solução**: solucionado depois do prazo.
     *
     * Comparação direta `solvedate > time_to_resolve`, igual à estatística
     * "solucionados com atraso" do core (`Stat::inter_solved_late`). Não some
     * o `sla_waiting_duration`: o GLPI **já empurra** o `time_to_resolve` pelo
     * tempo em que o chamado ficou Pendente (CommonITILObject, ao sair do
     * Pendente) — somar de novo contaria o mesmo tempo duas vezes.
     * Chamado sem SLA (`time_to_resolve` nulo) nunca é atraso.
     */
    private const LATE_TTR = "(glpi_tickets.time_to_resolve IS NOT NULL
        AND glpi_tickets.solvedate IS NOT NULL
        AND glpi_tickets.solvedate > glpi_tickets.time_to_resolve)";

    /** @return array<int,array<string,mixed>> */
    private static function rows(string $sql): array
    {
        global $DB;
        $res = $DB->doQuery($sql);
        $out = [];
        while ($row = $DB->fetchAssoc($res)) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Dias do intervalo, em ordem: ['2026-08-01' => '01/08', …].
     *
     * @return array<string,string>
     */
    public static function dayLabels(string $start, string $end): array
    {
        $out = [];
        $cur = strtotime(substr($start, 0, 10));
        $lim = strtotime(substr($end, 0, 10));
        // Trava de segurança: um filtro de anos viraria um SVG quilométrico.
        $guard = 0;
        while ($cur <= $lim && $guard++ < 800) {
            $out[date('Y-m-d', $cur)] = date('d/m', $cur);
            $cur = strtotime('+1 day', $cur);
        }
        return $out;
    }

    /**
     * Chamados **abertos** por dia (pela data de abertura).
     *
     * @return array<string,int> 'Y-m-d' => nº (todos os dias do intervalo)
     */
    public static function getOpenedByDay(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $out = array_fill_keys(array_keys(self::dayLabels($start, $end)), 0);
        foreach (self::rows(
            "SELECT DATE(glpi_tickets.date) d, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY d"
        ) as $r) {
            $out[(string) $r['d']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Chamados **encerrados** por dia — pela `solvedate`, que é a data em que
     * o chamado foi Solucionado. Chamado que já avançou para Fechado continua
     * contando (no GLPI, Fechado passou por Solucionado e guarda a data), por
     * isso o total de encerrados pode passar o de abertos no mesmo período.
     *
     * @return array<string,int>
     */
    public static function getSolvedByDay(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $out = array_fill_keys(array_keys(self::dayLabels($start, $end)), 0);
        foreach (self::rows(
            "SELECT DATE(glpi_tickets.solvedate) d, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.solvedate BETWEEN '$s' AND '$e' $ent
             GROUP BY d"
        ) as $r) {
            $out[(string) $r['d']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Não conformidade de SLA por dia, dos chamados **abertos** no período:
     * quantos estouraram o prazo de atendimento (assumir) e quantos o de
     * solução. Ver LATE_TTO / LATE_TTR.
     *
     * @return array<string,array{tto:int,ttr:int}>
     */
    public static function getSlaBreachByDay(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $out = [];
        foreach (array_keys(self::dayLabels($start, $end)) as $d) {
            $out[$d] = ['tto' => 0, 'ttr' => 0];
        }
        foreach (self::rows(
            "SELECT DATE(glpi_tickets.date) d,
                    SUM(CASE WHEN " . self::LATE_TTO . " THEN 1 ELSE 0 END) tto,
                    SUM(CASE WHEN " . self::LATE_TTR . " THEN 1 ELSE 0 END) ttr
             FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY d"
        ) as $r) {
            $out[(string) $r['d']] = ['tto' => (int) $r['tto'], 'ttr' => (int) $r['ttr']];
        }
        return $out;
    }

    /**
     * Nível de serviço do período: dos chamados abertos, quantos ficaram
     * dentro e quantos fora do prazo de **solução**. Sem SLA definido conta
     * como dentro do prazo (é o que fecha a conta com o total de abertos).
     *
     * @return array{total:int,dentro:int,fora:int}
     */
    public static function getSlaSummary(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $r = self::rows(
            "SELECT COUNT(*) total, SUM(CASE WHEN " . self::LATE_TTR . " THEN 1 ELSE 0 END) fora
             FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent"
        )[0] ?? [];

        $total = (int) ($r['total'] ?? 0);
        $fora  = (int) ($r['fora'] ?? 0);
        return ['total' => $total, 'dentro' => $total - $fora, 'fora' => $fora];
    }

    /**
     * Top categorias dos chamados abertos no período.
     *
     * @return array{rows:array<int,array{label:string,value:int,note:string}>,total:int}
     */
    public static function getTopCategories(string $start, string $end, int $limit = 7): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $all = self::rows(
            "SELECT glpi_tickets.itilcategories_id cat, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY cat ORDER BY n DESC"
        );

        $total = 0;
        foreach ($all as $r) {
            $total += (int) $r['n'];
        }

        $rows = [];
        foreach (array_slice($all, 0, $limit) as $r) {
            $id   = (int) $r['cat'];
            $name = $id > 0 ? Dropdown::getDropdownName('glpi_itilcategories', $id) : '';
            if ($id > 0 && trim(strip_tags($name)) === '') {
                // Categoria apagada (ou fora do escopo): sem isso a barra sairia
                // com o rótulo vazio e ninguém saberia do que se trata.
                $name = sprintf(__('Categoria #%d', 'servicereports'), $id);
            }
            $rows[] = [
                'label' => $id > 0 ? $name : __('Sem categoria', 'servicereports'),
                'value' => (int) $r['n'],
                'note'  => $total > 0 ? number_format((int) $r['n'] / $total * 100, 2, ',', '.') . '%' : '',
            ];
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Top **requerentes** dos chamados abertos no período — usuários do GLPI
     * **e** e-mails avulsos (quem abriu por e-mail sem cadastro entra pelo
     * `alternative_email`, que o GLPI grava com `users_id=0`).
     *
     * @return array{rows:array<int,array{label:string,value:int,note:string}>,total:int}
     */
    public static function getTopRequesters(string $start, string $end, int $limit = 10): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        // Requerente do chamado: ou um usuário do GLPI (`users_id`), ou apenas
        // um e-mail (`alternative_email`, com `users_id=0`) — é assim que fica
        // quem abriu chamado por e-mail sem ter cadastro. Os dois entram na
        // lista; o agrupamento usa o e-mail só quando não há usuário.
        $join = "INNER JOIN glpi_tickets_users tu ON tu.tickets_id=glpi_tickets.id
                    AND tu.type=" . CommonITILActor::REQUESTER . "
                    AND (tu.users_id > 0 OR (tu.alternative_email IS NOT NULL AND tu.alternative_email <> ''))";
        $where = "WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent";
        $key   = "IF(tu.users_id > 0, '', tu.alternative_email)";

        $all = self::rows(
            "SELECT tu.users_id uid, $key email, COUNT(DISTINCT glpi_tickets.id) n
             FROM glpi_tickets $join $where
             GROUP BY tu.users_id, $key ORDER BY n DESC LIMIT " . max(1, $limit)
        );

        $total = 0;
        foreach (self::rows(
            "SELECT COUNT(DISTINCT glpi_tickets.id) n FROM glpi_tickets $join $where"
        ) as $r) {
            $total = (int) $r['n'];
        }

        $rows = [];
        foreach ($all as $r) {
            $uid = (int) $r['uid'];
            $rows[] = [
                'label' => $uid > 0 ? getUserName($uid) : (string) $r['email'],
                'value' => (int) $r['n'],
                'note'  => '',
            ];
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Tudo o que o relatório central de serviços precisa, numa passada só —
     * a tela e o PDF consomem exatamente o mesmo array.
     */
    public static function getReport(string $start, string $end): array
    {
        $days     = self::dayLabels($start, $end);
        $opened   = self::getOpenedByDay($start, $end);
        $solved   = self::getSolvedByDay($start, $end);
        $breach   = self::getSlaBreachByDay($start, $end);

        $tto = [];
        $ttr = [];
        foreach (array_keys($days) as $d) {
            $tto[$d] = $breach[$d]['tto'] ?? 0;
            $ttr[$d] = $breach[$d]['ttr'] ?? 0;
        }

        return [
            'client'     => (string) ($_SESSION['glpiactive_entity_shortname'] ?? ''),
            'start'      => $start,
            'end'        => $end,
            'days'       => $days,
            'opened'     => $opened,
            'solved'     => $solved,
            'late_tto'   => $tto,
            'late_ttr'   => $ttr,
            'sla'        => self::getSlaSummary($start, $end),
            'categories' => self::getTopCategories($start, $end, 7),
            'requesters' => self::getTopRequesters($start, $end, 10),
            'total_open' => array_sum($opened),
        ];
    }

    /**
     * Retorna os cartões KPI com valor, descrição e ação.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getKpis(): array
    {
        global $DB, $CFG_GLPI;

        [$start, $end] = self::getMonthRange();
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $open = implode(',', self::OPEN_STATUS);
        $pending = CommonITILObject::WAITING;
        $month = "glpi_tickets.date_mod BETWEEN '$s' AND '$e'";

        // 1) Incidentes em atendimento
        $incidents = self::runCount(
            "SELECT COUNT(*) AS cpt FROM glpi_tickets
             WHERE is_deleted=0 AND type=" . Ticket::INCIDENT_TYPE . " AND status IN ($open) $ent"
        );

        // 2) Requisições em atendimento
        $requests = self::runCount(
            "SELECT COUNT(*) AS cpt FROM glpi_tickets
             WHERE is_deleted=0 AND type=" . Ticket::DEMAND_TYPE . " AND status IN ($open) $ent"
        );

        // 3) Aguardando retorno dos usuários (status Pendente, no mês)
        $pendingCount = self::runCount(
            "SELECT COUNT(*) AS cpt FROM glpi_tickets
             WHERE is_deleted=0 AND status=$pending AND $month $ent"
        );

        // 4) Analistas em atendimento (chamados abertos com técnico atribuído, no mês)
        $analysts = self::runCount(
            "SELECT COUNT(DISTINCT glpi_tickets.id) AS cpt FROM glpi_tickets
             INNER JOIN glpi_tickets_users ON glpi_tickets_users.tickets_id=glpi_tickets.id
                AND glpi_tickets_users.type=" . CommonITILActor::ASSIGN . " AND glpi_tickets_users.users_id>0
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.status IN ($open) AND $month $ent"
        );

        // 5) Usuários em atendimento (chamados abertos com requerente, no mês)
        $users = self::runCount(
            "SELECT COUNT(DISTINCT glpi_tickets.id) AS cpt FROM glpi_tickets
             INNER JOIN glpi_tickets_users ON glpi_tickets_users.tickets_id=glpi_tickets.id
                AND glpi_tickets_users.type=" . CommonITILActor::REQUESTER . " AND glpi_tickets_users.users_id>0
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.status IN ($open) AND $month $ent"
        );

        // 6) Satisfação dos usuários (% médio das pesquisas respondidas no mês)
        $satRes = $DB->doQuery(
            "SELECT AVG(ts.satisfaction) AS avgsat, COUNT(ts.id) AS cnt FROM glpi_tickets
             INNER JOIN glpi_ticketsatisfactions ts ON ts.tickets_id=glpi_tickets.id AND ts.satisfaction IS NOT NULL
             WHERE glpi_tickets.is_deleted=0 AND $month $ent"
        );
        $satRow  = $DB->fetchAssoc($satRes);
        $satPct  = ((int) ($satRow['cnt'] ?? 0)) > 0 ? round(((float) $satRow['avgsat']) / 5 * 100) : 0;

        // 7) Chamados envolvendo fornecedores (fornecedor atribuído, no mês)
        $suppliers = self::runCount(
            "SELECT COUNT(DISTINCT glpi_tickets.id) AS cpt FROM glpi_tickets
             INNER JOIN glpi_suppliers_tickets ON glpi_suppliers_tickets.tickets_id=glpi_tickets.id
                AND glpi_suppliers_tickets.suppliers_id>0
             WHERE glpi_tickets.is_deleted=0 AND $month $ent"
        );

        // 8) Artigos publicados na base de conhecimento no mês
        $kb = self::runCount(
            "SELECT COUNT(*) AS cpt FROM glpi_knowbaseitems WHERE date_creation BETWEEN '$s' AND '$e'"
        );

        // Deep-links (critérios idênticos ao plugin original)
        $urlPending = self::ticketUrl([
            ['field' => 12, 'searchtype' => 'equals', 'value' => $pending],
            ['field' => 19, 'searchtype' => 'morethan', 'value' => $start],
            ['field' => 19, 'searchtype' => 'lessthan', 'value' => $end],
        ]);
        $urlAnalysts = self::ticketUrl([
            ['field' => 12, 'searchtype' => 'equals', 'value' => 'notold'],
            ['field' => 19, 'searchtype' => 'morethan', 'value' => $start],
            ['field' => 19, 'searchtype' => 'lessthan', 'value' => $end],
            ['field' => 5, 'searchtype' => 'notequals', 'value' => 0],
        ]);
        $urlUsers = self::ticketUrl([
            ['field' => 12, 'searchtype' => 'equals', 'value' => 'notold'],
            ['field' => 19, 'searchtype' => 'morethan', 'value' => $start],
            ['field' => 19, 'searchtype' => 'lessthan', 'value' => $end],
            ['field' => 4, 'searchtype' => 'notequals', 'value' => 0],
        ]);
        $urlSat = self::ticketUrl([
            ['field' => 19, 'searchtype' => 'morethan', 'value' => $start],
            ['field' => 19, 'searchtype' => 'lessthan', 'value' => $end],
            ['field' => 62, 'searchtype' => 'notcontains', 'value' => 'NULL'],
        ]);
        $urlSuppliers = self::ticketUrl([
            ['field' => 19, 'searchtype' => 'morethan', 'value' => $start],
            ['field' => 19, 'searchtype' => 'lessthan', 'value' => $end],
            ['field' => 6, 'searchtype' => 'notequals', 'value' => 0],
        ]);
        $urlIncidents = self::ticketUrl([
            ['field' => 12, 'searchtype' => 'equals', 'value' => 'notold'],
            ['field' => 14, 'searchtype' => 'equals', 'value' => Ticket::INCIDENT_TYPE],
        ]);
        $urlRequests = self::ticketUrl([
            ['field' => 12, 'searchtype' => 'equals', 'value' => 'notold'],
            ['field' => 14, 'searchtype' => 'equals', 'value' => Ticket::DEMAND_TYPE],
        ]);
        $urlKb = $CFG_GLPI['root_doc'] . '/front/knowbaseitem.php';

        return [
            ['title' => __('Incidentes', 'servicereports'), 'value' => (string) $incidents, 'icon' => 'ti ti-alert-triangle',
                'desc' => __('Incidentes em atendimento na Central de Serviços.', 'servicereports'), 'btn' => __('Visualizar chamados', 'servicereports'), 'url' => $urlIncidents],
            ['title' => __('Requisições', 'servicereports'), 'value' => (string) $requests, 'icon' => 'ti ti-clipboard-list',
                'desc' => __('Requisições em atendimento na Central de Serviços.', 'servicereports'), 'btn' => __('Visualizar chamados', 'servicereports'), 'url' => $urlRequests],
            ['title' => __('Aguardando retorno dos usuários', 'servicereports'), 'value' => (string) $pendingCount, 'icon' => 'ti ti-clock-pause',
                'desc' => __('Chamados com o status pendente.', 'servicereports'), 'btn' => __('Visualizar chamados', 'servicereports'), 'url' => $urlPending],
            ['title' => __('Analistas em atendimento', 'servicereports'), 'value' => (string) $analysts, 'icon' => 'ti ti-headset',
                'desc' => __('Analistas atribuídos a chamados abertos.', 'servicereports'), 'btn' => __('Visualizar chamados', 'servicereports'), 'url' => $urlAnalysts],
            ['title' => __('Usuários em atendimento', 'servicereports'), 'value' => (string) $users, 'icon' => 'ti ti-users',
                'desc' => __('Chamados abertos com usuários como requerente.', 'servicereports'), 'btn' => __('Visualizar chamados', 'servicereports'), 'url' => $urlUsers],
            ['title' => __('Satisfação dos usuários', 'servicereports'), 'value' => $satPct . '%', 'icon' => 'ti ti-mood-smile',
                'desc' => __('Pesquisas de satisfação dos usuários no mês corrente.', 'servicereports'), 'btn' => __('Visualizar chamados', 'servicereports'), 'url' => $urlSat],
            ['title' => __('Chamados envolvendo fornecedores', 'servicereports'), 'value' => (string) $suppliers, 'icon' => 'ti ti-truck-delivery',
                'desc' => __('Chamados abertos atribuídos aos fornecedores.', 'servicereports'), 'btn' => __('Visualizar chamados', 'servicereports'), 'url' => $urlSuppliers],
            ['title' => __('Artigos publicados', 'servicereports'), 'value' => (string) $kb, 'icon' => 'ti ti-book',
                'desc' => __('Artigos publicados na base de conhecimento no mês corrente.', 'servicereports'), 'btn' => __('Ver base de conhecimento', 'servicereports'), 'url' => $urlKb],
        ];
    }
}
