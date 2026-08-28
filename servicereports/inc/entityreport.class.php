<?php
/**
 * Relatório "Chamados por entidade" (Central de serviços › Relatórios, id 5).
 *
 * É o **mesmo gráfico do relatório 61** (barras empilhadas por status, com o
 * total em cima da barra) com **entidade** no eixo X em vez de técnico — o
 * modelo que a Instant mandou em 28/08. Por isso reaproveita, da classe
 * `PluginServicereportsAnalysts`, a ordem e as cores dos status
 * (`STATUS_ORDER`/`STATUS_COLORS`), os rótulos (`statusLabels()`) e o
 * desenho do SVG (`renderStackedChart()`): mexeu na aparência de um, o outro
 * acompanha de graça.
 *
 * Definições (fechadas com a Instant em 28/08):
 *  - **Todos os chamados, em qualquer status** — não há filtro de status; a
 *    pilha é só a quebra visual.
 *  - **Entidade do chamado** (`glpi_tickets.entities_id`), pelo **período de
 *    abertura** (`glpi_tickets.date`), como no 61 e no "Chamados por grupo".
 *  - **Sem soma na árvore**: o chamado conta **uma vez**, na entidade em que
 *    foi aberto — "Instant > Standard > Uniletra" não soma em "Standard".
 *    Assim a soma das barras fecha com o total de chamados do período.
 *  - Entram **só as entidades com chamado** no período (não é a lista de
 *    entidades visíveis, como no relatório 60), ordenadas pelo **nome
 *    completo**, para as irmãs saírem juntas no eixo — não pelo tamanho da
 *    barra, como no modelo da Instant.
 *  - O eixo mostra o nome **curto** da entidade (o completename não cabe e
 *    sairia cortado); a tabela mostra o completo.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsEntityreport
{
    /** Nome do relatório — seletor, título da tela, PDF e CSV. */
    public static function title(): string
    {
        return __('Chamados por entidade', 'servicereports');
    }

    /** Texto de ajuda — o mesmo na tela e no PDF. */
    public static function hint(): string
    {
        return __('Todos os chamados abertos no período, em QUALQUER status, pela entidade do chamado. '
                . 'Cada chamado conta uma vez, na entidade em que foi aberto: chamado de entidade filha NÃO '
                . 'soma na entidade-pai, então a soma das barras é o total de chamados do período. '
                . 'Só entidades com pelo menos um chamado aparecem no eixo.', 'servicereports');
    }

    /** @return array<int,array<string,mixed>> */
    private static function rows(string $sql): array
    {
        global $DB;
        $out = [];
        $res = $DB->doQuery($sql);
        while ($row = $DB->fetchAssoc($res)) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Chamados por entidade e status, no formato que o
     * `PluginServicereportsAnalysts::renderStackedChart()` consome.
     *
     * @return array{keys:array<int,int>, legend:array<int,int>, labels:array<int,string>,
     *               colors:array<int,string>,
     *               rows:array<int,array{id:int,name:string,fullname:string,counts:array<int,int>,total:int}>,
     *               totals:array<int,int>, grand:int, max:int,
     *               client:string, start:string, end:string, entities:int}
     */
    public static function getReport(string $start, string $end): array
    {
        global $DB;

        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        // Sem filtro de status: o relatório é "todos os chamados".
        $data = self::rows(
            "SELECT glpi_tickets.entities_id ent, glpi_tickets.status st, COUNT(*) n
             FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY glpi_tickets.entities_id, glpi_tickets.status"
        );

        $counts = [];
        foreach ($data as $r) {
            $counts[(int) $r['ent']][(int) $r['st']] = (int) $r['n'];
        }

        // Nomes numa consulta só (o `Dropdown::getDropdownName` por linha faria
        // uma consulta por entidade e só devolve o completename).
        $names = [];
        if (!empty($counts)) {
            $ids = implode(',', array_map('intval', array_keys($counts)));
            foreach (self::rows("SELECT id, name, completename FROM glpi_entities WHERE id IN ($ids)") as $r) {
                $names[(int) $r['id']] = ['name' => (string) $r['name'], 'full' => (string) $r['completename']];
            }
        }

        $keys   = PluginServicereportsAnalysts::STATUS_ORDER;
        $labels = PluginServicereportsAnalysts::statusLabels();

        $rows   = [];
        $totals = array_fill_keys($keys, 0);
        $grand  = 0;
        $max    = 0;
        foreach ($counts as $entId => $byStatus) {
            $total = 0;
            foreach ($keys as $k) {
                $n           = (int) ($byStatus[$k] ?? 0);
                $total      += $n;
                $totals[$k] += $n;
            }
            $grand += $total;
            $max    = max($max, $total);

            // Entidade apagada (ou fora do escopo): sem isso a barra sairia sem
            // rótulo e ninguém saberia de que cliente se trata.
            $full  = $names[$entId]['full'] ?? '';
            $short = $names[$entId]['name'] ?? '';
            if (trim($full) === '') {
                $full = $short !== '' ? $short : sprintf(__('Entidade #%d', 'servicereports'), $entId);
            }
            if (trim($short) === '') {
                $short = $full;
            }

            $rows[$entId] = [
                'id'       => (int) $entId,
                'name'     => $short,
                'fullname' => $full,
                'counts'   => $byStatus,
                'total'    => $total,
            ];
        }
        uasort($rows, static fn ($a, $b) => strnatcasecmp($a['fullname'], $b['fullname']));

        return [
            'keys'     => $keys,
            'legend'   => array_reverse($keys),
            'labels'   => $labels,
            'colors'   => PluginServicereportsAnalysts::STATUS_COLORS,
            'rows'     => array_values($rows),
            'totals'   => $totals,
            'grand'    => $grand,
            'max'      => $max,
            'client'   => (string) ($_SESSION['glpiactive_entity_shortname'] ?? ''),
            'start'    => $start,
            'end'      => $end,
            'entities' => count($rows),
        ];
    }
}
