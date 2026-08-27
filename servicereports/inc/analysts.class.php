<?php
/**
 * Lógica do bloco "Analistas" (Desempenho de Analistas):
 *  - Técnicos: performance por técnico (horas de tarefas, chamados por tipo,
 *    satisfação, pontos).
 *  - Relatórios: Tarefas por Técnico (57), Deslocamentos (58), Horas fora de
 *    expediente (59), Entidade vs. Analistas (60).
 *
 * Jornada padrão de expediente: Seg–Sex 08:00–18:00 (ajustável).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsAnalysts
{
    /** Início/fim do expediente (horas do dia), dias úteis Seg(1)–Sex(5). */
    private const WORK_START = 8;
    private const WORK_END   = 18;

    public static function getMonthRange(): array
    {
        return [date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')];
    }

    public static function secToHms(int $sec): string
    {
        $sec = max(0, $sec);
        return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }

    /**
     * Técnicos com atividade (atribuídos ou com tarefas) no período.
     * Usado para montar os cartões de performance — o dropdown de filtro lista
     * **todos** os técnicos do GLPI (User::dropdown com o direito own_ticket).
     *
     * @return array<int,string> id => nome
     */
    public static function getTechnicians(string $start, string $end): array
    {
        global $DB;
        $s = $DB->escape($start);
        $e = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $sql = "SELECT DISTINCT u.id, u.name, u.realname, u.firstname
                FROM glpi_users u
                WHERE u.id IN (
                    SELECT tt.users_id_tech FROM glpi_tickettasks tt
                    INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tt.tickets_id AND glpi_tickets.is_deleted=0
                    WHERE tt.users_id_tech>0 AND tt.date BETWEEN '$s' AND '$e' $ent
                    UNION
                    SELECT tu.users_id FROM glpi_tickets_users tu
                    INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tu.tickets_id AND glpi_tickets.is_deleted=0
                    WHERE tu.type=" . CommonITILActor::ASSIGN . " AND tu.users_id>0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
                )
                ORDER BY u.realname, u.firstname, u.name";
        $out = [];
        foreach ($DB->request($sql) as $row) {
            $out[(int) $row['id']] = formatUserName($row['id'], $row['name'], $row['realname'], $row['firstname']);
        }
        return $out;
    }

    /**
     * **Todos** os técnicos do GLPI (usuários com o direito `own_ticket`, os que
     * podem ser atribuídos a chamados), nas entidades ativas — independente de
     * terem atividade no período filtrado.
     *
     * @return array<int,string> id => nome
     */
    public static function getAllTechnicians(): array
    {
        $out = [];
        // false = lista (não contagem); mesmo critério do campo "Atribuído a" do core.
        $iterator = User::getSqlSearchResult(false, 'own_ticket');
        foreach ($iterator as $row) {
            $out[(int) $row['id']] = formatUserName(
                $row['id'],
                $row['name'],
                $row['realname'] ?? '',
                $row['firstname'] ?? ''
            );
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Métricas de performance por técnico.
     *
     * @param int[] $techIds  filtra técnicos (vazio = todos com atividade)
     * @return array<int,array<string,mixed>>
     */
    public static function getPerformance(array $techIds, string $start, string $end): array
    {
        global $DB;
        $s = $DB->escape($start);
        $e = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        // Técnico escolhido no filtro aparece mesmo sem atividade no período
        // (o dropdown lista todos os técnicos do GLPI, não só os do período).
        if (!empty($techIds)) {
            $techs = [];
            foreach ($techIds as $tid) {
                $tid = (int) $tid;
                if ($tid > 0) {
                    $techs[$tid] = getUserName($tid);
                }
            }
        } else {
            $techs = self::getTechnicians($start, $end);
        }
        if (empty($techs)) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_keys($techs)));

        $perf = [];
        foreach ($techs as $id => $name) {
            $perf[$id] = [
                'name' => $name, 'worked' => 0, 'tickets' => 0, 'incidents' => 0,
                'requests' => 0, 'solved' => 0, 'satisfaction' => null,
            ];
        }

        // Horas trabalhadas (soma actiontime das tarefas)
        foreach ($DB->request("SELECT tt.users_id_tech tech, SUM(tt.actiontime) worked
             FROM glpi_tickettasks tt
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tt.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tt.users_id_tech IN ($ids) AND tt.date BETWEEN '$s' AND '$e' $ent
             GROUP BY tt.users_id_tech") as $r) {
            $perf[(int) $r['tech']]['worked'] = (int) $r['worked'];
        }

        // Chamados atribuídos por tipo
        foreach ($DB->request("SELECT tu.users_id tech, glpi_tickets.type, COUNT(DISTINCT glpi_tickets.id) cnt
             FROM glpi_tickets_users tu
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tu.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tu.type=" . CommonITILActor::ASSIGN . " AND tu.users_id IN ($ids)
                   AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY tu.users_id, glpi_tickets.type") as $r) {
            $tid = (int) $r['tech'];
            $perf[$tid]['tickets'] += (int) $r['cnt'];
            if ((int) $r['type'] === Ticket::INCIDENT_TYPE) {
                $perf[$tid]['incidents'] = (int) $r['cnt'];
            } elseif ((int) $r['type'] === Ticket::DEMAND_TYPE) {
                $perf[$tid]['requests'] = (int) $r['cnt'];
            }
        }

        // Pontos = chamados solucionados/fechados
        foreach ($DB->request("SELECT tu.users_id tech, COUNT(DISTINCT glpi_tickets.id) cnt
             FROM glpi_tickets_users tu
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tu.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tu.type=" . CommonITILActor::ASSIGN . " AND tu.users_id IN ($ids)
                   AND glpi_tickets.status IN (" . CommonITILObject::SOLVED . "," . CommonITILObject::CLOSED . ")
                   AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY tu.users_id") as $r) {
            $perf[(int) $r['tech']]['solved'] = (int) $r['cnt'];
        }

        // Satisfação média
        foreach ($DB->request("SELECT tu.users_id tech, AVG(ts.satisfaction) sat
             FROM glpi_tickets_users tu
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tu.tickets_id AND glpi_tickets.is_deleted=0
             INNER JOIN glpi_ticketsatisfactions ts ON ts.tickets_id=glpi_tickets.id AND ts.satisfaction IS NOT NULL
             WHERE tu.type=" . CommonITILActor::ASSIGN . " AND tu.users_id IN ($ids)
                   AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY tu.users_id") as $r) {
            $perf[(int) $r['tech']]['satisfaction'] = round(((float) $r['sat']) / 5 * 100);
        }

        return $perf;
    }

    /**
     * Relatório 57 — Tarefas por Técnico.
     *
     * Os totais (nº de tarefas / tempo) são sempre do período inteiro; `$limit`
     * pagina apenas as linhas exibidas (0 = todas, usado no CSV).
     *
     * @return array{rows:array<int,array<string,mixed>>,total_tasks:int,total_time:int}
     */
    public static function getTasksReport(string $start, string $end, int $techId = 0, int $ticketId = 0, int $limit = 0, int $offset = 0): array
    {
        global $DB;
        $s = $DB->escape($start);
        $e = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $extra = '';
        if ($techId > 0) {
            $extra .= ' AND tt.users_id_tech=' . (int) $techId;
        }
        if ($ticketId > 0) {
            $extra .= ' AND tt.tickets_id=' . (int) $ticketId;
        }

        // Totais do período completo (independem da paginação).
        $tot = $DB->request("SELECT COUNT(*) nb, COALESCE(SUM(tt.actiontime),0) total_time
             FROM glpi_tickettasks tt
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tt.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tt.users_id_tech>0 AND tt.date BETWEEN '$s' AND '$e' $ent $extra")->current();

        $page = '';
        if ($limit > 0) {
            $page = ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) $offset);
        }

        $rows = [];
        foreach ($DB->request("SELECT tt.tickets_id, tt.content, tt.begin, tt.end, tt.actiontime, tt.date AS task_date,
                    tt.taskcategories_id, tt.users_id AS author, tt.users_id_tech AS tech,
                    tt.groups_id_tech, glpi_tickets.entities_id, glpi_tickets.itilcategories_id AS ticket_cat, glpi_tickets.date_creation
             FROM glpi_tickettasks tt
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tt.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tt.users_id_tech>0 AND tt.date BETWEEN '$s' AND '$e' $ent $extra
             ORDER BY tt.date DESC$page") as $r) {
            $rows[] = $r;
        }

        return [
            'rows'        => $rows,
            'total_tasks' => (int) ($tot['nb'] ?? 0),
            'total_time'  => (int) ($tot['total_time'] ?? 0),
        ];
    }

    /**
     * Relatório 59 — Horas fora de expediente por técnico e chamado.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getOutOfHoursReport(string $start, string $end, int $techId = 0, int $ticketId = 0): array
    {
        global $DB;
        $s = $DB->escape($start);
        $e = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $extra = '';
        if ($techId > 0) {
            $extra .= ' AND tt.users_id_tech=' . (int) $techId;
        }
        if ($ticketId > 0) {
            $extra .= ' AND tt.tickets_id=' . (int) $ticketId;
        }

        // agrega por (técnico, chamado)
        $agg = [];
        foreach ($DB->request("SELECT tt.users_id_tech tech, tt.tickets_id, tt.begin, tt.end, tt.actiontime,
                    glpi_tickets.entities_id
             FROM glpi_tickettasks tt
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tt.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tt.users_id_tech>0 AND tt.date BETWEEN '$s' AND '$e' $ent $extra") as $r) {
            $key = $r['tech'] . '-' . $r['tickets_id'];
            if (!isset($agg[$key])) {
                $agg[$key] = ['tech' => (int) $r['tech'], 'tickets_id' => (int) $r['tickets_id'],
                    'entities_id' => (int) $r['entities_id'], 'total' => 0, 'outside' => 0];
            }
            $agg[$key]['total']   += (int) $r['actiontime'];
            $agg[$key]['outside'] += self::outsideWorkSeconds((string) $r['begin'], (string) $r['end']);
        }
        return array_values($agg);
    }

    /**
     * Entidades visíveis na sessão (colunas do relatório 60), ordenadas pela
     * árvore. O rótulo é o nome **curto** (só a folha), como no extrato; o
     * completename fica no `title` da coluna.
     *
     * @return array<int,array{name:string,completename:string}>
     */
    public static function getVisibleEntities(): array
    {
        global $DB;

        $ids = $_SESSION['glpiactiveentities'] ?? [];
        if (empty($ids)) {
            return [];
        }
        $in  = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($DB->request("SELECT id, name, completename FROM glpi_entities WHERE id IN ($in) ORDER BY completename") as $r) {
            $id = (int) $r['id'];
            $out[$id] = [
                'name'         => $id === 0 ? Dropdown::getDropdownName('glpi_entities', 0) : (string) $r['name'],
                'completename' => (string) ($r['completename'] ?: $r['name']),
            ];
        }
        return $out;
    }

    /**
     * Relatório 60 — Entidade vs. Analistas.
     *
     * Matriz analista × entidade com o tempo de tarefas, somado pela **mesma
     * regra do extrato financeiro** (ver `PluginServicereportsFinancial::getExtrato`):
     * o período recorta o chamado pela data de **fechamento** (`closedate`) e,
     * uma vez dentro, entram **todas** as tarefas dele — inclusive as lançadas
     * em meses anteriores. Chamado ainda aberto (ou apenas *Solucionado*, com
     * `closedate` NULL) não conta em período nenhum. É por isso que este
     * relatório **não** usa `tt.date` como os relatórios 57/59.
     *
     * Diferença para o extrato: aqui não há recorte por serviço gerenciado —
     * entram todos os chamados fechados no período (decisão da Instant, 2026-08-26).
     *
     * As colunas são **todas** as entidades visíveis na sessão (podem sair
     * zeradas); as linhas são os analistas com horas no período, ou apenas o
     * escolhido no filtro.
     *
     * @param int $techId 0 = todos os analistas com horas no período
     * @return array{entities:array<int,array{name:string,completename:string}>,
     *               rows:array<int,array{name:string,cells:array<int,int>,total:int}>,
     *               totals:array<int,int>, grand:int}
     */
    public static function getEntityAnalystMatrix(string $start, string $end, int $techId = 0): array
    {
        global $DB;

        $s     = $DB->escape($start);
        $e     = $DB->escape($end);
        $ent   = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $extra = $techId > 0 ? ' AND tt.users_id_tech=' . (int) $techId : '';

        $entities = self::getVisibleEntities();

        $cells = [];
        foreach ($DB->request(
            "SELECT tt.users_id_tech tech, glpi_tickets.entities_id ent, COALESCE(SUM(tt.actiontime),0) secs
             FROM glpi_tickettasks tt
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tt.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tt.users_id_tech>0 AND glpi_tickets.closedate BETWEEN '$s' AND '$e' $ent $extra
             GROUP BY tt.users_id_tech, glpi_tickets.entities_id"
        ) as $r) {
            $cells[(int) $r['tech']][(int) $r['ent']] = (int) $r['secs'];
        }

        // Analista escolhido no filtro aparece mesmo sem horas no período
        // (linha zerada), como nos cartões de performance.
        if ($techId > 0 && !isset($cells[$techId])) {
            $cells[$techId] = [];
        }

        $rows   = [];
        $totals = [];
        $grand  = 0;
        foreach ($cells as $tech => $byEntity) {
            $total = 0;
            foreach ($byEntity as $entId => $secs) {
                // Entidade fora da lista de colunas (não deve ocorrer com a
                // sessão consistente) entra na tabela para não sumir com horas.
                if (!isset($entities[$entId])) {
                    $entities[$entId] = [
                        'name'         => PluginServicereportsFinancial::entityName($entId),
                        'completename' => Dropdown::getDropdownName('glpi_entities', $entId),
                    ];
                }
                $total            += $secs;
                $totals[$entId]    = ($totals[$entId] ?? 0) + $secs;
            }
            $grand      += $total;
            $rows[$tech] = ['name' => getUserName($tech), 'cells' => $byEntity, 'total' => $total];
        }

        uasort($rows, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return ['entities' => $entities, 'rows' => $rows, 'totals' => $totals, 'grand' => $grand];
    }

    /**
     * Segundos de um intervalo [begin,end] fora do expediente (Seg–Sex 08–18).
     */
    public static function outsideWorkSeconds(string $begin, string $end): int
    {
        if (!$begin || !$end || $begin === '0000-00-00 00:00:00') {
            return 0;
        }
        $b = strtotime($begin);
        $e = strtotime($end);
        if ($e <= $b) {
            return 0;
        }
        $outside = 0;
        // percorre em blocos de dia
        $cursor = $b;
        while ($cursor < $e) {
            $dayStart = strtotime(date('Y-m-d 00:00:00', $cursor));
            $dayEnd   = $dayStart + 86400;
            $segEnd   = min($e, $dayEnd);
            $dow      = (int) date('N', $cursor); // 1=Seg .. 7=Dom
            if ($dow >= 6) {
                // fim de semana: tudo fora
                $outside += $segEnd - $cursor;
            } else {
                $workStart = $dayStart + self::WORK_START * 3600;
                $workEnd   = $dayStart + self::WORK_END * 3600;
                // interseção com expediente
                $inStart = max($cursor, $workStart);
                $inEnd   = min($segEnd, $workEnd);
                $inside  = max(0, $inEnd - $inStart);
                $outside += ($segEnd - $cursor) - $inside;
            }
            $cursor = $segEnd;
        }
        return (int) $outside;
    }
}
