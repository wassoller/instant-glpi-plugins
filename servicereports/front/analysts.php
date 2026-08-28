<?php
/**
 * Bloco: Analistas — Desempenho de Analistas (Técnicos / Relatórios / Mapas).
 */

include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight('plugin_servicereports', READ);

$tab      = $_GET['tab'] ?? 'tecnicos';
$report   = (int) ($_GET['report'] ?? 0);
$techId   = (int) ($_GET['technician_id'] ?? 0);
$ticketId = (int) ($_GET['ticket_id'] ?? 0);
$start    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'] ?? '') ? $_GET['start_date'] : date('Y-m-01');
$end      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'] ?? '') ? $_GET['end_date'] : date('Y-m-t');
$startDt  = $start . ' 00:00:00';
$endDt    = $end . ' 23:59:59';
// Rótulo do período pedido no filtro — impresso como última linha do relatório 60.
$periodLabel = date('d/m/Y', strtotime($start)) . ' ' . __('a', 'servicereports') . ' ' . date('d/m/Y', strtotime($end));

// Exportação CSV — deve ocorrer antes de qualquer saída HTML.
if (($_GET['export'] ?? '') === 'csv' && $tab === 'relatorios' && in_array($report, [57, 59, 60, 61], true)) {
    $filename = ['57' => 'tarefas_por_tecnico.csv', '59' => 'horas_fora_expediente.csv',
                 '60' => 'entidade_vs_analistas.csv',
                 '61' => 'chamados_por_status_e_tecnico.csv'][(string) $report];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
    $dec = static fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($report === 57) {
        $data = PluginServicereportsAnalysts::getTasksReport($startDt, $endDt, $techId, $ticketId);
        fputcsv($out, ['Chamado', 'Autor', 'Entidade', 'Data', 'Categoria', 'Descrição', 'Técnico', 'Início', 'Fim', 'Duração'], ';', '"', '');
        foreach ($data['rows'] as $r) {
            fputcsv($out, array_map($dec, [
                (int) $r['tickets_id'],
                getUserName((int) $r['author']),
                Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']),
                $r['task_date'],
                (int) $r['ticket_cat'] ? Dropdown::getDropdownName('glpi_itilcategories', (int) $r['ticket_cat']) : '',
                Toolbox::stripTags($r['content']),
                getUserName((int) $r['tech']),
                $r['begin'],
                $r['end'],
                PluginServicereportsAnalysts::secToHms((int) $r['actiontime']),
            ]), ';', '"', '');
        }
    } elseif ($report === 60) {
        // Matriz analista × entidade: mesma tabela da tela, sem paginação.
        $m       = PluginServicereportsAnalysts::getEntityAnalystMatrix($startDt, $endDt, $techId);
        $entCols = array_keys($m['entities']);
        $header  = ['Analista'];
        foreach ($entCols as $entId) {
            $header[] = $m['entities'][$entId]['name'];
        }
        $header[] = 'Total';
        fputcsv($out, array_map($dec, $header), ';', '"', '');
        foreach ($m['rows'] as $row) {
            $line = [$row['name']];
            foreach ($entCols as $entId) {
                $line[] = PluginServicereportsAnalysts::secToHms((int) ($row['cells'][$entId] ?? 0));
            }
            $line[] = PluginServicereportsAnalysts::secToHms((int) $row['total']);
            fputcsv($out, array_map($dec, $line), ';', '"', '');
        }
        $line = ['Total'];
        foreach ($entCols as $entId) {
            $line[] = PluginServicereportsAnalysts::secToHms((int) ($m['totals'][$entId] ?? 0));
        }
        $line[] = PluginServicereportsAnalysts::secToHms((int) $m['grand']);
        fputcsv($out, array_map($dec, $line), ';', '"', '');
        // Última linha: o período solicitado no filtro (mesma informação da tela).
        fputcsv($out, array_map($dec, ['Período do relatório', $periodLabel]), ';', '"', '');
    } elseif ($report === 61) {
        // Duas seções, separadas por linha em branco (mesma convenção do CSV do
        // relatório central de serviços): técnico × status e técnico × tipo.
        // Nenhuma delas pagina — são as mesmas tabelas da tela.
        $section = static function (array $d, string $title) use ($out, $dec) {
            fputcsv($out, [$dec($title)], ';', '"', '');
            $header = ['Técnico'];
            foreach ($d['keys'] as $k) {
                $header[] = $d['labels'][$k];
            }
            $header[] = 'Total';
            fputcsv($out, array_map($dec, $header), ';', '"', '');
            foreach ($d['rows'] as $row) {
                $line = [$row['name']];
                foreach ($d['keys'] as $k) {
                    $line[] = (int) ($row['counts'][$k] ?? 0);
                }
                $line[] = (int) $row['total'];
                fputcsv($out, array_map($dec, $line), ';', '"', '');
            }
            $line = ['Total'];
            foreach ($d['keys'] as $k) {
                $line[] = (int) ($d['totals'][$k] ?? 0);
            }
            $line[] = (int) $d['grand'];
            fputcsv($out, array_map($dec, $line), ';', '"', '');
        };

        $section(
            PluginServicereportsAnalysts::getStatusByTechnician($startDt, $endDt, $techId),
            'Chamados por status e técnico'
        );
        fputcsv($out, [''], ';', '"', '');
        $section(
            PluginServicereportsAnalysts::getTypeByTechnician($startDt, $endDt, $techId),
            'Chamados por tipo e técnico'
        );
        fputcsv($out, [''], ';', '"', '');
        fputcsv($out, array_map($dec, ['Período do relatório', $periodLabel]), ';', '"', '');
    } else {
        $rows = PluginServicereportsAnalysts::getOutOfHoursReport($startDt, $endDt, $techId, $ticketId);
        fputcsv($out, ['Técnico', 'ID do chamado', 'Tempo total de tarefas', 'Tempo fora do expediente', 'Entidade'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map($dec, [
                getUserName((int) $r['tech']),
                (int) $r['tickets_id'],
                PluginServicereportsAnalysts::secToHms((int) $r['total']),
                PluginServicereportsAnalysts::secToHms((int) $r['outside']),
                Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']),
            ]), ';', '"', '');
        }
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// PDF (TCPDF) do relatório 61 — antes de qualquer saída HTML.
//
// `ob_start()`/`ob_end_clean()` são obrigatórios: com display_errors ligado,
// qualquer aviso do PHP impresso durante a montagem entra **dentro** do binário
// e o arquivo não abre (o TCPDF dispara um "Deprecated: imagedestroy()" no
// PHP 8.5). Mesma armadilha do extrato financeiro.
// ---------------------------------------------------------------------------
if ($tab === 'relatorios' && $report === 61 && ($_GET['pdf'] ?? '') === '1') {
    $data      = PluginServicereportsAnalysts::getStatusByTechnician($startDt, $endDt, $techId);
    $typeData  = PluginServicereportsAnalysts::getTypeByTechnician($startDt, $endDt, $techId);
    $techLabel = $techId > 0 ? getUserName($techId) : __('Todos', 'servicereports');

    ob_start();
    $bytes = PluginServicereportsStatustechpdf::build($data, $typeData, $start, $end, $techLabel);
    ob_end_clean();

    $file = 'chamados_por_status_e_tecnico_' . $start . '_' . $end . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $file . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

Html::header(
    __('Analistas', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

$base = $CFG_GLPI['root_doc'] . '/plugins/servicereports/front/analysts.php';
$tabs = [
    'tecnicos'   => __('Técnicos', 'servicereports'),
    'relatorios' => __('Relatórios', 'servicereports'),
    'mapas'      => __('Mapas', 'servicereports'),
];

echo "<div class='container-fluid mt-3'>";

// --- Navegação de sub-abas ---
echo "<ul class='nav nav-tabs mb-3'>";
foreach ($tabs as $key => $label) {
    $active = $tab === $key ? 'active' : '';
    echo "<li class='nav-item'><a class='nav-link $active' href='" . Html::cleanInputText("$base?tab=$key") . "'>$label</a></li>";
}
echo "</ul>";

// --- Filtro comum (data + técnico) ---
$renderFilter = function (string $tab) use ($base, $start, $end, $techId) {
    echo "<form method='get' action='" . Html::cleanInputText($base) . "' class='card card-body mb-3'>";
    echo Html::hidden('tab', ['value' => $tab]);
    if (isset($_GET['report'])) {
        echo Html::hidden('report', ['value' => (int) $_GET['report']]);
    }
    echo "<div class='row g-2 align-items-end'>";
    echo "<div class='col-auto'><label class='form-label'>" . __('De', 'servicereports') . "</label>";
    Html::showDateField('start_date', ['value' => $start]);
    echo "</div>";
    echo "<div class='col-auto'><label class='form-label'>" . __('Até', 'servicereports') . "</label>";
    Html::showDateField('end_date', ['value' => $end]);
    echo "</div>";
    echo "<div class='col-auto'><label class='form-label'>" . __('Técnico', 'servicereports') . "</label><br>";
    // Todos os técnicos do GLPI (direito own_ticket), não só os ativos no período.
    Dropdown::showFromArray(
        'technician_id',
        [0 => __('Todos', 'servicereports')] + PluginServicereportsAnalysts::getAllTechnicians(),
        ['value' => $techId, 'width' => '260px']
    );
    echo "</div>";
    echo "<div class='col-auto'>" . Html::submit(__('Filtrar', 'servicereports'), ['class' => 'btn btn-primary']) . "</div>";
    echo "</div></form>";
};

if ($tab === 'tecnicos') {
    $renderFilter('tecnicos');
    $techFilter = $techId > 0 ? [$techId] : [];
    $perf = PluginServicereportsAnalysts::getPerformance($techFilter, $startDt, $endDt);

    echo "<h2 class='text-center mb-3'>" . __('Performance de técnicos', 'servicereports') . "</h2>";
    if (empty($perf)) {
        echo "<div class='alert alert-info'>" . __('Nenhum técnico com atividade no período.', 'servicereports') . "</div>";
    } else {
        echo "<div class='row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3'>";
        foreach ($perf as $p) {
            $total   = max(1, (int) $p['tickets']);
            $incPct  = round($p['incidents'] / $total * 100);
            $reqPct  = round($p['requests'] / $total * 100);
            $sat     = $p['satisfaction'] === null ? '—' : $p['satisfaction'] . '%';
            echo "<div class='col'><div class='card h-100 shadow-sm'><div class='card-body'>";
            echo "<h3 class='h6 mb-3'><i class='ti ti-user me-1'></i>" . $p['name'] . "</h3>";
            echo "<div class='d-flex justify-content-between mb-1'><span class='text-muted'>" . __('Horas trabalhadas', 'servicereports') . "</span><strong>" . PluginServicereportsAnalysts::secToHms((int) $p['worked']) . "</strong></div>";
            echo "<div class='d-flex justify-content-between mb-1'><span class='text-muted'>" . __('Pontos', 'servicereports') . "</span><strong>" . (int) $p['solved'] . "</strong></div>";
            echo "<div class='d-flex justify-content-between mb-1'><span class='text-muted'>" . __('Satisfação', 'servicereports') . "</span><strong>$sat</strong></div>";
            echo "<div class='d-flex justify-content-between mb-1'><span class='text-muted'>" . __('Chamados atendidos', 'servicereports') . "</span><strong>" . (int) $p['tickets'] . "</strong></div>";
            echo "<div class='d-flex justify-content-between mb-1'><span class='text-muted'>" . __('Incidentes', 'servicereports') . "</span><strong>" . (int) $p['incidents'] . " <span class='text-muted'>($incPct%)</span></strong></div>";
            echo "<div class='d-flex justify-content-between'><span class='text-muted'>" . __('Requisições', 'servicereports') . "</span><strong>" . (int) $p['requests'] . " <span class='text-muted'>($reqPct%)</span></strong></div>";
            echo "</div></div></div>";
        }
        echo "</div>";
    }
} elseif ($tab === 'relatorios') {
    // seletor de relatório
    $reports = [
        0  => __('---', 'servicereports'),
        57 => __('Relatório de Tarefas por Técnico', 'servicereports'),
        58 => __('Relatório de Deslocamentos por Técnico', 'servicereports'),
        59 => __('Relatório de Horas fora de expediente de Técnicos por Chamados', 'servicereports'),
        60 => __('Entidade vs. Analistas', 'servicereports'),
        61 => __('Chamados por Status e Técnico', 'servicereports'),
    ];
    echo "<form method='get' action='" . Html::cleanInputText($base) . "' class='mb-3' id='reportform'>";
    echo Html::hidden('tab', ['value' => 'relatorios']);
    Dropdown::showFromArray('report', $reports, [
        'value'     => $report,
        'width'     => '420px',
        'on_change' => 'this.form.submit()',
    ]);
    echo "</form>";

    $renderFilter('relatorios');

    if (in_array($report, [57, 59, 60, 61], true)) {
        $expArgs = [
            'tab' => 'relatorios', 'report' => $report, 'start_date' => $start,
            'end_date' => $end, 'technician_id' => $techId, 'ticket_id' => $ticketId,
        ];
        $expUrl = $base . '?' . http_build_query($expArgs + ['export' => 'csv']);
        echo "<div class='mb-3 d-flex gap-2'>";
        echo "<a href='" . Html::cleanInputText($expUrl) . "' class='btn btn-outline-success btn-sm'>"
            . "<i class='ti ti-file-spreadsheet me-1'></i>" . __('Exportar CSV', 'servicereports') . "</a>";
        if ($report === 61) {
            // Paisagem, gráfico + tabela — ver inc/statustechpdf.class.php.
            $pdfUrl = $base . '?' . http_build_query($expArgs + ['pdf' => 1]);
            echo "<a href='" . Html::cleanInputText($pdfUrl) . "' target='_blank' class='btn btn-outline-danger btn-sm'>"
                . "<i class='ti ti-file-type-pdf me-1'></i>" . __('Exportar PDF', 'servicereports') . "</a>";
        }
        echo "</div>";
    }

    // Parâmetros preservados nos links de paginação.
    $pagerParams = [
        'tab' => 'relatorios', 'report' => $report, 'start_date' => $start,
        'end_date' => $end, 'technician_id' => $techId, 'ticket_id' => $ticketId,
    ];
    $perPage = PluginServicereportsPager::PER_PAGE;

    if ($report === 57) {
        $count  = PluginServicereportsAnalysts::getTasksReport($startDt, $endDt, $techId, $ticketId, 1);
        $offset = PluginServicereportsPager::offset((int) $count['total_tasks']);
        $data   = PluginServicereportsAnalysts::getTasksReport($startDt, $endDt, $techId, $ticketId, $perPage, $offset);
        echo "<h3 class='mb-2'>" . __('Tarefas por técnico', 'servicereports') . "</h3>";
        echo "<div class='mb-3 text-muted'>";
        echo __('Total de tarefas', 'servicereports') . ": <strong>" . $data['total_tasks'] . "</strong> &middot; ";
        echo __('Tempo total de tarefas', 'servicereports') . ": <strong>" . PluginServicereportsAnalysts::secToHms($data['total_time']) . "</strong>";
        echo "</div>";
        PluginServicereportsPager::show($base, $pagerParams, $offset, (int) $data['total_tasks']);
        echo "<div class='table-responsive'><table class='table table-hover'>";
        echo "<thead><tr>"
            . "<th>" . __('Chamado', 'servicereports') . "</th><th>" . __('Autor', 'servicereports') . "</th>"
            . "<th>" . __('Entidade', 'servicereports') . "</th><th>" . __('Data', 'servicereports') . "</th>"
            . "<th>" . __('Categoria', 'servicereports') . "</th>"
            . "<th>" . __('Descrição', 'servicereports') . "</th><th>" . __('Técnico', 'servicereports') . "</th>"
            . "<th>" . __('Início', 'servicereports') . "</th><th>" . __('Fim', 'servicereports') . "</th>"
            . "<th>" . __('Duração', 'servicereports') . "</th></tr></thead><tbody>";
        foreach ($data['rows'] as $r) {
            echo "<tr>";
            echo "<td><a href='" . Html::cleanInputText($CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int) $r['tickets_id']) . "'>" . (int) $r['tickets_id'] . "</a></td>";
            echo "<td>" . getUserName((int) $r['author']) . "</td>";
            echo "<td>" . Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']) . "</td>";
            echo "<td>" . Html::convDateTime($r['task_date']) . "</td>";
            echo "<td>" . ((int) $r['ticket_cat'] ? Dropdown::getDropdownName('glpi_itilcategories', (int) $r['ticket_cat']) : '-') . "</td>";
            echo "<td>" . Html::resume_text(Toolbox::stripTags($r['content']), 50) . "</td>";
            echo "<td>" . getUserName((int) $r['tech']) . "</td>";
            echo "<td>" . Html::convDateTime($r['begin']) . "</td>";
            echo "<td>" . Html::convDateTime($r['end']) . "</td>";
            echo "<td>" . PluginServicereportsAnalysts::secToHms((int) $r['actiontime']) . "</td>";
            echo "</tr>";
        }
        if (empty($data['rows'])) {
            echo "<tr><td colspan='10' class='text-center text-muted'>" . __('Nenhum item encontrado', 'servicereports') . "</td></tr>";
        }
        echo "</tbody></table></div>";
        PluginServicereportsPager::show($base, $pagerParams, $offset, (int) $data['total_tasks']);
    } elseif ($report === 58) {
        echo "<h3 class='mb-2'>" . __('Deslocamentos por técnico', 'servicereports') . "</h3>";
        echo "<div class='alert alert-secondary'>"
            . __('Distância total de deslocamentos: 0 Km · Tempo total: 00:00:00', 'servicereports') . "<br>"
            . __('Este relatório depende de uma fonte de dados de deslocamento (não nativa do GLPI). Sem registros disponíveis.', 'servicereports')
            . "</div>";
    } elseif ($report === 59) {
        $allRows = PluginServicereportsAnalysts::getOutOfHoursReport($startDt, $endDt, $techId, $ticketId);
        $total   = count($allRows);
        $offset  = PluginServicereportsPager::offset($total);
        $rows    = array_slice($allRows, $offset, $perPage);
        echo "<h3 class='mb-2'>" . __('Horas fora de expediente de técnicos por chamado', 'servicereports') . "</h3>";
        echo "<div class='text-muted mb-2'>" . __('Expediente considerado: Seg–Sex, 08:00–18:00.', 'servicereports') . "</div>";
        PluginServicereportsPager::show($base, $pagerParams, $offset, $total);
        echo "<div class='table-responsive'><table class='table table-hover'>";
        echo "<thead><tr>"
            . "<th>" . __('Técnico', 'servicereports') . "</th><th>" . __('ID do chamado', 'servicereports') . "</th>"
            . "<th>" . __('Tempo total de tarefas no chamado', 'servicereports') . "</th>"
            . "<th>" . __('Tempo de tarefas fora do expediente', 'servicereports') . "</th>"
            . "<th>" . __('Entidade', 'servicereports') . "</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            echo "<tr>";
            echo "<td>" . getUserName((int) $r['tech']) . "</td>";
            echo "<td>" . (int) $r['tickets_id'] . "</td>";
            echo "<td>" . PluginServicereportsAnalysts::secToHms((int) $r['total']) . "</td>";
            echo "<td>" . PluginServicereportsAnalysts::secToHms((int) $r['outside']) . "</td>";
            echo "<td>" . Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']) . "</td>";
            echo "</tr>";
        }
        if (empty($rows)) {
            echo "<tr><td colspan='5' class='text-center text-muted'>" . __('Nenhum item encontrado', 'servicereports') . "</td></tr>";
        }
        echo "</tbody></table></div>";
        PluginServicereportsPager::show($base, $pagerParams, $offset, $total);
    } elseif ($report === 60) {
        // Matriz analista × entidade. Não pagina: a tabela inteira é o relatório
        // (a planilha da Instant tem uma coluna por cliente); rola na horizontal
        // com a coluna do analista fixa à esquerda.
        $m       = PluginServicereportsAnalysts::getEntityAnalystMatrix($startDt, $endDt, $techId);
        $entCols = array_keys($m['entities']);

        echo "<style>
            .sr-eva { max-height: 70vh; overflow: auto; }
            .sr-eva table { table-layout: auto; margin-bottom: 0; }
            .sr-eva th, .sr-eva td { white-space: nowrap; }
            .sr-eva thead th { position: sticky; top: 0; z-index: 3; background: var(--tblr-bg-surface, #fff); }
            .sr-eva .sr-eva-name { position: sticky; left: 0; z-index: 2; background: var(--tblr-bg-surface, #fff); }
            .sr-eva thead .sr-eva-name { z-index: 4; }
            .sr-eva tbody tr:nth-child(even) td { background: var(--tblr-bg-surface-secondary, #f8f9fa); }
            .sr-eva tbody tr:nth-child(even) td.sr-eva-name { background: var(--tblr-bg-surface-secondary, #f8f9fa); }
            .sr-eva td.sr-eva-zero { color: var(--tblr-secondary, #909296); }
            .sr-eva tfoot th { position: sticky; bottom: 0; z-index: 3; background: var(--tblr-bg-surface, #fff); }
            .sr-eva-period { font-size: 0.9em; }
        </style>";

        echo "<h3 class='mb-2'>" . __('Entidade vs. Analistas', 'servicereports') . "</h3>";
        echo "<div class='text-muted mb-3' style='font-size:0.85em'>"
            . __('Tempo de tarefas por analista e entidade. Entram TODAS as tarefas lançadas no período '
               . '(pela data da tarefa), esteja o chamado fechado ou ainda em aberto. Por isso o número não é '
               . 'o faturável do Extrato financeiro, que recorta o chamado pela data de fechamento.', 'servicereports')
            . "</div>";
        echo "<div class='mb-3 text-muted'>";
        echo __('Total de horas no período', 'servicereports') . ": <strong>" . PluginServicereportsAnalysts::secToHms((int) $m['grand']) . "</strong>";
        echo "</div>";

        if (empty($entCols)) {
            echo "<div class='alert alert-info'>" . __('Nenhuma entidade visível na sessão.', 'servicereports') . "</div>";
        } else {
            echo "<div class='sr-eva table-responsive'><table class='table table-sm table-bordered'>";
            echo "<thead><tr><th class='sr-eva-name'>" . __('Analista', 'servicereports') . "</th>";
            foreach ($entCols as $entId) {
                $e = $m['entities'][$entId];
                echo "<th class='text-center' title='" . Html::cleanInputText($e['completename']) . "'>" . $e['name'] . "</th>";
            }
            echo "<th class='text-center'>" . __('Total', 'servicereports') . "</th></tr></thead><tbody>";

            foreach ($m['rows'] as $row) {
                echo "<tr><td class='sr-eva-name'>" . $row['name'] . "</td>";
                foreach ($entCols as $entId) {
                    $sec  = (int) ($row['cells'][$entId] ?? 0);
                    $cls  = $sec > 0 ? '' : ' sr-eva-zero';
                    echo "<td class='text-center$cls'>" . PluginServicereportsAnalysts::secToHms($sec) . "</td>";
                }
                echo "<td class='text-center'><strong>" . PluginServicereportsAnalysts::secToHms((int) $row['total']) . "</strong></td></tr>";
            }
            if (empty($m['rows'])) {
                echo "<tr><td colspan='" . (count($entCols) + 2) . "' class='text-center text-muted'>"
                    . __('Nenhum item encontrado', 'servicereports') . "</td></tr>";
            }
            echo "</tbody><tfoot><tr><th class='sr-eva-name'>" . __('Total', 'servicereports') . "</th>";
            foreach ($entCols as $entId) {
                echo "<th class='text-center'>" . PluginServicereportsAnalysts::secToHms((int) ($m['totals'][$entId] ?? 0)) . "</th>";
            }
            echo "<th class='text-center'>" . PluginServicereportsAnalysts::secToHms((int) $m['grand']) . "</th>";
            echo "</tr></tfoot></table></div>";
        }
        // Última linha do relatório: o período solicitado no filtro. Fica fora
        // da tabela porque o rodapé de totais é `position: sticky` e cobriria
        // uma segunda linha do <tfoot> ao rolar até o fim.
        echo "<div class='sr-eva-period mt-2'>" . __('Período do relatório', 'servicereports')
            . ': <strong>' . $periodLabel . '</strong></div>';
    } elseif ($report === 61) {
        // Dois gráficos de barras empilhadas (SVG gerado no PHP, sem biblioteca
        // JS), cada um com a tabela dos mesmos números: chamados por STATUS e
        // técnico e chamados por TIPO (Incidente × Requisição) e técnico. Os
        // dois blocos contam os MESMOS chamados — só muda a quebra da pilha —,
        // então o total por técnico bate entre eles. Não pagina (o gráfico é o
        // relatório); exporta CSV (uma seção por bloco) e PDF (paisagem, uma
        // página por bloco).
        $data     = PluginServicereportsAnalysts::getStatusByTechnician($startDt, $endDt, $techId);
        $typeData = PluginServicereportsAnalysts::getTypeByTechnician($startDt, $endDt, $techId);

        echo "<style>
            .sr-cst-legend { display:flex; flex-wrap:wrap; gap:.25rem 1rem; justify-content:flex-end;
                             font-size:.8rem; margin-bottom:.25rem; }
            .sr-cst-key { display:inline-flex; align-items:center; gap:.35rem; color:var(--tblr-secondary,#626976); }
            .sr-cst-key i { width:11px; height:11px; border-radius:50%; display:inline-block; }
            .sr-cst-wrap { overflow-x:auto; padding-bottom:.5rem; }
            .sr-cst-grid { stroke:var(--tblr-border-color,#e6e7e9); stroke-width:1; }
            .sr-cst-base { stroke:var(--tblr-body-color,#232b31); stroke-width:1.5; }
            .sr-cst-axis { font-size:11px; fill:var(--tblr-secondary,#626976); }
            .sr-cst-name { font-size:11px; fill:var(--tblr-body-color,#232b31); }
            .sr-cst-total { font-size:11px; font-weight:600; fill:var(--tblr-secondary,#626976); }
            .sr-cst-seg { cursor:default; }
            .sr-cst-seg:hover { opacity:.78; }
            .sr-cst-tip { position:fixed; z-index:1080; display:none; pointer-events:none;
                          background:#1f2933; color:#fff; border-radius:4px; padding:.4rem .6rem;
                          font-size:.78rem; line-height:1.35; box-shadow:0 2px 8px rgba(0,0,0,.25); }
            .sr-cst-tip b { font-weight:600; }
            .sr-cst-table th, .sr-cst-table td { white-space:nowrap; }
            .sr-cst-table td.sr-cst-zero { color:var(--tblr-secondary,#909296); }
            .sr-cst-dot { width:9px; height:9px; border-radius:50%; display:inline-block; margin-right:.3rem; }
        </style>";

        // Tabela técnico × pilha — a mesma para os dois blocos: as colunas saem
        // de `keys`/`labels`/`colors`, como no gráfico.
        $renderCstTable = function (array $d) {
            if (empty($d['rows'])) {
                return;
            }
            echo "<div class='table-responsive'><table class='table table-sm table-hover table-bordered sr-cst-table'>";
            echo "<thead><tr><th>" . __('Técnico', 'servicereports') . "</th>";
            foreach ($d['keys'] as $k) {
                echo "<th class='text-center'><span class='sr-cst-dot' style='background:"
                    . $d['colors'][$k] . "'></span>" . $d['labels'][$k] . "</th>";
            }
            echo "<th class='text-center'>" . __('Total', 'servicereports') . "</th></tr></thead><tbody>";
            foreach ($d['rows'] as $row) {
                echo "<tr><td>" . $row['name'] . "</td>";
                foreach ($d['keys'] as $k) {
                    $n = (int) ($row['counts'][$k] ?? 0);
                    echo "<td class='text-center" . ($n > 0 ? '' : ' sr-cst-zero') . "'>$n</td>";
                }
                echo "<td class='text-center'><strong>" . (int) $row['total'] . "</strong></td></tr>";
            }
            echo "</tbody><tfoot><tr><th>" . __('Total', 'servicereports') . "</th>";
            foreach ($d['keys'] as $k) {
                echo "<th class='text-center'>" . (int) ($d['totals'][$k] ?? 0) . "</th>";
            }
            echo "<th class='text-center'>" . (int) $d['grand'] . "</th></tr></tfoot></table></div>";
        };

        // --- Bloco 1: por status ---
        echo "<h3 class='mb-2'>" . __('Chamados por status e técnico', 'servicereports') . "</h3>";
        echo "<div class='text-muted mb-3' style='font-size:0.85em'>"
            . __('Conta CHAMADOS (não tarefas) pelo técnico ATRIBUÍDO, recortados pela data de abertura, '
               . 'no status em que estão hoje. Chamado com mais de um técnico atribuído conta para cada um, '
               . 'então a soma das barras pode passar o número de chamados do período.', 'servicereports')
            . "</div>";

        echo "<div class='mb-3 text-muted'>";
        echo __('Total de chamados', 'servicereports') . ": <strong>" . (int) $data['grand'] . "</strong> &middot; ";
        echo __('Técnicos', 'servicereports') . ": <strong>" . count($data['rows']) . "</strong>";
        echo "</div>";

        echo "<div class='card mb-3'><div class='card-body'>";
        PluginServicereportsAnalysts::renderStackedChart($data, __('Chamados por status e técnico', 'servicereports'));
        echo "</div></div>";

        $renderCstTable($data);

        // --- Bloco 2: por tipo (Incidente × Requisição) ---
        echo "<h3 class='mb-2 mt-4'>" . __('Chamados por tipo e técnico', 'servicereports') . "</h3>";
        echo "<div class='text-muted mb-3' style='font-size:0.85em'>"
            . __('Os MESMOS chamados do gráfico acima, quebrados por tipo — Incidente e Requisição são os '
               . 'dois tipos de chamado do GLPI. O total de cada técnico é igual ao do gráfico de status.', 'servicereports')
            . "</div>";

        echo "<div class='card mb-3'><div class='card-body'>";
        PluginServicereportsAnalysts::renderStackedChart($typeData, __('Chamados por tipo e técnico', 'servicereports'));
        echo "</div></div>";

        $renderCstTable($typeData);

        echo "<div class='sr-cst-period mt-2' style='font-size:0.9em'>" . __('Período do relatório', 'servicereports')
            . ': <strong>' . $periodLabel . '</strong></div>';

        // Tooltip dos gráficos: um só elemento para os dois (montado com
        // textContent — os nomes vêm dos data-* já decodificados pelo DOM;
        // innerHTML com nome de usuário é convite a XSS).
        $totalWord = __('Total do técnico', 'servicereports');
        echo Html::scriptBlock(<<<JS
(function () {
    var wraps = document.querySelectorAll('.sr-cst-wrap');
    if (!wraps.length) { return; }
    var tip = document.createElement('div');
    tip.className = 'sr-cst-tip';
    document.body.appendChild(tip);

    function fill(el) {
        tip.textContent = '';
        var head = document.createElement('div');
        head.appendChild(document.createElement('b')).textContent = el.getAttribute('data-tech');
        var line = document.createElement('div');
        line.textContent = el.getAttribute('data-series') + ': ' + el.getAttribute('data-n')
            + ' (' + el.getAttribute('data-pct') + '%)';
        var tot = document.createElement('div');
        tot.textContent = '{$totalWord}: ' + el.getAttribute('data-total');
        tip.appendChild(head);
        tip.appendChild(line);
        tip.appendChild(tot);
    }

    // Posicionar já no mouseover: só no mousemove o tooltip apareceria no
    // canto da página se o ponteiro entrasse na barra sem se mover depois.
    function place(e) {
        var x = e.clientX + 14, y = e.clientY + 14;
        if (x + tip.offsetWidth > window.innerWidth) { x = e.clientX - tip.offsetWidth - 14; }
        if (y + tip.offsetHeight > window.innerHeight) { y = e.clientY - tip.offsetHeight - 14; }
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
    }

    Array.prototype.forEach.call(wraps, function (wrap) {
        wrap.addEventListener('mouseover', function (e) {
            var el = e.target;
            if (!el.classList || !el.classList.contains('sr-cst-seg')) { return; }
            fill(el);
            tip.style.display = 'block';
            place(e);
        });
        wrap.addEventListener('mousemove', function (e) {
            if (tip.style.display === 'block') { place(e); }
        });
        wrap.addEventListener('mouseout', function (e) {
            if (e.target.classList && e.target.classList.contains('sr-cst-seg')) { tip.style.display = 'none'; }
        });
    });
})();
JS);
    } else {
        echo "<div class='alert alert-info'>" . __('Selecione um relatório.', 'servicereports') . "</div>";
    }
} elseif ($tab === 'mapas') {
    echo "<h2 class='text-center mb-3'>" . __('Posicionamento geográfico de técnicos', 'servicereports') . "</h2>";
    echo "<div class='alert alert-info'>"
        . __('O mapa de posicionamento de técnicos depende de uma fonte de geolocalização (não nativa do GLPI). '
           . 'Pode ser conectado às coordenadas das localizações (glpi_locations) dos técnicos/chamados.', 'servicereports')
        . "</div>";
}

echo "</div>";

Html::footer();
