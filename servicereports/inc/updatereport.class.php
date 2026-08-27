<?php
/**
 * "Relatório de atualização - Cliente" (Central de serviços › Relatórios).
 *
 * Reimplementação do deck que a Instant entrega ao cliente
 * ("Atualização - <Cliente> <data>.pptx"), com 7 seções:
 *   1. Capa / dados do relatório
 *   2. Relatório de atendimentos (legenda dos status)
 *   3. Chamados por mês (Incidente × Requisição)
 *   4. Chamados por tipo (tabela mês × tipo + rosca)
 *   5. Top 5 chamados por categoria
 *   6. Chamados por dia (Aberto × Fechado + linha de backlog)
 *   7. Chamados por horário (hora de abertura)
 *
 * Definições fechadas com a Instant em 2026-08-27:
 *   - "Aberto"  = `glpi_tickets.date` no período (igual ao relatório central).
 *   - "Fechado" = `glpi_tickets.closedate` no período — o status **Fechado**,
 *     não o Solucionado. Chamado só Solucionado não entra até fechar (mesma
 *     régua do extrato financeiro).
 *   - "Backlog" = fila acumulada: começa nos chamados que já estavam em aberto
 *     na véspera do período e, a cada dia, soma abertos e subtrai fechados.
 *     Com essa definição todo chamado fechado no período foi contado antes (no
 *     backlog inicial ou nos abertos), então a linha normalmente **não** desce
 *     de zero — o −9 do deck original saía de um backlog inicial calculado em
 *     outra base. Ainda assim o gráfico (tela e PDF) desenha eixo negativo:
 *     data inconsistente (`closedate` anterior à `date`, chamado movido de
 *     entidade) empurra a conta para baixo e o relatório tem de mostrar isso
 *     em vez de esconder.
 *   - A rosca por tipo tem só **Incidente e Requisição**: é o que o campo
 *     `glpi_tickets.type` do GLPI tem. O "Problema" do deck original é outro
 *     objeto (`glpi_problems`) e ficaria sempre zerado.
 *   - Os meses do gráfico "Chamados por mês" são **os do período filtrado**
 *     (o deck mostrava 13 fixos; aqui o filtro manda).
 *
 * A tela (front/servicecentral.php) e o PDF (updatepdf.class.php) consomem
 * exatamente o mesmo array de `getReport()`.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsUpdatereport
{
    /** Trava de segurança: um filtro de anos viraria um eixo quilométrico. */
    private const MAX_MONTHS = 60;

    /**
     * Abreviações dos meses em pt-BR, como no deck ("DEZ/25").
     *
     * Fixas de propósito: `strftime()` está obsoleto e o `IntlDateFormatter`
     * depende da extensão intl, que não é requisito do GLPI.
     */
    private const MONTH_ABBR = [
        1 => 'JAN', 2 => 'FEV', 3 => 'MAR', 4 => 'ABR', 5 => 'MAI', 6 => 'JUN',
        7 => 'JUL', 8 => 'AGO', 9 => 'SET', 10 => 'OUT', 11 => 'NOV', 12 => 'DEZ',
    ];

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
     * Meses do intervalo, em ordem: ['2025-12' => 'DEZ/25', …].
     *
     * @return array<string,string>
     */
    public static function monthLabels(string $start, string $end): array
    {
        $out   = [];
        $cur   = strtotime(date('Y-m-01', strtotime(substr($start, 0, 10))));
        $lim   = strtotime(date('Y-m-01', strtotime(substr($end, 0, 10))));
        $guard = 0;
        while ($cur <= $lim && $guard++ < self::MAX_MONTHS) {
            $out[date('Y-m', $cur)] = self::MONTH_ABBR[(int) date('n', $cur)] . '/' . date('y', $cur);
            $cur = strtotime('+1 month', $cur);
        }
        return $out;
    }

    /**
     * Chamados abertos por mês e tipo (Incidente / Requisição), pela data de
     * abertura.
     *
     * @return array<string,array{inc:int,req:int}> 'Y-m' => contagens
     */
    public static function getByMonthAndType(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $out = [];
        foreach (array_keys(self::monthLabels($start, $end)) as $m) {
            $out[$m] = ['inc' => 0, 'req' => 0];
        }
        foreach (self::rows(
            "SELECT DATE_FORMAT(glpi_tickets.date, '%Y-%m') m, glpi_tickets.type t, COUNT(*) n
             FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY m, t"
        ) as $r) {
            $m = (string) $r['m'];
            if (!isset($out[$m])) {
                continue;
            }
            $key = (int) $r['t'] === Ticket::INCIDENT_TYPE ? 'inc' : 'req';
            $out[$m][$key] += (int) $r['n'];
        }
        return $out;
    }

    /**
     * Chamados **fechados** por dia — pela `closedate` (status Fechado). Um
     * chamado apenas Solucionado ainda não conta; ver o cabeçalho da classe.
     *
     * @return array<string,int>
     */
    public static function getClosedByDay(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $out = array_fill_keys(
            array_keys(PluginServicereportsServicecentral::dayLabels($start, $end)),
            0
        );
        foreach (self::rows(
            "SELECT DATE(glpi_tickets.closedate) d, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.closedate BETWEEN '$s' AND '$e' $ent
             GROUP BY d"
        ) as $r) {
            $out[(string) $r['d']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Fila que o período **herdou**: chamados abertos antes do início e ainda
     * não fechados quando ele começa. É o ponto de partida da linha de backlog.
     */
    public static function getInitialBacklog(string $start): int
    {
        global $DB;
        $s   = $DB->escape($start);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $r = self::rows(
            "SELECT COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date < '$s'
               AND (glpi_tickets.closedate IS NULL OR glpi_tickets.closedate >= '$s') $ent"
        )[0] ?? [];
        return (int) ($r['n'] ?? 0);
    }

    /**
     * Chamados abertos por **hora do dia** (hora de abertura). Só as horas com
     * pelo menos um chamado entram, como no deck original — as 24 horas
     * deixariam metade do eixo vazia.
     *
     * @return array<int,array{label:string,value:int}>
     */
    public static function getByHour(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $out = [];
        foreach (self::rows(
            "SELECT HOUR(glpi_tickets.date) h, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY h ORDER BY h"
        ) as $r) {
            $out[] = [
                'label' => sprintf('%02d', (int) $r['h']),
                'value' => (int) $r['n'],
            ];
        }
        return $out;
    }

    /**
     * Legenda dos status usada na 2ª seção — o texto do deck, palavra por
     * palavra. Fica aqui (e não no front) porque a tela e o PDF imprimem a
     * mesma lista.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function statusGlossary(): array
    {
        return [
            [
                __('Atribuído', 'servicereports'),
                __('Chamado assumido pelo técnico.', 'servicereports'),
            ],
            [
                __('Pendente', 'servicereports'),
                __('Status que sinaliza a dependência de terceiros, equipamentos ou algo que não esteja '
                 . 'relacionado com o usuário ou o técnico diretamente.', 'servicereports'),
            ],
            [
                __('Solucionado', 'servicereports'),
                __('O chamado foi solucionado pelo técnico mas ainda não foi aprovado ou recusado pelo '
                 . 'usuário.', 'servicereports'),
            ],
            [
                __('Fechado', 'servicereports'),
                __('O chamado foi aprovado pelo usuário ou foi fechado pelo sistema após um período sem '
                 . 'retorno do usuário (para aprovação ou recusa do chamado).', 'servicereports'),
            ],
        ];
    }

    /**
     * Tudo o que o relatório de atualização precisa, numa passada só.
     */
    public static function getReport(string $start, string $end): array
    {
        $days   = PluginServicereportsServicecentral::dayLabels($start, $end);
        $opened = PluginServicereportsServicecentral::getOpenedByDay($start, $end);
        $closed = self::getClosedByDay($start, $end);

        // Backlog: fila herdada + (abertos − fechados) acumulado dia a dia.
        $backlog = [];
        $running = self::getInitialBacklog($start);
        $initial = $running;
        foreach (array_keys($days) as $d) {
            $running += ($opened[$d] ?? 0) - ($closed[$d] ?? 0);
            $backlog[$d] = $running;
        }

        $months  = self::monthLabels($start, $end);
        $byMonth = self::getByMonthAndType($start, $end);

        $inc = 0;
        $req = 0;
        foreach ($byMonth as $m) {
            $inc += $m['inc'];
            $req += $m['req'];
        }

        return [
            'client'     => (string) ($_SESSION['glpiactive_entity_shortname'] ?? ''),
            'start'      => $start,
            'end'        => $end,
            'statuses'   => self::statusGlossary(),
            'months'     => $months,
            'by_month'   => $byMonth,
            'types'      => ['inc' => $inc, 'req' => $req],
            'categories' => PluginServicereportsServicecentral::getTopCategories($start, $end, 5),
            'days'       => $days,
            'opened'     => $opened,
            'closed'     => $closed,
            'backlog'    => $backlog,
            'backlog_initial' => $initial,
            'hours'      => self::getByHour($start, $end),
            'total_open' => array_sum($opened),
            'total_closed' => array_sum($closed),
        ];
    }
}
