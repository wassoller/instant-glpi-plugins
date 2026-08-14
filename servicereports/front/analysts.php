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
$renderFilter = function (string $tab) use ($base, $start, $end, $techId, $startDt, $endDt) {
    $techs = PluginServicereportsAnalysts::getTechnicians($startDt, $endDt);
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
    Dropdown::showFromArray('technician_id', [0 => __('Todos', 'servicereports')] + $techs, ['value' => $techId, 'width' => '220px']);
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

    if ($report === 57) {
        $data = PluginServicereportsAnalysts::getTasksReport($startDt, $endDt, $techId, $ticketId);
        echo "<h3 class='mb-2'>" . __('Tarefas por técnico', 'servicereports') . "</h3>";
        echo "<div class='mb-3 text-muted'>";
        echo __('Total de tarefas', 'servicereports') . ": <strong>" . $data['total_tasks'] . "</strong> &middot; ";
        echo __('Tempo total de tarefas', 'servicereports') . ": <strong>" . PluginServicereportsAnalysts::secToHms($data['total_time']) . "</strong>";
        echo "</div>";
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
            echo "<td>" . (int) $r['tickets_id'] . "</td>";
            echo "<td>" . getUserName((int) $r['author']) . "</td>";
            echo "<td>" . Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']) . "</td>";
            echo "<td>" . Html::convDateTime($r['task_date']) . "</td>";
            echo "<td>" . ((int) $r['taskcategories_id'] ? Dropdown::getDropdownName('glpi_taskcategories', (int) $r['taskcategories_id']) : '-') . "</td>";
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
    } elseif ($report === 58) {
        echo "<h3 class='mb-2'>" . __('Deslocamentos por técnico', 'servicereports') . "</h3>";
        echo "<div class='alert alert-secondary'>"
            . __('Distância total de deslocamentos: 0 Km · Tempo total: 00:00:00', 'servicereports') . "<br>"
            . __('Este relatório depende de uma fonte de dados de deslocamento (não nativa do GLPI). Sem registros disponíveis.', 'servicereports')
            . "</div>";
    } elseif ($report === 59) {
        $rows = PluginServicereportsAnalysts::getOutOfHoursReport($startDt, $endDt, $techId, $ticketId);
        echo "<h3 class='mb-2'>" . __('Horas fora de expediente de técnicos por chamado', 'servicereports') . "</h3>";
        echo "<div class='text-muted mb-2'>" . __('Expediente considerado: Seg–Sex, 08:00–18:00.', 'servicereports') . "</div>";
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
