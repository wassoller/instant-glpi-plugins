<?php
/**
 * Bloco: Central de serviços.
 *
 * Sub-abas:
 *   - Dashboard   → KPIs do mês corrente (cartões com deep-link para a busca).
 *   - Relatórios  → seletor de relatório + filtro de período. Cinco relatórios,
 *                   todos com CSV e PDF (os três primeiros com 7 seções):
 *                     1 "Relatório central de serviços"
 *                     2 "Relatório de atualização - Cliente - ANUAL"  (por mês)
 *                     3 "Relatório de atualização - Cliente - MENSAL" (por dia)
 *                     4 "Chamados por grupo" (grupo atribuído; capa, gráfico e tabela)
 *                     5 "Chamados por entidade" (o gráfico do relatório 61 com
 *                       entidade no eixo X; capa, gráfico e tabela)
 *
 * Os relatórios 2 e 3 são a **mesma** implementação com granularidades
 * diferentes (PluginServicereportsUpdatereport::GRAIN_MONTH / GRAIN_DAY).
 */

include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight(PluginServicereportsMenu::RIGHT_CENTRAL, READ);

$base   = $CFG_GLPI['root_doc'] . '/plugins/servicereports/front/servicecentral.php';
$tab    = ($_GET['tab'] ?? 'dashboard') === 'relatorios' ? 'relatorios' : 'dashboard';
$report = (int) ($_GET['report'] ?? 0);

// Granularidade dos relatórios de atualização (2 = ANUAL/mês, 3 = MENSAL/dia).
$grain = $report === 3
    ? PluginServicereportsUpdatereport::GRAIN_DAY
    : PluginServicereportsUpdatereport::GRAIN_MONTH;

// O ANUAL já abre com os últimos 12 meses; os outros, no mês corrente. Trocar
// de relatório no seletor não envia datas, então cai sempre no padrão certo.
$defaultStart = $report === 2 ? date('Y-m-01', strtotime('-11 months')) : date('Y-m-01');
$start  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'] ?? '') ? $_GET['start_date'] : $defaultStart;
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

if ($tab === 'relatorios' && in_array($report, [2, 3], true) && ($_GET['export'] ?? '') === 'csv') {
    $d = PluginServicereportsUpdatereport::getReport($startDt, $endDt, $grain);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_de_atualizacao_'
        . PluginServicereportsUpdatereport::slug($grain) . '_' . $start . '_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
    $dec = static fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $put = static function (array $line) use ($out, $dec) {
        fputcsv($out, array_map($dec, $line), ';', '"', '');
    };

    $put([$d['title']]);
    $put(['Cliente', $d['client']]);
    $put(['Período', $periodLabel]);
    $put(['Chamados abertos', $d['total_open']]);
    $put(['Chamados fechados', $d['total_closed']]);
    $put([]);

    $put(['Relatório de atendimentos']);
    $put([sprintf(__('Total de chamados %s', 'servicereports'), $d['status_period']), $d['total_open']]);
    foreach ($d['by_status'] as $r) {
        $put([$r['label'], $r['value']]);
    }
    $put([]);

    $put([$d['series_titles']['types']]);
    $put([$d['bucket_label'], 'Incidente', 'Requisição', 'Total']);
    foreach ($d['buckets'] as $key => $label) {
        $inc = (int) ($d['by_type'][$key]['inc'] ?? 0);
        $req = (int) ($d['by_type'][$key]['req'] ?? 0);
        $put([$label, $inc, $req, $inc + $req]);
    }
    $put(['Total', $d['types']['inc'], $d['types']['req'], $d['types']['inc'] + $d['types']['req']]);
    $put([]);

    $put(['Top 5 - Chamados por categoria']);
    $put(['Categoria', 'Chamados', '% do total']);
    foreach ($d['categories']['rows'] as $r) {
        $put([$r['label'], $r['value'], $r['note']]);
    }
    $put(['TOTAL GERAL', $d['categories']['total'], '']);
    $put([]);

    $put([$d['series_titles']['flow']]);
    $put([$d['bucket_label'], 'Aberto', 'Fechado']);
    foreach ($d['buckets'] as $key => $label) {
        $put([$label, $d['opened'][$key] ?? 0, $d['closed'][$key] ?? 0]);
    }
    $put(['Total', $d['total_open'], $d['total_closed']]);
    $put([]);

    $put(['Chamados por horário']);
    $put(['Hora', 'Chamados']);
    foreach ($d['hours'] as $h) {
        $put([$h['label'], $h['value']]);
    }
    fclose($out);
    exit;
}

// Relatório 4 — "Chamados por grupo": cabeçalho com os números do período e a
// tabela grupo × chamados, separados por linha em branco.
if ($tab === 'relatorios' && $report === 4 && ($_GET['export'] ?? '') === 'csv') {
    $d = PluginServicereportsGroupreport::getReport($startDt, $endDt);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chamados_por_grupo_' . $start . '_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
    $dec = static fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $put = static function (array $line) use ($out, $dec) {
        fputcsv($out, array_map($dec, $line), ';', '"', '');
    };

    $put([PluginServicereportsGroupreport::title()]);
    $put(['Cliente', $d['client']]);
    $put(['Período', $periodLabel]);
    $put(['Chamados no período', (int) $d['total_tickets']]);
    $put(['Grupos com chamado', (int) $d['groups']]);
    $put(['Sem grupo atribuído', (int) $d['no_group']]);
    $put([]);

    $put(['Grupo', 'Chamados', '% do total']);
    foreach ($d['rows'] as $row) {
        $put([$row['label'], (int) $row['value'], $row['note']]);
    }
    $put(['Total', (int) $d['total_links'], $d['total_links'] > 0 ? '100,00%' : '']);
    fclose($out);
    exit;
}

// Relatório 5 — "Chamados por entidade": cabeçalho com os números do período e
// a matriz entidade × status (a mesma tabela da tela).
if ($tab === 'relatorios' && $report === 5 && ($_GET['export'] ?? '') === 'csv') {
    $d = PluginServicereportsEntityreport::getReport($startDt, $endDt);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chamados_por_entidade_' . $start . '_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
    $dec = static fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $put = static function (array $line) use ($out, $dec) {
        fputcsv($out, array_map($dec, $line), ';', '"', '');
    };

    $put([PluginServicereportsEntityreport::title()]);
    $put(['Cliente', $d['client']]);
    $put(['Período', $periodLabel]);
    $put(['Chamados no período', (int) $d['grand']]);
    $put(['Entidades com chamado', (int) $d['entities']]);
    $put([]);

    $header = ['Entidade'];
    foreach ($d['keys'] as $k) {
        $header[] = $d['labels'][$k];
    }
    $header[] = 'Total';
    $put($header);
    foreach ($d['rows'] as $row) {
        $line = [$row['fullname']];
        foreach ($d['keys'] as $k) {
            $line[] = (int) ($row['counts'][$k] ?? 0);
        }
        $line[] = (int) $row['total'];
        $put($line);
    }
    $line = ['Total'];
    foreach ($d['keys'] as $k) {
        $line[] = (int) ($d['totals'][$k] ?? 0);
    }
    $line[] = (int) $d['grand'];
    $put($line);
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

if ($tab === 'relatorios' && in_array($report, [2, 3], true) && ($_GET['pdf'] ?? '') === '1') {
    $d = PluginServicereportsUpdatereport::getReport($startDt, $endDt, $grain);

    ob_start();
    $bytes = PluginServicereportsUpdatepdf::build($d);
    ob_end_clean();

    $file = 'relatorio_de_atualizacao_' . PluginServicereportsUpdatereport::slug($grain)
        . '_' . $start . '_' . $end . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $file . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

if ($tab === 'relatorios' && $report === 4 && ($_GET['pdf'] ?? '') === '1') {
    $d = PluginServicereportsGroupreport::getReport($startDt, $endDt);

    ob_start();
    $bytes = PluginServicereportsGroupreportpdf::build($d);
    ob_end_clean();

    $file = 'chamados_por_grupo_' . $start . '_' . $end . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $file . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

if ($tab === 'relatorios' && $report === 5 && ($_GET['pdf'] ?? '') === '1') {
    $d = PluginServicereportsEntityreport::getReport($startDt, $endDt);

    ob_start();
    $bytes = PluginServicereportsEntityreportpdf::buildEntity($d, $start, $end, (string) $d['client']);
    ob_end_clean();

    $file = 'chamados_por_entidade_' . $start . '_' . $end . '.pdf';
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
        2 => PluginServicereportsUpdatereport::title(PluginServicereportsUpdatereport::GRAIN_MONTH),
        3 => PluginServicereportsUpdatereport::title(PluginServicereportsUpdatereport::GRAIN_DAY),
        4 => PluginServicereportsGroupreport::title(),
        5 => PluginServicereportsEntityreport::title(),
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

    if (!in_array($report, [1, 2, 3, 4, 5], true)) {
        echo "<div class='alert alert-info'>" . __('Selecione um relatório.', 'servicereports') . "</div>";
    } else {
        $args   = ['tab' => 'relatorios', 'report' => $report, 'start_date' => $start, 'end_date' => $end];
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

        if ($report === 1) {
            $d = PluginServicereportsServicecentral::getReport($startDt, $endDt);

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
        } elseif ($report === 4) {
            // "Chamados por grupo": capa, gráfico de barras horizontais e
            // tabela — as mesmas três páginas do PDF, na mesma ordem.
            $d = PluginServicereportsGroupreport::getReport($startDt, $endDt);

            // 1) Capa / dados do relatório
            echo "<div class='card mb-3'><div class='card-body'>";
            echo "<h2 class='h4 mb-3'>" . PluginServicereportsGroupreport::title() . "</h2>";
            echo "<div class='row g-3'>";
            foreach ([
                [__('Cliente', 'servicereports'), $d['client'] !== '' ? $d['client'] : '-'],
                [__('Chamados no período', 'servicereports'), (string) (int) $d['total_tickets']],
                [__('Grupos com chamado', 'servicereports'), (string) (int) $d['groups']],
                [__('Sem grupo atribuído', 'servicereports'), (string) (int) $d['no_group']],
            ] as [$k, $v]) {
                echo "<div class='col-6 col-md-3'><div class='text-muted text-uppercase' style='font-size:.72rem'>$k</div>"
                    . "<div class='h4 mb-0'>$v</div></div>";
            }
            echo "</div></div></div>";

            // 2) Gráfico
            $section(PluginServicereportsGroupreport::chartTitle(), PluginServicereportsGroupreport::hint());
            PluginServicereportsChart::hbars($d['rows'], __('Chamados', 'servicereports'));
            $endSection();

            // 3) Tabela
            $section(PluginServicereportsGroupreport::tableTitle(), '');
            if (empty($d['rows'])) {
                echo "<div class='alert alert-info'>" . __('Nenhum chamado encontrado no período.', 'servicereports') . "</div>";
            } else {
                echo "<div class='table-responsive'><table class='table table-sm table-hover table-bordered'>";
                echo "<thead><tr><th>" . __('Grupo', 'servicereports') . "</th>"
                    . "<th class='text-center'>" . __('Chamados', 'servicereports') . "</th>"
                    . "<th class='text-center'>" . __('% do total', 'servicereports') . "</th></tr></thead><tbody>";
                foreach ($d['rows'] as $row) {
                    echo "<tr><td>" . $row['label'] . "</td>";
                    echo "<td class='text-center'><strong>" . (int) $row['value'] . "</strong></td>";
                    echo "<td class='text-center text-muted'>" . $row['note'] . "</td></tr>";
                }
                // O total é a SOMA das linhas: chamado em dois grupos conta nas
                // duas, então ele pode passar os chamados do período (que ficam
                // na capa). O percentual é sobre essa soma — daí os 100%.
                echo "</tbody><tfoot><tr><th>" . __('Total', 'servicereports') . "</th>";
                echo "<th class='text-center'>" . (int) $d['total_links'] . "</th>";
                echo "<th class='text-center'>" . ($d['total_links'] > 0 ? '100,00%' : '') . "</th>";
                echo "</tr></tfoot></table></div>";
            }
            $endSection();
        } elseif ($report === 5) {
            // "Chamados por entidade": o gráfico do relatório 61 com entidade
            // no eixo X. O SVG, o CSS e o tooltip vêm de
            // PluginServicereportsAnalysts (renderStackedChart/stackedAssets),
            // e não daqui — os dois relatórios têm de continuar iguais.
            $d = PluginServicereportsEntityreport::getReport($startDt, $endDt);

            // 1) Capa / dados do relatório
            echo "<div class='card mb-3'><div class='card-body'>";
            echo "<h2 class='h4 mb-3'>" . PluginServicereportsEntityreport::title() . "</h2>";
            echo "<div class='row g-3'>";
            foreach ([
                [__('Cliente', 'servicereports'), $d['client'] !== '' ? $d['client'] : '-'],
                [__('Chamados no período', 'servicereports'), (string) (int) $d['grand']],
                [__('Entidades com chamado', 'servicereports'), (string) (int) $d['entities']],
                [__('Período', 'servicereports'), $periodLabel],
            ] as [$k, $v]) {
                echo "<div class='col-6 col-md-3'><div class='text-muted text-uppercase' style='font-size:.72rem'>$k</div>"
                    . "<div class='h4 mb-0'>$v</div></div>";
            }
            echo "</div></div></div>";

            // 2) Gráfico
            $section(PluginServicereportsEntityreport::title(), PluginServicereportsEntityreport::hint());
            PluginServicereportsAnalysts::renderStackedChart($d, PluginServicereportsEntityreport::title());
            $endSection();

            // 3) Tabela
            $section(__('Detalhamento por entidade', 'servicereports'), '');
            if (empty($d['rows'])) {
                echo "<div class='alert alert-info'>" . __('Nenhum chamado encontrado no período.', 'servicereports') . "</div>";
            } else {
                echo "<div class='table-responsive'><table class='table table-sm table-hover table-bordered sr-cst-table'>";
                echo "<thead><tr><th>" . __('Entidade', 'servicereports') . "</th>";
                foreach ($d['keys'] as $k) {
                    echo "<th class='text-center'><span class='sr-cst-dot' style='background:"
                        . $d['colors'][$k] . "'></span>" . $d['labels'][$k] . "</th>";
                }
                echo "<th class='text-center'>" . __('Total', 'servicereports') . "</th></tr></thead><tbody>";
                foreach ($d['rows'] as $row) {
                    echo "<tr><td>" . $row['fullname'] . "</td>";
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
            }
            $endSection();
        } else {
            $d = PluginServicereportsUpdatereport::getReport($startDt, $endDt, $grain);

            // Estilo próprio da capa e da tabela de status. Cores explícitas
            // (e não variáveis do tema): a faixa é escura nos dois temas do
            // GLPI, então o texto tem de ser claro em qualquer um.
            echo "<style>
                .sr-uc { border-radius: .5rem; overflow: hidden; }
                .sr-uc-band { background: #223140; color: #fff; padding: 1.75rem 2rem 1.5rem; }
                .sr-uc-kicker { color: #4bb3d4; font-size: .72rem; font-weight: 600;
                                letter-spacing: .14em; text-transform: uppercase; }
                .sr-uc-title { font-size: 1.7rem; font-weight: 700; line-height: 1.2; margin: .35rem 0 .75rem; }
                .sr-uc-rule { width: 46px; height: 3px; background: #4bb3d4; border-radius: 2px; }
                .sr-uc-client { font-size: 1.05rem; color: #e2e9f0; margin-top: .75rem; }
                .sr-uc-body { background: #fff; color: #16202a; padding: 1.25rem 2rem 1.5rem; }
                .sr-uc-lbl { font-size: .68rem; letter-spacing: .08em; text-transform: uppercase; color: #8b98a5; }
                .sr-uc-period { font-size: 1.05rem; font-weight: 600; }
                .sr-uc-stats { display: flex; flex-wrap: wrap; border-top: 1px solid #d8dee4;
                               margin-top: 1rem; padding-top: 1rem; }
                .sr-uc-stat { flex: 1 1 140px; padding: 0 1.25rem; }
                .sr-uc-stat + .sr-uc-stat { border-left: 1px solid #d8dee4; }
                .sr-uc-stat:first-child { padding-left: 0; }
                .sr-uc-num { font-size: 1.9rem; font-weight: 700; line-height: 1.1; color: #223140; }
                .sr-us { max-width: 520px; border-collapse: collapse; width: 100%; }
                .sr-us th, .sr-us td { padding: .4rem .75rem; }
                .sr-us thead th { background: #223140; color: #fff; font-weight: 700; }
                .sr-us tbody tr:nth-child(odd) { background: #e9eef4; }
                .sr-us tbody td { color: #16202a; }
                .sr-us .sr-us-n { text-align: right; white-space: nowrap; }
            </style>";

            // 1) Capa
            echo "<div class='card mb-3 sr-uc'>";
            echo "<div class='sr-uc-band'>";
            echo "<div class='sr-uc-kicker'>" . __('Relatório', 'servicereports') . "</div>";
            echo "<div class='sr-uc-title'>" . $d['title'] . "</div>";
            echo "<div class='sr-uc-rule'></div>";
            echo "<div class='sr-uc-client'>" . ($d['client'] !== '' ? $d['client'] : '-') . "</div>";
            echo "</div>";
            echo "<div class='sr-uc-body'>";
            echo "<div class='sr-uc-lbl'>" . __('Período', 'servicereports') . "</div>";
            echo "<div class='sr-uc-period'>" . $periodLabel . "</div>";
            echo "<div class='sr-uc-stats'>";
            foreach ([
                [(int) $d['total_open'], __('Chamados abertos', 'servicereports')],
                [(int) $d['total_closed'], __('Chamados fechados', 'servicereports')],
                [(int) $d['types']['inc'], __('Incidentes', 'servicereports')],
                [(int) $d['types']['req'], __('Requisições', 'servicereports')],
            ] as [$num, $lbl]) {
                echo "<div class='sr-uc-stat'><div class='sr-uc-num'>$num</div>"
                    . "<div class='sr-uc-lbl'>$lbl</div></div>";
            }
            echo "</div></div></div>";

            // 2) Relatório de atendimentos — total por status + legenda
            $section(
                __('Relatório de atendimentos', 'servicereports'),
                __('Os chamados abertos no período pelo status em que estão agora, e o que cada status '
                 . 'significa no acompanhamento do atendimento.', 'servicereports')
            );
            echo "<table class='sr-us mb-4'><thead><tr>"
                . "<th>" . mb_strtoupper(sprintf(__('Total de chamados %s', 'servicereports'), $d['status_period']), 'UTF-8') . "</th>"
                . "<th class='sr-us-n'>" . (int) $d['total_open'] . "</th></tr></thead><tbody>";
            foreach ($d['by_status'] as $r) {
                echo "<tr><td>" . $r['label'] . "</td><td class='sr-us-n'>" . (int) $r['value'] . "</td></tr>";
            }
            echo "</tbody></table>";
            echo "<dl class='row mb-0'>";
            foreach ($d['statuses'] as [$name, $desc]) {
                echo "<dt class='col-12 col-sm-3 col-lg-2'>" . $name . "</dt>";
                echo "<dd class='col-12 col-sm-9 col-lg-10 text-muted'>" . $desc . "</dd>";
            }
            echo "</dl>";
            $endSection();

            // 3) Chamados por mês/dia, por tipo
            $bucketLabels = array_values($d['buckets']);
            $incData      = [];
            $reqData      = [];
            foreach (array_keys($d['buckets']) as $k) {
                $incData[] = (int) ($d['by_type'][$k]['inc'] ?? 0);
                $reqData[] = (int) ($d['by_type'][$k]['req'] ?? 0);
            }
            $section(
                $d['series_titles']['types'],
                __('Chamados abertos no período, separados por tipo.', 'servicereports')
            );
            PluginServicereportsChart::bars($bucketLabels, [
                ['name' => __('Incidente', 'servicereports'), 'color' => PluginServicereportsChart::NAVY, 'data' => $incData],
                ['name' => __('Requisição', 'servicereports'), 'color' => PluginServicereportsChart::STEEL, 'data' => $reqData],
            ]);
            $endSection();

            // 4) Chamados por tipo — tabela do bucket + rosca
            $section(
                __('Chamados por tipo', 'servicereports'),
                __('Incidente e Requisição são os dois tipos de chamado do GLPI; o total é o de chamados '
                 . 'abertos no período.', 'servicereports')
            );
            echo "<div class='row g-3 align-items-center'>";
            echo "<div class='col-12 col-lg-5'>";
            echo "<div style='max-height:420px;overflow-y:auto'>";
            echo "<table class='table table-sm table-striped mb-0' style='max-width:420px'>";
            echo "<thead><tr><th>" . $d['bucket_label'] . "</th>"
                . "<th class='text-center'>" . __('INC', 'servicereports') . "</th>"
                . "<th class='text-center'>" . __('REQ', 'servicereports') . "</th></tr></thead><tbody>";
            foreach ($d['buckets'] as $key => $label) {
                echo "<tr><td>" . $label . "</td>"
                    . "<td class='text-center'>" . (int) ($d['by_type'][$key]['inc'] ?? 0) . "</td>"
                    . "<td class='text-center'>" . (int) ($d['by_type'][$key]['req'] ?? 0) . "</td></tr>";
            }
            echo "</tbody><tfoot><tr><th>" . __('Total', 'servicereports') . "</th>"
                . "<th class='text-center'>" . (int) $d['types']['inc'] . "</th>"
                . "<th class='text-center'>" . (int) $d['types']['req'] . "</th></tr></tfoot></table>";
            echo "</div></div>";
            echo "<div class='col-12 col-lg-7'>";
            PluginServicereportsChart::donut([
                ['label' => __('Incidente', 'servicereports'), 'value' => (int) $d['types']['inc'], 'color' => PluginServicereportsChart::NAVY],
                ['label' => __('Requisição', 'servicereports'), 'value' => (int) $d['types']['req'], 'color' => PluginServicereportsChart::STEEL],
            ]);
            echo "</div></div>";
            $endSection();

            // 5) Top 5 chamados por categoria
            $section(
                __('Top 5 - Chamados por categoria', 'servicereports'),
                sprintf(
                    __('As 5 categorias com mais chamados abertos no período (de %s no total).', 'servicereports'),
                    (int) $d['categories']['total']
                )
            );
            PluginServicereportsChart::hbars($d['categories']['rows'], __('Chamados abertos', 'servicereports'));
            $endSection();

            // 6) Abertos × Fechados por mês/dia
            $section(
                $d['series_titles']['flow'],
                __('Abertos pela data de abertura e fechados pela data de fechamento (status Fechado; '
                 . 'chamado só Solucionado ainda não conta).', 'servicereports')
            );
            PluginServicereportsChart::bars($bucketLabels, [
                ['name' => __('Aberto', 'servicereports'), 'color' => PluginServicereportsChart::NAVY, 'data' => array_values($d['opened'])],
                ['name' => __('Fechado', 'servicereports'), 'color' => PluginServicereportsChart::STEEL, 'data' => array_values($d['closed'])],
            ]);
            $endSection();

            // 7) Chamados por horário
            $hourLabels = [];
            $hourData   = [];
            foreach ($d['hours'] as $h) {
                $hourLabels[] = $h['label'];
                $hourData[]   = (int) $h['value'];
            }
            $section(
                __('Chamados por horário', 'servicereports'),
                __('Chamados abertos por hora do dia, pela hora de abertura. Só as horas com pelo menos um '
                 . 'chamado aparecem.', 'servicereports')
            );
            PluginServicereportsChart::bars($hourLabels, [
                ['name' => __('Chamados abertos', 'servicereports'), 'color' => PluginServicereportsChart::NAVY, 'data' => $hourData],
            ]);
            $endSection();
        }

        echo "<div class='mt-2' style='font-size:0.9em'>" . __('Período do relatório', 'servicereports')
            . ': <strong>' . $periodLabel . '</strong></div>';
    }
}

echo "</div>";

Html::footer();
