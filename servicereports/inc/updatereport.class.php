<?php
/**
 * "Relatório de atualização - Cliente" (Central de serviços › Relatórios).
 *
 * Reimplementação do deck que a Instant entrega ao cliente
 * ("Atualização - <Cliente> <data>.pptx"), em **duas variantes** que só diferem
 * na granularidade dos dois gráficos de série temporal:
 *
 *   - **ANUAL**  (`GRAIN_MONTH`) — as colunas são meses. É o deck original.
 *   - **MENSAL** (`GRAIN_DAY`)   — as mesmas métricas diluídas em dias.
 *
 * Uma classe só serve as duas: o "bucket" é o mês (`2025-12` → `DEZ/25`) ou o
 * dia (`2025-12-01` → `01/12`), e toda consulta agrupa pela expressão que
 * `bucketExpr()` devolve. Fora o eixo, nada muda entre elas.
 *
 * As 7 seções, na ordem dos slides:
 *   1. Capa / dados do relatório
 *   2. Relatório de atendimentos (legenda dos status + total por status)
 *   3. Chamados por mês/dia (Incidente × Requisição)
 *   4. Chamados por tipo (tabela do bucket × tipo + rosca)
 *   5. Top 5 chamados por categoria
 *   6. Abertos × Fechados por mês/dia
 *   7. Chamados por horário (hora de abertura)
 *
 * Definições fechadas com a Instant em 2026-08-27:
 *   - "Aberto"  = `glpi_tickets.date` no período (igual ao relatório central).
 *   - "Fechado" = `glpi_tickets.closedate` no período — o status **Fechado**,
 *     não o Solucionado. Chamado só Solucionado não entra até fechar (mesma
 *     régua do extrato financeiro).
 *   - O deck traz uma **linha de backlog** por cima das barras de abertos ×
 *     fechados; ela foi **retirada** por confundir a leitura do gráfico. Se um
 *     dia voltar: era a fila acumulada a partir dos chamados abertos antes do
 *     período e ainda não fechados na véspera.
 *   - A tabela de status da 2ª seção conta os chamados **abertos no período**
 *     pelo **status atual** de cada um, e o total é o mesmo "chamados abertos"
 *     da capa.
 *   - A rosca por tipo tem só **Incidente e Requisição**: é o que o campo
 *     `glpi_tickets.type` do GLPI tem. O "Problema" do deck original é outro
 *     objeto (`glpi_problems`) e ficaria sempre zerado.
 *
 * A tela (front/servicecentral.php) e o PDF (updatepdf.class.php) consomem
 * exatamente o mesmo array de `getReport()`.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsUpdatereport
{
    /** Granularidade das séries temporais. */
    public const GRAIN_MONTH = 'month';
    public const GRAIN_DAY   = 'day';

    /**
     * Travas de segurança: um filtro de anos no modo diário (ou de décadas no
     * mensal) viraria um eixo quilométrico.
     */
    private const MAX_MONTHS = 60;
    private const MAX_DAYS   = 800;

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

    /** Mês por extenso, para o cabeçalho "TOTAL DE CHAMADOS DEZEMBRO/25". */
    private const MONTH_FULL = [
        1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARÇO', 4 => 'ABRIL', 5 => 'MAIO',
        6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO', 9 => 'SETEMBRO', 10 => 'OUTUBRO',
        11 => 'NOVEMBRO', 12 => 'DEZEMBRO',
    ];

    /**
     * Os quatro status do deck, na ordem em que ele os lista — que é a ordem
     * canônica do core sem "Novo" e sem "Em atendimento (planejado)". Esses
     * dois entram na tabela só quando têm chamado, para a soma sempre fechar
     * com o total (ver getByStatus()).
     */
    public const DECK_STATUSES = [
        CommonITILObject::ASSIGNED,
        CommonITILObject::WAITING,
        CommonITILObject::SOLVED,
        CommonITILObject::CLOSED,
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

    private static function grain(string $granularity): string
    {
        return $granularity === self::GRAIN_DAY ? self::GRAIN_DAY : self::GRAIN_MONTH;
    }

    /** Expressão SQL que reduz uma coluna de data à chave do bucket. */
    private static function bucketExpr(string $granularity, string $column): string
    {
        return self::grain($granularity) === self::GRAIN_DAY
            ? "DATE($column)"
            : "DATE_FORMAT($column, '%Y-%m')";
    }

    /**
     * Buckets do intervalo, em ordem e já rotulados:
     *   mês → ['2025-12' => 'DEZ/25', …]
     *   dia → ['2025-12-01' => '01/12', …]
     *
     * @return array<string,string>
     */
    public static function bucketLabels(string $start, string $end, string $granularity): array
    {
        $out   = [];
        $guard = 0;

        if (self::grain($granularity) === self::GRAIN_DAY) {
            $cur = strtotime(substr($start, 0, 10));
            $lim = strtotime(substr($end, 0, 10));
            while ($cur <= $lim && $guard++ < self::MAX_DAYS) {
                $out[date('Y-m-d', $cur)] = date('d/m', $cur);
                $cur = strtotime('+1 day', $cur);
            }
            return $out;
        }

        $cur = strtotime(date('Y-m-01', strtotime(substr($start, 0, 10))));
        $lim = strtotime(date('Y-m-01', strtotime(substr($end, 0, 10))));
        while ($cur <= $lim && $guard++ < self::MAX_MONTHS) {
            $out[date('Y-m', $cur)] = self::MONTH_ABBR[(int) date('n', $cur)] . '/' . date('y', $cur);
            $cur = strtotime('+1 month', $cur);
        }
        return $out;
    }

    /** Rótulo da coluna do bucket na tabela da seção 4. */
    public static function bucketColumnLabel(string $granularity): string
    {
        return self::grain($granularity) === self::GRAIN_DAY
            ? __('Dia', 'servicereports')
            : __('Mês', 'servicereports');
    }

    /**
     * Chamados abertos por bucket **e tipo** (Incidente / Requisição), pela
     * data de abertura.
     *
     * @return array<string,array{inc:int,req:int}>
     */
    public static function getByBucketAndType(string $start, string $end, string $granularity): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $b   = self::bucketExpr($granularity, 'glpi_tickets.date');

        $out = [];
        foreach (array_keys(self::bucketLabels($start, $end, $granularity)) as $k) {
            $out[$k] = ['inc' => 0, 'req' => 0];
        }
        foreach (self::rows(
            "SELECT $b b, glpi_tickets.type t, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY b, t"
        ) as $r) {
            $k = (string) $r['b'];
            if (!isset($out[$k])) {
                continue;
            }
            $key = (int) $r['t'] === Ticket::INCIDENT_TYPE ? 'inc' : 'req';
            $out[$k][$key] += (int) $r['n'];
        }
        return $out;
    }

    /**
     * Contagem por bucket de uma coluna de data (`date` para abertos,
     * `closedate` para fechados), com todos os buckets do intervalo presentes.
     *
     * @return array<string,int>
     */
    private static function countByBucket(
        string $start,
        string $end,
        string $granularity,
        string $column
    ): array {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');
        $b   = self::bucketExpr($granularity, $column);

        $out = array_fill_keys(array_keys(self::bucketLabels($start, $end, $granularity)), 0);
        foreach (self::rows(
            "SELECT $b b, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND $column BETWEEN '$s' AND '$e' $ent
             GROUP BY b"
        ) as $r) {
            $k = (string) $r['b'];
            if (isset($out[$k])) {
                $out[$k] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** Chamados **abertos** por bucket (pela data de abertura). */
    public static function getOpenedByBucket(string $start, string $end, string $granularity): array
    {
        return self::countByBucket($start, $end, $granularity, 'glpi_tickets.date');
    }

    /**
     * Chamados **fechados** por bucket — pela `closedate` (status Fechado). Um
     * chamado apenas Solucionado ainda não conta; ver o cabeçalho da classe.
     */
    public static function getClosedByBucket(string $start, string $end, string $granularity): array
    {
        return self::countByBucket($start, $end, $granularity, 'glpi_tickets.closedate');
    }

    /**
     * Rótulo do total na tabela de status: "DEZEMBRO/25" quando o período cabe
     * num mês só (o caso do relatório MENSAL) e "NO PERÍODO" quando atravessa
     * meses — aí o nome de um mês seria mentira.
     */
    public static function statusPeriodLabel(string $start, string $end): string
    {
        $s = strtotime(substr($start, 0, 10));
        $e = strtotime(substr($end, 0, 10));
        if (date('Y-m', $s) === date('Y-m', $e)) {
            return self::MONTH_FULL[(int) date('n', $s)] . '/' . date('y', $s);
        }
        return __('NO PERÍODO', 'servicereports');
    }

    /**
     * Nome de cada status **como o deck escreve** ("Atribuído", e não o
     * "Em atendimento (atribuído)" do core). A legenda e a tabela da 2ª seção
     * ficam lado a lado, então têm de usar as mesmas palavras.
     *
     * @return array<int,string>
     */
    public static function statusNames(): array
    {
        return [
            CommonITILObject::ASSIGNED => __('Atribuído', 'servicereports'),
            CommonITILObject::WAITING  => __('Pendente', 'servicereports'),
            CommonITILObject::SOLVED   => __('Solucionado', 'servicereports'),
            CommonITILObject::CLOSED   => __('Fechado', 'servicereports'),
        ];
    }

    /**
     * Chamados abertos no período, pelo **status atual** de cada um — a tabela
     * que o deck imprime ao lado da legenda ("TOTAL DE CHAMADOS DEZEMBRO/25").
     *
     * Os quatro status do deck saem sempre, mesmo zerados (é o que a legenda ao
     * lado explica); "Novo" e "Em atendimento (planejado)" entram só quando têm
     * chamado. Assim a soma das linhas fecha com o total, que é o número que o
     * cliente confere.
     *
     * @return array<int,array{label:string,value:int}>
     */
    public static function getByStatus(string $start, string $end): array
    {
        global $DB;
        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $counts = [];
        foreach (self::rows(
            "SELECT glpi_tickets.status st, COUNT(*) n FROM glpi_tickets
             WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent
             GROUP BY st"
        ) as $r) {
            $counts[(int) $r['st']] = (int) $r['n'];
        }

        $names = self::statusNames();
        $core  = Ticket::getAllStatusArray();
        $out   = [];
        // Ordem canônica do core, para "Novo"/"Planejado" caírem no lugar certo.
        foreach (array_keys($core) as $id) {
            $id = (int) $id;
            $n  = $counts[$id] ?? 0;
            if ($n === 0 && !in_array($id, self::DECK_STATUSES, true)) {
                continue;
            }
            $out[] = [
                'label' => $names[$id] ?? (string) $core[$id],
                'value' => $n,
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
        $names = self::statusNames();
        $descs = [
            CommonITILObject::ASSIGNED => __('Chamado assumido pelo técnico.', 'servicereports'),
            CommonITILObject::WAITING  => __('Status que sinaliza a dependência de terceiros, equipamentos ou algo '
                                           . 'que não esteja relacionado com o usuário ou o técnico diretamente.', 'servicereports'),
            CommonITILObject::SOLVED   => __('O chamado foi solucionado pelo técnico mas ainda não foi aprovado ou '
                                           . 'recusado pelo usuário.', 'servicereports'),
            CommonITILObject::CLOSED   => __('O chamado foi aprovado pelo usuário ou foi fechado pelo sistema após '
                                           . 'um período sem retorno do usuário (para aprovação ou recusa do '
                                           . 'chamado).', 'servicereports'),
        ];

        $out = [];
        foreach (self::DECK_STATUSES as $id) {
            $out[] = [$names[$id], $descs[$id]];
        }
        return $out;
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

    /** Nome do relatório: seletor, tela, PDF e CSV. */
    public static function title(string $granularity): string
    {
        return self::grain($granularity) === self::GRAIN_DAY
            ? __('Relatório de atualização - Cliente - MENSAL', 'servicereports')
            : __('Relatório de atualização - Cliente - ANUAL', 'servicereports');
    }

    /** Sufixo dos arquivos exportados. */
    public static function slug(string $granularity): string
    {
        return self::grain($granularity) === self::GRAIN_DAY ? 'mensal' : 'anual';
    }

    /**
     * Título das duas seções de série temporal, que mudam de nome com a
     * granularidade ("por mês" × "por dia").
     *
     * @return array{types:string,flow:string}
     */
    public static function seriesTitles(string $granularity): array
    {
        if (self::grain($granularity) === self::GRAIN_DAY) {
            return [
                'types' => __('Chamados por dia', 'servicereports'),
                'flow'  => __('Abertos × Fechados por dia', 'servicereports'),
            ];
        }
        return [
            'types' => __('Chamados por mês', 'servicereports'),
            'flow'  => __('Abertos × Fechados por mês', 'servicereports'),
        ];
    }

    /**
     * Tudo o que o relatório precisa, numa passada só.
     *
     * @param string $granularity self::GRAIN_MONTH (ANUAL) ou self::GRAIN_DAY (MENSAL)
     */
    public static function getReport(string $start, string $end, string $granularity): array
    {
        $granularity = self::grain($granularity);

        $buckets = self::bucketLabels($start, $end, $granularity);
        $byType  = self::getByBucketAndType($start, $end, $granularity);
        $opened  = self::getOpenedByBucket($start, $end, $granularity);
        $closed  = self::getClosedByBucket($start, $end, $granularity);

        $inc = 0;
        $req = 0;
        foreach ($byType as $b) {
            $inc += $b['inc'];
            $req += $b['req'];
        }

        return [
            'granularity'   => $granularity,
            'title'         => self::title($granularity),
            'series_titles' => self::seriesTitles($granularity),
            'bucket_label'  => self::bucketColumnLabel($granularity),
            'client'        => (string) ($_SESSION['glpiactive_entity_shortname'] ?? ''),
            'start'         => $start,
            'end'           => $end,
            'statuses'      => self::statusGlossary(),
            'by_status'     => self::getByStatus($start, $end),
            'status_period' => self::statusPeriodLabel($start, $end),
            'buckets'       => $buckets,
            'by_type'       => $byType,
            'types'         => ['inc' => $inc, 'req' => $req],
            'categories'    => PluginServicereportsServicecentral::getTopCategories($start, $end, 5),
            'opened'        => $opened,
            'closed'        => $closed,
            'hours'         => self::getByHour($start, $end),
            'total_open'    => array_sum($opened),
            'total_closed'  => array_sum($closed),
        ];
    }
}
