<?php
/**
 * Bloco: Central de serviços.
 *
 * Sub-abas:
 *   - Dashboard   → KPIs do mês corrente (cartões com deep-link para a busca).
 *   - Relatórios  → seletor de relatório + filtro de período. Hoje só o
 *                   "Relatório central de serviços" (7 seções), com CSV e PDF.
 */

include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight('plugin_servicereports', READ);

$base   = $CFG_GLPI['root_doc'] . '/plugins/servicereports/front/servicecentral.php';
$tab    = ($_GET['tab'] ?? 'dashboard') === 'relatorios' ? 'relatorios' : 'dashboard';
$report = (int) ($_GET['report'] ?? 0);
$start  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'] ?? '') ? $_GET['start_date'] : date('Y-m-01');
$end    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'] ?? '') ? $_GET['end_date'] : date('Y-m-d');
$startDt = $start . ' 00:00:00';
$endDt   = $end . ' 23:59:59';
$periodLabel = date('d/m/Y', strtotime($start)) . ' ' . __('a', 'servicereports') . ' ' . date('d/m/Y', strtotime($end));

// ---------------------------------------------------------------------------
// Exportação CSV — antes de qualquer saída HTML.
//
// Um arquivo por relatório, com as seções separadas por linha em branco (o
// original oferece um CSV por gráfico; aqui um só arquivo evita sete botões).
// ---------------------------------------------------------------------------
if ($tab === 'relatorios' && $report === 1 && ($_GET['export'] ?? '') === 'csv') {
    $d = PluginServicereportsServicecentral::getReport($startDt, $endDt);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="central_de_servicos_' . $start . '_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
    $dec = static fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $put = static function (array $line) use ($out, $dec) {
        fputcsv($out, array_map($dec, $line), ';', '"', '');
    };

    $put(['Relatório central de serviços']);
    $put(['Cliente', $d['client']]);
    $put(['Período', $periodLabel]);
    $put(['Total de chamados abertos', $d['total_open']]);
    $put([]);

    $put(['Atendimento diário']);
    $put(['Dia', 'Abertos', 'Encerrados', 'Fora do SLA de atendimento', 'Fora do SLA de solução']);
    foreach ($d['days'] as $iso => $label) {
        $put([$label, $d['opened'][$iso] ?? 0, $d['solved'][$iso] ?? 0, $d['late_tto'][$iso] ?? 0, $d['late_ttr'][$iso] ?? 0]);
    }
    $put(['Total', array_sum($d['opened']), array_sum($d['solved']), array_sum($d['late_tto']), array_sum($d['late_ttr'])]);
    $put([]);

    $put(['Atendimentos por categoria (Top 7)']);
    $put(['Categoria', 'Chamados', '% do total']);
    foreach ($d['categories']['rows'] as $r) {
        $put([$r['label'], $r['value'], $r['note']]);
    }
    $put(['TOTAL GERAL', $d['categories']['total'], '']);
    $put([]);

    $put(['Atendimento SLA (nível de serviço)']);
    $put(['Dentro do prazo', $d['sla']['dentro']]);
    $put(['Fora do prazo', $d['sla']['fora']]);
    $put(['Total', $d['sla']['total']]);
    $put([]);

    $put(['Top usuários requerentes']);
    $put(['Usuário', 'Chamados']);
    foreach ($d['requesters']['rows'] as $r) {
        $put([$r['label'], $r['value']]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// PDF (TCPDF) — antes de qualquer saída HTML.
//
// `ob_start()`/`ob_end_clean()` obrigatórios: um aviso do PHP impresso durante
// a montagem entraria **dentro** do binário e o arquivo não abriria (o TCPDF
// dispara um "Deprecated: imagedestroy()" no PHP 8.5). Ver extratopdf.class.php.
// ---------------------------------------------------------------------------
if ($tab === 'relatorios' && $report === 1 && ($_GET['pdf'] ?? '') === '1') {
    $d = PluginServicereportsServicecentral::getReport($startDt, $endDt);

    ob_start();
    $bytes = PluginServicereportsCentralpdf::build($d);
    ob_end_clean();

    $file = 'central_de_servicos_' . $start . '_' . $end . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $file . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

// ---------------------------------------------------------------------------
// Página normal.
// ---------------------------------------------------------------------------
Html::header(
    __('Central de serviços', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

echo "<div class='container-fluid mt-3'>";

$subtabs = [
    'dashboard'  => __('Dashboard', 'servicereports'),
    'relatorios' => __('Relatórios', 'servicereports'),
];
echo "<ul class='nav nav-tabs mb-3'>";
foreach ($subtabs as $key => $label) {
    $active = $tab === $key ? 'active' : '';
    echo "<li class='nav-item'><a class='nav-link $active' href='" . Html::cleanInputText($base . '?tab=' . $key) . "'>$label</a></li>";
}
echo "</ul>";

if ($tab === 'dashboard') {
    $kpis = PluginServicereportsServicecentral::getKpis();

    echo "<div class='text-center mb-3'>";
    echo "<h2 class='mb-0'>" . __('Central de Serviços', 'servicereports') . "</h2>";
    echo "<div class='text-muted'>" . __('Dados do mês corrente', 'servicereports') . "</div>";
    echo "</div>";

    echo "<div class='row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3'>";
    foreach ($kpis as $kpi) {
        echo "<div class='col'>";
        echo "<div class='card h-100 shadow-sm'>";
        echo "<div class='card-body d-flex flex-column'>";
        echo "<div class='d-flex align-items-center justify-content-between mb-2'>";
        echo "<div class='text-muted text-uppercase' style='font-size:.75rem'>" . $kpi['title'] . "</div>";
        echo "<i class='" . $kpi['icon'] . "' style='font-size:1.5rem;opacity:.6'></i>";
        echo "</div>";
        echo "<div class='h1 mb-2'>" . $kpi['value'] . "</div>";
        echo "<div class='text-muted mb-3' style='font-size:.85rem;flex-grow:1'>" . $kpi['desc'] . "</div>";
        echo "<a href='" . Html::cleanInputText($kpi['url']) . "' class='btn btn-outline-primary btn-sm'>" . $kpi['btn'] . "</a>";
        echo "</div></div></div>";
    }
    echo "</div>";
} else {
    // --- Seletor de relatório ---
    $reports = [
        0 => __('---', 'servicereports'),
        1 => __('Relatório central de serviços', 'servicereports'),
    ];
    echo "<form method='get' action='" . Html::cleanInputText($base) . "' class='mb-3' id='reportform'>";
    echo Html::hidden('tab', ['value' => 'relatorios']);
    Dropdown::showFromArray('report', $reports, [
        'value'     => $report,
        'width'     => '420px',
        'on_change' => 'this.form.submit()',
    ]);
    echo "</form>";

    // --- Filtro de período ---
    echo "<form method='get' action='" . Html::cleanInputText($base) . "' class='card card-body mb-3'>";
    echo Html::hidden('tab', ['value' => 'relatorios']);
    echo Html::hidden('report', ['value' => $report]);
    echo "<div class='row g-2 align-items-end'>";
    echo "<div class='col-auto'><label class='form-label'>" . __('De', 'servicereports') . "</label>";
    Html::showDateField('start_date', ['value' => $start]);
    echo "</div>";
    echo "<div class='col-auto'><label class='form-label'>" . __('Até', 'servicereports') . "</label>";
    Html::showDateField('end_date', ['value' => $end]);
    echo "</div>";
    echo "<div class='col-auto'>" . Html::submit(__('Filtrar', 'servicereports'), ['class' => 'btn btn-primary']) . "</div>";
    echo "</div></form>";

    if ($report !== 1) {
        echo "<div class='alert alert-info'>" . __('Selecione um relatório.', 'servicereports') . "</div>";
    } else {
        $d = PluginServicereportsServicecentral::getReport($startDt, $endDt);

        $args   = ['tab' => 'relatorios', 'report' => 1, 'start_date' => $start, 'end_date' => $end];
        $csvUrl = $base . '?' . http_build_query($args + ['export' => 'csv']);
        $pdfUrl = $base . '?' . http_build_query($args + ['pdf' => 1]);
        echo "<div class='mb-3 d-flex gap-2'>";
        echo "<a href='" . Html::cleanInputText($csvUrl) . "' class='btn btn-outline-success btn-sm'>"
            . "<i class='ti ti-file-spreadsheet me-1'></i>" . __('Exportar CSV', 'servicereports') . "</a>";
        echo "<a href='" . Html::cleanInputText($pdfUrl) . "' target='_blank' class='btn btn-outline-danger btn-sm'>"
            . "<i class='ti ti-file-type-pdf me-1'></i>" . __('Exportar PDF', 'servicereports') . "</a>";
        echo "</div>";

        // Cada seção da tela é uma página do PDF — mesma ordem, mesmos números.
        $section = static function (string $title, string $hint = '') {
            echo "<div class='card mb-3'><div class='card-body'>";
            echo "<h3 class='h5 mb-1'>" . $title . "</h3>";
            if ($hint !== '') {
                echo "<div class='text-muted mb-3' style='font-size:.85em'>" . $hint . "</div>";
            } else {
                echo "<div class='mb-3'></div>";
            }
        };
        $endSection = static function () {
            echo "</div></div>";
        };

        // 1) Capa / dados do relatório
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h2 class='h4 mb-3'>" . __('Relatório central de serviços', 'servicereports') . "</h2>";
        echo "<div class='row g-3'>";
        foreach ([
            [__('Cliente', 'servicereports'), $d['client'] !== '' ? $d['client'] : '-'],
            [__('Total de chamados abertos', 'servicereports'), (string) $d['total_open']],
            [__('Período', 'servicereports'), $periodLabel],
        ] as [$k, $v]) {
            echo "<div class='col-12 col-md-4'><div class='text-muted text-uppercase' style='font-size:.72rem'>$k</div>"
                . "<div class='h4 mb-0'>$v</div></div>";
        }
        echo "</div></div></div>";

        $labels = array_values($d['days']);

        // 2) Total de atendimento
        $section(__('Total de atendimento', 'servicereports'), __('Chamados abertos por dia, pela data de abertura.', 'servicereports'));
        PluginServicereportsChart::line($labels, array_values($d['opened']), __('Abertos por dia', 'servicereports'));
        $endSection();

        // 3) Atendimento diário
        $section(
            __('Atendimento diário', 'servicereports'),
            __('Abertos pela data de abertura; encerrados pela data de solução — inclui os chamados que já '
             . 'avançaram para Fechado, por isso o total de encerrados pode passar o de abertos.', 'servicereports')
        );
        PluginServicereportsChart::bars($labels, [
            ['name' => __('Abertos', 'servicereports'), 'color' => PluginServicereportsChart::NAVY, 'data' => array_values($d['opened'])],
            ['name' => __('Encerrados', 'servicereports'), 'color' => PluginServicereportsChart::STEEL, 'data' => array_values($d['solved'])],
        ]);
        $endSection();

        // 4) Top categorias
        $section(
            __('Atendimentos por categoria', 'servicereports'),
            sprintf(
                __('As 7 categorias com mais chamados abertos no período (de %s no total).', 'servicereports'),
                (int) $d['categories']['total']
            )
        );
        PluginServicereportsChart::hbars($d['categories']['rows'], __('Chamados abertos', 'servicereports'));
        $endSection();

        // 5) SLA — não conformidade
        $section(
            __('Atendimento SLA — (Não conformidade)', 'servicereports'),
            __('Dos chamados abertos em cada dia: quantos estouraram o prazo para o analista ASSUMIR o chamado '
             . '(SLA de atendimento) e quantos estouraram o prazo de SOLUÇÃO. Chamado sem SLA não entra.', 'servicereports')
        );
        PluginServicereportsChart::bars($labels, [
            ['name' => __('Fora do SLA de atendimento', 'servicereports'), 'color' => PluginServicereportsChart::NAVY, 'data' => array_values($d['late_tto'])],
            ['name' => __('Fora do SLA de solução', 'servicereports'), 'color' => PluginServicereportsChart::STEEL, 'data' => array_values($d['late_ttr'])],
        ]);
        $endSection();

        // 6) SLA — nível de serviço
        $section(
            __('Atendimento SLA — (Nível de serviço)', 'servicereports'),
            __('Chamados abertos no período, pelo prazo de solução. Chamado sem SLA definido conta como dentro do prazo.', 'servicereports')
        );
        PluginServicereportsChart::donut([
            ['label' => __('Dentro do prazo', 'servicereports'), 'value' => (int) $d['sla']['dentro'], 'color' => PluginServicereportsChart::GREEN],
            ['label' => __('Fora do prazo', 'servicereports'), 'value' => (int) $d['sla']['fora'], 'color' => PluginServicereportsChart::RED],
        ]);
        $endSection();

        // 7) Top usuários requerentes
        $section(
            __('Top usuários requerentes', 'servicereports'),
            __('Os 10 usuários com mais chamados abertos no período, pelo ator Requerente.', 'servicereports')
        );
        PluginServicereportsChart::hbars($d['requesters']['rows'], __('Chamados abertos', 'servicereports'));
        $endSection();

        echo "<div class='mt-2' style='font-size:0.9em'>" . __('Período do relatório', 'servicereports')
            . ': <strong>' . $periodLabel . '</strong></div>';
    }
}

echo "</div>";

Html::footer();
