<?php
/**
 * Bloco: Gestão financeira — dashboard (lê dados do plugin managedservices).
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_servicereports', READ);

Html::header(
    __('Gestão financeira', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

echo "<div class='container-fluid mt-3'>";
echo "<div class='text-center mb-3'>";
echo "<h2 class='mb-0'>" . __('Dashboard financeiro', 'servicereports') . "</h2>";
echo "<div class='text-muted'>" . __('Em tempo real', 'servicereports') . "</div>";
echo "</div>";

if (!PluginServicereportsFinancial::isAvailable()) {
    echo "<div class='alert alert-warning'>"
        . __('O plugin "Serviços Gerenciados" (managedservices) precisa estar instalado e ativo para exibir os dados financeiros.', 'servicereports')
        . "</div>";
    echo "</div>";
    Html::footer();
    return;
}

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

echo "</div>";

Html::footer();
