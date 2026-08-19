<?php
/**
 * Bloco: Gestão financeira (lê dados do plugin managedservices).
 *
 * Sub-abas (paridade com o vReports original):
 *   - Dashboards  → KPIs + gráficos.
 *   - Relatórios  → seletor de relatório (Extrato financeiro / Faturamento
 *                   financeiro / Fatura de serviços detalhada) + filtro de período.
 */

include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight('plugin_servicereports', READ);

$base   = $CFG_GLPI['root_doc'] . '/plugins/servicereports/front/financial.php';
$tab    = ($_GET['tab'] ?? 'dashboards') === 'relatorios' ? 'relatorios' : 'dashboards';
$report = (int) ($_GET['report'] ?? 0);
$start  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'] ?? '') ? $_GET['start_date'] : date('Y-m-01');
$end    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'] ?? '') ? $_GET['end_date'] : date('Y-m-t');
$startDt = $start . ' 00:00:00';
$endDt   = $end . ' 23:59:59';
$isPdf  = ($_GET['pdf'] ?? '') === '1';

$available = PluginServicereportsFinancial::isAvailable();

// URL builder p/ preservar filtros.
$url = static function (array $extra = []) use ($base, $tab, $report, $start, $end) {
    return $base . '?' . http_build_query(array_merge([
        'tab' => $tab, 'report' => $report, 'start_date' => $start, 'end_date' => $end,
    ], $extra));
};

// ---------------------------------------------------------------------------
// Exportação CSV — antes de qualquer saída HTML.
// ---------------------------------------------------------------------------
if ($available && $tab === 'relatorios' && ($_GET['export'] ?? '') === 'csv' && in_array($report, [1, 2], true)) {
    $filename = $report === 1 ? 'extrato_financeiro.csv' : 'faturamento_financeiro.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
    $dec = static fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $m   = static fn (float $v) => number_format($v, 2, ',', '.');

    $extrato = PluginServicereportsFinancial::getExtrato($startDt, $endDt);
    if ($report === 1) {
        fputcsv($out, ['Entidade', 'Serviço', 'Valor mensal', 'Valor ativos', 'Valor categoria', 'Valor extras', 'Tempo tarefas', 'Valor tarefas', 'Valor total'], ';', '"', '');
        foreach ($extrato as $ent) {
            foreach ($ent['services'] as $svc) {
                fputcsv($out, array_map($dec, [
                    $ent['name'], $svc['name'], $m($svc['mensal']), $m($svc['ativos']),
                    $m($svc['categoria']), $m($svc['extras']),
                    PluginServicereportsFinancial::hms((int) $svc['task_seconds']),
                    $m($svc['task_value']), $m($svc['total']),
                ]), ';', '"', '');
            }
        }
    } else { // 2 — faturamento por entidade
        fputcsv($out, ['Entidade', 'Valor total faturado'], ';', '"', '');
        $geral = 0.0;
        foreach ($extrato as $ent) {
            $geral += $ent['summary']['total'];
            fputcsv($out, array_map($dec, [$ent['name'], $m($ent['summary']['total'])]), ';', '"', '');
        }
        fputcsv($out, array_map($dec, ['TOTAL GERAL', $m($geral)]), ';', '"', '');
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Visão de impressão (PDF) — página limpa, sem menu, auto-imprime.
// ---------------------------------------------------------------------------
if ($available && $tab === 'relatorios' && $isPdf && in_array($report, [1, 4], true)) {
    Html::popHeader(__('Gestão financeira', 'servicereports'), $_SERVER['PHP_SELF']);
    echo "<div class='container-fluid p-4'>";
    $extrato = PluginServicereportsFinancial::getExtrato($startDt, $endDt);
    if ($report === 1) {
        // versão de impressão: cabeçalho com logo/título, sem os botões da tela.
        PluginServicereportsFinancial::renderExtratoPrint($extrato, $start, $end);
    } else {
        PluginServicereportsFinancial::renderFaturaDetalhada($extrato, $start, $end);
    }
    echo "</div>";
    echo Html::scriptBlock("window.onload=function(){window.print();};");
    Html::popFooter();
    exit;
}

// ---------------------------------------------------------------------------
// Página normal.
// ---------------------------------------------------------------------------
Html::header(
    __('Gestão financeira', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

echo "<div class='container-fluid mt-3'>";

// Navegação de sub-abas.
$subtabs = [
    'dashboards' => __('Dashboards', 'servicereports'),
    'relatorios' => __('Relatórios', 'servicereports'),
];
echo "<ul class='nav nav-tabs mb-3'>";
foreach ($subtabs as $key => $label) {
    $active = $tab === $key ? 'active' : '';
    echo "<li class='nav-item'><a class='nav-link $active' href='" . Html::cleanInputText($base . '?tab=' . $key) . "'>$label</a></li>";
}
echo "</ul>";

if (!$available) {
    echo "<div class='alert alert-warning'>"
        . __('O plugin "Serviços Gerenciados" (managedservices) precisa estar instalado e ativo para exibir os dados financeiros.', 'servicereports')
        . "</div>";
    echo "</div>";
    Html::footer();
    return;
}

if ($tab === 'dashboards') {
    // -------------------------------------------------------------- Dashboards
    echo "<div class='text-center mb-3'>";
    echo "<h2 class='mb-0'>" . __('Dashboard financeiro', 'servicereports') . "</h2>";
    echo "<div class='text-muted'>" . __('Em tempo real', 'servicereports') . "</div>";
    echo "</div>";

    $kpis = PluginServicereportsFinancial::getKpis();
    echo "<div class='row row-cols-1 row-cols-md-3 g-3 mb-3'>";
    foreach ($kpis as $kpi) {
        echo "<div class='col'>";
        echo "<div class='card h-100 shadow-sm'>";
        echo "<div class='card-body d-flex align-items-center'>";
        echo "<i class='" . $kpi['icon'] . " me-3 text-" . $kpi['accent'] . "' style='font-size:2rem'></i>";
        echo "<div class='flex-grow-1' style='min-width:0'>";
        echo "<div class='text-muted text-uppercase' style='font-size:.72rem'>" . $kpi['title'] . "</div>";
        echo "<div class='h3 mb-0'>" . $kpi['value'] . "</div>";
        echo "</div></div></div></div>";
    }
    echo "</div>";

    echo "<div class='row row-cols-1 row-cols-lg-2 g-3'>";
    echo "<div class='col'>";
    PluginServicereportsFinancial::renderBarChart(
        PluginServicereportsFinancial::getRevenueByEntity(),
        __('Top 10 receitas previstas por entidade', 'servicereports')
    );
    echo "</div>";
    echo "<div class='col'>";
    PluginServicereportsFinancial::renderBarChart(
        PluginServicereportsFinancial::getAvgByAssetType(),
        __('Top 10 valor médio por tipo de ativo', 'servicereports')
    );
    echo "</div>";
    echo "</div>";
} else {
    // -------------------------------------------------------------- Relatórios
    $reports = [
        0 => __('---', 'servicereports'),
        1 => __('Extrato financeiro', 'servicereports'),
        2 => __('Faturamento financeiro', 'servicereports'),
        4 => __('Fatura de serviços detalhada', 'servicereports'),
    ];

    echo "<form method='get' action='" . Html::cleanInputText($base) . "' class='row g-2 align-items-end mb-3'>";
    echo Html::hidden('tab', ['value' => 'relatorios']);
    echo "<div class='col-auto'>";
    Dropdown::showFromArray('report', $reports, [
        'value'     => $report,
        'width'     => '300px',
        'on_change' => 'this.form.submit()',
    ]);
    echo "</div>";
    if ($report !== 0) {
        echo "<div class='col-auto'><label class='form-label mb-0'>" . __('De', 'servicereports') . "</label>";
        Html::showDateField('start_date', ['value' => $start]);
        echo "</div>";
        echo "<div class='col-auto'><label class='form-label mb-0'>" . __('Até', 'servicereports') . "</label>";
        Html::showDateField('end_date', ['value' => $end]);
        echo "</div>";
        echo "<div class='col-auto'>" . Html::submit(__('Filtrar', 'servicereports'), ['class' => 'btn btn-primary']) . "</div>";
    }
    echo "</form>";

    if ($report === 0) {
        echo "<div class='text-center text-muted py-5'>";
        echo "<i class='ti ti-clipboard-list' style='font-size:3rem;opacity:.4'></i>";
        echo "<div class='mt-2'>" . __('Nenhum relatório selecionado', 'servicereports') . "</div>";
        echo "</div>";
    } elseif ($report === 1 || $report === 4) {
        // Listagem paginada de 10 em 10 serviços (CSV/PDF continuam completos).
        $extrato     = PluginServicereportsFinancial::getExtrato($startDt, $endDt);
        $totalSvc    = PluginServicereportsFinancial::countServices($extrato);
        $perPage     = PluginServicereportsPager::PER_PAGE;
        $offset      = PluginServicereportsPager::offset($totalSvc);
        $page        = PluginServicereportsFinancial::sliceExtrato($extrato, $offset, $perPage);
        $pagerParams = ['tab' => 'relatorios', 'report' => $report, 'start_date' => $start, 'end_date' => $end];

        PluginServicereportsPager::show($base, $pagerParams, $offset, $totalSvc);
        if ($report === 1) {
            PluginServicereportsFinancial::renderExtrato(
                $page,
                $url(['export' => 'csv']),
                $url(['pdf' => '1'])
            );
        } else {
            PluginServicereportsFinancial::renderFaturaDetalhada($page, $start, $end, $url(['pdf' => '1']));
        }
        PluginServicereportsPager::show($base, $pagerParams, $offset, $totalSvc);
    } elseif ($report === 2) {
        PluginServicereportsFinancial::renderFaturamento($start, $end, $startDt, $endDt, $url(['export' => 'csv']));
    } else {
        echo "<div class='alert alert-info'>" . __('Relatório não disponível.', 'servicereports') . "</div>";
    }
}

echo "</div>";

Html::footer();
