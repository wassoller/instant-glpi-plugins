<?php
/**
 * Lógica do bloco "Gestão financeira": lê os dados financeiros do plugin
 * managedservices (valores historizados) e agrega em KPIs e rankings.
 *
 * "Valor atual" de uma dimensão = registro mais recente (por record_date)
 * de cada (serviço, tipo, ativo, usuário).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsFinancial
{
    private const MS_TABLE  = 'glpi_plugin_managedservices_managedservices';
    private const FV_TABLE  = 'glpi_plugin_managedservices_financialvalues';

    /** O plugin managedservices está instalado? */
    public static function isAvailable(): bool
    {
        global $DB;
        return $DB->tableExists(self::MS_TABLE) && $DB->tableExists(self::FV_TABLE);
    }

    /**
     * Subconsulta com o valor mais recente de cada dimensão financeira,
     * já restrita às entidades ativas (via join ao serviço).
     */
    private static function latestValuesSql(): string
    {
        $ms  = self::MS_TABLE;
        $fv  = self::FV_TABLE;
        $ent = getEntitiesRestrictRequest('AND', $ms);
        return "SELECT fv.*, ms.entities_id AS ms_entity, ms.users_id AS ms_client,
                       ROW_NUMBER() OVER (
                           PARTITION BY fv.plugin_managedservices_managedservices_id, fv.value_type, fv.itemtype, fv.items_id, fv.users_id
                           ORDER BY fv.record_date DESC, fv.id DESC
                       ) AS rn
                FROM `$fv` fv
                INNER JOIN `$ms` ms ON ms.id = fv.plugin_managedservices_managedservices_id
                WHERE 1 $ent";
    }

    private static function scalar(string $sql): float
    {
        global $DB;
        $res = $DB->doQuery($sql);
        $row = $DB->fetchAssoc($res);
        return (float) ($row['v'] ?? 0);
    }

    /**
     * @return array<int,array{title:string,value:string,icon:string,accent:string}>
     */
    public static function getKpis(): array
    {
        global $DB;

        $ms  = self::MS_TABLE;
        $ent = getEntitiesRestrictRequest('AND', $ms);
        $latest = self::latestValuesSql();

        $receitaPrevista = self::scalar("SELECT COALESCE(SUM(value),0) AS v FROM ($latest) l WHERE rn=1");
        $receitaMensal   = self::scalar("SELECT COALESCE(SUM(value),0) AS v FROM ($latest) l WHERE rn=1 AND value_type='monthly'");
        $mediaMensal     = self::scalar("SELECT COALESCE(AVG(value),0) AS v FROM ($latest) l WHERE rn=1 AND value_type='monthly'");
        $receitaAtivos   = self::scalar("SELECT COALESCE(SUM(value),0) AS v FROM ($latest) l WHERE rn=1 AND value_type='perclass'");
        $clientes        = self::scalar("SELECT COUNT(DISTINCT users_id) AS v FROM `$ms` WHERE users_id>0 $ent");
        $servicos        = self::scalar("SELECT COUNT(*) AS v FROM `$ms` WHERE 1 $ent");

        return [
            ['title' => __('Receita prevista', 'servicereports'), 'value' => self::money($receitaPrevista), 'icon' => 'ti ti-trending-up', 'accent' => 'primary'],
            ['title' => __('Receita de ativos cobertos', 'servicereports'), 'value' => self::money($receitaAtivos), 'icon' => 'ti ti-device-desktop', 'accent' => 'primary'],
            ['title' => __('Valor médio mensal dos serviços', 'servicereports'), 'value' => self::money($mediaMensal), 'icon' => 'ti ti-calculator', 'accent' => 'primary'],
            ['title' => __('Receita mensal dos serviços', 'servicereports'), 'value' => self::money($receitaMensal), 'icon' => 'ti ti-cash', 'accent' => 'primary'],
            ['title' => __('Clientes', 'servicereports'), 'value' => (string) (int) $clientes, 'icon' => 'ti ti-users', 'accent' => 'info'],
            ['title' => __('Serviços', 'servicereports'), 'value' => (string) (int) $servicos, 'icon' => 'ti ti-server-cog', 'accent' => 'info'],
        ];
    }

    /**
     * Top 10 receita prevista por entidade.
     *
     * @return array<int,array{label:string,value:float}>
     */
    public static function getRevenueByEntity(): array
    {
        global $DB;
        $latest = self::latestValuesSql();
        $sql = "SELECT ms_entity AS entity, SUM(value) AS total
                FROM ($latest) l WHERE rn=1
                GROUP BY ms_entity ORDER BY total DESC LIMIT 10";
        $out = [];
        foreach ($DB->request($sql) as $row) {
            $out[] = ['label' => Dropdown::getDropdownName('glpi_entities', (int) $row['entity']), 'value' => (float) $row['total']];
        }
        return $out;
    }

    /**
     * Top 10 valor médio por tipo de ativo (dimensão perclass).
     *
     * @return array<int,array{label:string,value:float}>
     */
    public static function getAvgByAssetType(): array
    {
        global $DB;
        $latest = self::latestValuesSql();
        $sql = "SELECT itemtype, AVG(value) AS media
                FROM ($latest) l WHERE rn=1 AND value_type='perclass' AND itemtype IS NOT NULL AND itemtype<>''
                GROUP BY itemtype ORDER BY media DESC LIMIT 10";
        $out = [];
        foreach ($DB->request($sql) as $row) {
            $itemtype = $row['itemtype'];
            $label = (class_exists($itemtype)) ? $itemtype::getTypeName(1) : $itemtype;
            $out[] = ['label' => $label, 'value' => (float) $row['media']];
        }
        return $out;
    }

    public static function money(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    /**
     * Renderiza um gráfico de barras horizontais simples (HTML/CSS, sem JS).
     *
     * @param array<int,array{label:string,value:float}> $data
     */
    public static function renderBarChart(array $data, string $title): void
    {
        echo "<div class='card h-100 shadow-sm'><div class='card-body'>";
        echo "<h3 class='h6 mb-3'>" . $title . "</h3>";
        if (empty($data)) {
            echo "<div class='text-muted text-center py-4'><i class='ti ti-alert-circle'></i> " . __('Sem dados para construir o gráfico.', 'servicereports') . "</div>";
        } else {
            $max = 0.0;
            foreach ($data as $d) {
                $max = max($max, $d['value']);
            }
            $max = $max > 0 ? $max : 1;
            foreach ($data as $d) {
                $pct = round($d['value'] / $max * 100);
                echo "<div class='mb-2'>";
                echo "<div class='d-flex justify-content-between' style='font-size:.8rem'>";
                echo "<span class='text-truncate' style='max-width:60%'>" . $d['label'] . "</span>";
                echo "<span class='text-muted'>" . self::money($d['value']) . "</span>";
                echo "</div>";
                echo "<div class='progress' style='height:10px'>";
                echo "<div class='progress-bar' role='progressbar' style='width:{$pct}%'></div>";
                echo "</div></div>";
            }
        }
        echo "</div></div>";
    }
}
