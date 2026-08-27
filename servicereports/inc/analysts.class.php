<?php
/**
 * Lógica do bloco "Analistas" (Desempenho de Analistas):
 *  - Técnicos: performance por técnico (horas de tarefas, chamados por tipo,
 *    satisfação, pontos).
 *  - Relatórios: Tarefas por Técnico (57), Deslocamentos (58), Horas fora de
 *    expediente (59), Entidade vs. Analistas (60), Chamados por Status e
 *    Técnico (61).
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
     * `is_leaf` diz se a entidade **não tem filhas**. Na árvore da Instant os
     * nós intermediários ("Standard", "Premium", …) são níveis de agrupamento,
     * não clientes — o relatório 60 os esconde quando estão zerados (2026-08-26).
     * A checagem de pai olha a tabela inteira, não só as entidades visíveis:
     * uma entidade com filha fora do escopo da sessão continua sendo contêiner.
     *
     * @return array<int,array{name:string,completename:string,is_leaf:bool}>
     */
    public static function getVisibleEntities(): array
    {
        global $DB;

        $ids = $_SESSION['glpiactiveentities'] ?? [];
        if (empty($ids)) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));

        $parents = [];
        foreach ($DB->request("SELECT DISTINCT entities_id FROM glpi_entities WHERE entities_id IS NOT NULL") as $r) {
            $parents[(int) $r['entities_id']] = true;
        }

        $out = [];
        foreach ($DB->request("SELECT id, name, completename FROM glpi_entities WHERE id IN ($in) ORDER BY completename") as $r) {
            $id = (int) $r['id'];
            $out[$id] = [
                'name'         => $id === 0 ? Dropdown::getDropdownName('glpi_entities', 0) : (string) $r['name'],
                'completename' => (string) ($r['completename'] ?: $r['name']),
                'is_leaf'      => !isset($parents[$id]),
            ];
        }
        return $out;
    }

    /**
     * Relatório 60 — Entidade vs. Analistas.
     *
     * Matriz analista × entidade com o tempo de tarefas. O período recorta a
     * **tarefa** pela sua própria data (`tt.date`), como nos relatórios 57/59:
     * entram **todas** as tarefas lançadas no intervalo, esteja o chamado
     * fechado ou ainda em aberto (decisão da Instant, 2026-08-27 — antes o
     * recorte era pela `closedate` do chamado, na regra do extrato financeiro,
     * e as tarefas de chamados abertos ficavam de fora).
     *
     * Por isso o número **não** é o faturável do Extrato: aqui a pergunta é
     * "quanto de tarefa foi lançado no período", não "o que fechou no período".
     * Também não há recorte por serviço gerenciado.
     *
     * As colunas são as entidades visíveis na sessão que são **folhas** da
     * árvore (podem sair zeradas); as linhas são os analistas com horas no
     * período, ou apenas o escolhido no filtro. Os nós **intermediários**
     * ("Standard", "Premium", a própria raiz) são níveis de agrupamento e não
     * clientes: saem da tabela — **a menos que tenham horas no período**, e aí
     * a coluna fica, para que nenhuma hora suma e os totais continuem batendo.
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
             WHERE tt.users_id_tech>0 AND tt.date BETWEEN '$s' AND '$e' $ent $extra
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
                        'is_leaf'      => true,
                    ];
                }
                $total            += $secs;
                $totals[$entId]    = ($totals[$entId] ?? 0) + $secs;
            }
            $grand      += $total;
            $rows[$tech] = ['name' => getUserName($tech), 'cells' => $byEntity, 'total' => $total];
        }

        uasort($rows, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        // Esconde os nós de agrupamento zerados (ver docblock). Contêiner com
        // horas fica: os chamados são dele, e sumir com a coluna quebraria a
        // conferência entre as linhas, o rodapé e o total do período.
        $entities = array_filter(
            $entities,
            static fn ($e, $id) => $e['is_leaf'] || ($totals[$id] ?? 0) > 0,
            ARRAY_FILTER_USE_BOTH
        );

        return ['entities' => $entities, 'rows' => $rows, 'totals' => $totals, 'grand' => $grand];
    }

    // =====================================================================
    //  Relatório 61 — Chamados por Status e Técnico
    // =====================================================================

    /**
     * Ordem dos status na pilha do gráfico (de **baixo para cima**) e nas
     * colunas da tabela. A legenda sai na ordem inversa (Fechado primeiro),
     * como no relatório original da Verdana.
     */
    public const STATUS_ORDER = [
        CommonITILObject::INCOMING,   // Novo
        CommonITILObject::ASSIGNED,   // Em atendimento (atribuído)
        CommonITILObject::PLANNED,    // Em atendimento (planejado)
        CommonITILObject::WAITING,    // Pendente
        CommonITILObject::SOLVED,     // Solucionado
        CommonITILObject::CLOSED,     // Fechado
    ];

    /** Cor de cada status — a mesma paleta na tela (SVG) e no PDF (TCPDF). */
    public const STATUS_COLORS = [
        CommonITILObject::INCOMING => '#f7a35c',
        CommonITILObject::ASSIGNED => '#1f6fb5',
        CommonITILObject::PLANNED  => '#1e8a3e',
        CommonITILObject::WAITING  => '#8ec9ee',
        CommonITILObject::SOLVED   => '#5ac26f',
        CommonITILObject::CLOSED   => '#f2712a',
    ];

    /**
     * Rótulos dos seis status, na ordem da pilha. Vêm do core
     * (`Ticket::getAllStatusArray()`), então acompanham o idioma do GLPI.
     *
     * @return array<int,string>
     */
    public static function statusLabels(): array
    {
        $all = Ticket::getAllStatusArray();
        $out = [];
        foreach (self::STATUS_ORDER as $st) {
            $out[$st] = (string) ($all[$st] ?? $st);
        }
        return $out;
    }

    /** '#rrggbb' → [r,g,b] (o TCPDF pede os componentes separados). */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * Relatório 61 — Chamados por Status e Técnico.
     *
     * Conta **chamados** (não tarefas): o vínculo com o técnico é o ator
     * *Atribuído* (`glpi_tickets_users` tipo ASSIGN), mesma regra do card
     * nativo do GLPI de onde veio o modelo. Chamado com dois técnicos
     * atribuídos conta 1 para **cada um** — por isso a soma das barras pode
     * passar do número de chamados do período.
     *
     * O período recorta pela **data de abertura** (`glpi_tickets.date`) e o
     * status é o **atual**: a pergunta é "dos chamados abertos no período, em
     * que status estão e com quem". Não confunda com os relatórios 57/59/60,
     * que recortam pela data da *tarefa*.
     *
     * @param int $techId 0 = todos os técnicos com chamados no período
     * @return array{statuses:array<int,string>,
     *               rows:array<int,array{id:int,name:string,counts:array<int,int>,total:int}>,
     *               totals:array<int,int>, grand:int, max:int}
     */
    public static function getStatusByTechnician(string $start, string $end, int $techId = 0): array
    {
        global $DB;

        $s     = $DB->escape($start);
        $e     = $DB->escape($end);
        $ent   = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $extra = $techId > 0 ? ' AND tu.users_id=' . (int) $techId : '';
        $in    = implode(',', self::STATUS_ORDER);

        $counts = [];
        foreach ($DB->request(
            "SELECT tu.users_id tech, glpi_tickets.status, COUNT(DISTINCT glpi_tickets.id) cnt
             FROM glpi_tickets_users tu
             INNER JOIN glpi_tickets glpi_tickets ON glpi_tickets.id=tu.tickets_id AND glpi_tickets.is_deleted=0
             WHERE tu.type=" . CommonITILActor::ASSIGN . " AND tu.users_id>0
                   AND glpi_tickets.status IN ($in)
                   AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent $extra
             GROUP BY tu.users_id, glpi_tickets.status"
        ) as $r) {
            $counts[(int) $r['tech']][(int) $r['status']] = (int) $r['cnt'];
        }

        // Técnico escolhido no filtro aparece mesmo sem chamados no período
        // (barra zerada), como nos cartões de performance.
        if ($techId > 0 && !isset($counts[$techId])) {
            $counts[$techId] = [];
        }

        $rows   = [];
        $totals = array_fill_keys(self::STATUS_ORDER, 0);
        $grand  = 0;
        $max    = 0;
        foreach ($counts as $tech => $byStatus) {
            $total = 0;
            foreach (self::STATUS_ORDER as $st) {
                $n              = (int) ($byStatus[$st] ?? 0);
                $total         += $n;
                $totals[$st]   += $n;
            }
            $grand      += $total;
            $max         = max($max, $total);
            $rows[$tech] = ['id' => (int) $tech, 'name' => getUserName($tech), 'counts' => $byStatus, 'total' => $total];
        }
        uasort($rows, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return [
            'statuses' => self::statusLabels(),
            'rows'     => array_values($rows),
            'totals'   => $totals,
            'grand'    => $grand,
            'max'      => $max,
        ];
    }

    /**
     * Escala "redonda" para o eixo Y: devolve o topo e o passo das linhas de
     * grade (ex.: máximo 64 → topo 70, passo 10).
     *
     * @return array{0:int,1:int} [topo, passo]
     */
    public static function niceScale(int $value, int $ticks = 7): array
    {
        if ($value <= 0) {
            return [$ticks, 1];
        }
        $rough = $value / max(1, $ticks);
        $mag   = 10 ** (int) max(0, floor(log10(max($rough, 1))));
        $step  = 10 * $mag;
        foreach ([1, 2, 5, 10] as $m) {
            if ($rough <= $m * $mag) {
                $step = $m * $mag;
                break;
            }
        }
        $step = (int) max(1, $step);
        $top  = (int) (ceil($value / $step) * $step);
        // Uma folga acima da maior barra: sem ela a barra encosta no topo e o
        // rótulo com o total fica por cima da grade (ou da legenda).
        if ($top <= $value) {
            $top += $step;
        }
        return [$top, $step];
    }

    /**
     * Gráfico de barras empilhadas (SVG gerado no PHP — sem biblioteca JS).
     *
     * Cada segmento carrega os números em `data-*`; um tooltip minúsculo em JS
     * (emitido junto) mostra técnico/status/quantidade ao passar o mouse.
     * O SVG rola na horizontal quando há muitos técnicos.
     *
     * @param array $data saída de getStatusByTechnician()
     */
    public static function renderStatusChart(array $data): void
    {
        $rows = $data['rows'];
        if (empty($rows)) {
            echo "<div class='alert alert-info'>" . __('Nenhum chamado encontrado no período.', 'servicereports') . "</div>";
            return;
        }

        $labels = $data['statuses'];
        [$top, $step] = self::niceScale((int) $data['max']);

        // Geometria (px). O rótulo do técnico sai girado -32°, ancorado no fim,
        // por isso ele cresce para a **esquerda** e para baixo — daí a margem
        // esquerda folgada e o corte do nome em 22 caracteres (o nome inteiro
        // fica no tooltip e na tabela).
        $barW  = 46;
        $slot  = 78;
        $padL  = 76;
        $padR  = 24;
        $padT  = 22;
        $padB  = 104;
        $plotH = 360;
        $plotW = max(count($rows) * $slot, 260);
        $w     = $padL + $plotW + $padR;
        $h     = $padT + $plotH + $padB;
        $base  = $padT + $plotH;

        // Legenda: ordem inversa da pilha (Fechado primeiro), como no original.
        echo "<div class='sr-cst-legend'>";
        foreach (array_reverse(self::STATUS_ORDER) as $st) {
            echo "<span class='sr-cst-key'><i style='background:" . self::STATUS_COLORS[$st] . "'></i>"
                . $labels[$st] . "</span>";
        }
        echo "</div>";

        echo "<div class='sr-cst-wrap'>";
        echo "<svg class='sr-cst' width='$w' height='$h' viewBox='0 0 $w $h' role='img' "
            . "aria-label='" . __('Chamados por status e técnico', 'servicereports') . "'>";

        // Grade + eixo Y.
        for ($v = 0; $v <= $top; $v += $step) {
            $y = round($base - ($v / $top) * $plotH, 1);
            echo "<line class='sr-cst-grid' x1='$padL' y1='$y' x2='" . ($padL + $plotW) . "' y2='$y'/>";
            echo "<text class='sr-cst-axis' x='" . ($padL - 8) . "' y='" . ($y + 4) . "' text-anchor='end'>$v</text>";
        }
        echo "<line class='sr-cst-base' x1='$padL' y1='$base' x2='" . ($padL + $plotW) . "' y2='$base'/>";

        // Barras.
        $i = 0;
        foreach ($rows as $row) {
            $x    = $padL + $i * $slot + (int) (($slot - $barW) / 2);
            $y    = $base;
            // Nome sem entidades HTML: o GLPI devolve texto escapado e o
            // tooltip lê o atributo já decodificado pelo DOM.
            $plain = html_entity_decode((string) $row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach (self::STATUS_ORDER as $st) {
                $n = (int) ($row['counts'][$st] ?? 0);
                if ($n <= 0) {
                    continue;
                }
                $segH = ($n / $top) * $plotH;
                $y   -= $segH;
                $pct  = $row['total'] > 0 ? round($n / $row['total'] * 100) : 0;
                echo "<rect class='sr-cst-seg' x='$x' y='" . round($y, 2) . "' width='$barW' height='" . round($segH, 2) . "'"
                    . " fill='" . self::STATUS_COLORS[$st] . "'"
                    . " data-tech='" . Html::cleanInputText($plain) . "'"
                    . " data-status='" . Html::cleanInputText(html_entity_decode($labels[$st], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . "'"
                    . " data-n='$n' data-pct='$pct' data-total='" . (int) $row['total'] . "'></rect>";
            }
            // Total acima da barra.
            if ($row['total'] > 0) {
                echo "<text class='sr-cst-total' x='" . ($x + $barW / 2) . "' y='" . round($y - 6, 1) . "' text-anchor='middle'>"
                    . (int) $row['total'] . "</text>";
            }
            // Nome do técnico, girado.
            $short = mb_strlen($plain) > 22 ? mb_substr($plain, 0, 21) . '…' : $plain;
            $lx    = $x + $barW / 2;
            $ly    = $base + 14;
            echo "<text class='sr-cst-name' x='$lx' y='$ly' text-anchor='end' transform='rotate(-32 $lx $ly)'>"
                . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . "</text>";
            $i++;
        }

        echo "</svg></div>";
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
