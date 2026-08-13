<?php
/**
 * Aba "Composição do Serviço": ativos que compõem o serviço, com impacto.
 * Tabela: glpi_plugin_managedservices_compositions
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginManagedservicesComposition extends CommonDBTM
{
    public static $rightname = 'plugin_managedservices';

    public const FK = 'plugin_managedservices_managedservices_id';

    public const IMPACT_PARTIAL = 1;
    public const IMPACT_TOTAL   = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Composição do serviço', 'Composições do serviço', $nb, 'managedservices');
    }

    /**
     * @return array<int,string>
     */
    public static function getImpacts()
    {
        return [
            self::IMPACT_PARTIAL => __('Parcial', 'managedservices'),
            self::IMPACT_TOTAL   => __('Total', 'managedservices'),
        ];
    }

    public static function getImpactName($impact)
    {
        $impacts = self::getImpacts();
        return $impacts[(int) $impact] ?? '';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof PluginManagedservicesManagedservice) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs'] ?? 0) {
                $nb = countElementsInTable(self::getTable(), [self::FK => $item->getID()]);
            }
            return self::createTabEntry(__('Composição do Serviço', 'managedservices'), $nb);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof PluginManagedservicesManagedservice) {
            self::showForService($item);
        }
        return true;
    }

    public static function showForService(PluginManagedservicesManagedservice $service)
    {
        global $DB;

        $sid     = (int) $service->getID();
        $canedit = $service->canUpdateItem();

        if ($canedit) {
            echo "<div class='spaced'>";
            echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
            echo Html::hidden(self::FK, ['value' => $sid]);
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='4'>" . __('Adicionar ativo que compõe o serviço', 'managedservices') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Classe de ativo', 'managedservices') . " / " . _n('Item', 'Items', 1) . "</td>";
            echo "<td>";
            Dropdown::showSelectItemFromItemtypes([
                'itemtypes'       => PluginManagedservicesManagedservice::getAssetItemtypes(),
                'entity_restrict' => $service->fields['entities_id'],
                'checkright'      => true,
            ]);
            echo "</td>";
            echo "<td>" . __('Impacto', 'managedservices') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('impact', self::getImpacts(), ['value' => self::IMPACT_TOTAL]);
            echo "</td>";
            echo "</tr>";
            echo "<tr class='tab_bg_2'><td colspan='4' class='center'>";
            echo Html::submit(_sx('button', 'Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        $rows  = $DB->request(['FROM' => self::getTable(), 'WHERE' => [self::FK => $sid], 'ORDER' => 'itemtype']);
        $count = count($rows);

        echo "<div class='spaced'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='4'>" . __('Listagem de ativos que compõem o serviço', 'managedservices') . " - " . $count . "</th></tr>";
        if ($count) {
            echo "<tr class='noHover'>";
            echo "<th>" . _n('Type', 'Types', 1) . "</th>";
            echo "<th>" . _n('Item', 'Items', 1) . "</th>";
            echo "<th>" . __('Impacto', 'managedservices') . "</th>";
            echo "<th>" . _n('Action', 'Actions', 1) . "</th>";
            echo "</tr>";
            foreach ($rows as $row) {
                $itemtype = $row['itemtype'];
                $typename = class_exists($itemtype) ? $itemtype::getTypeName(1) : $itemtype;
                echo "<tr class='tab_bg_1'>";
                echo "<td>" . $typename . "</td>";
                echo "<td>" . self::getItemLink($itemtype, (int) $row['items_id']) . "</td>";
                echo "<td>" . self::getImpactName($row['impact']) . "</td>";
                echo "<td>";
                if ($canedit) {
                    echo "<form method='post' style='display:inline' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo Html::hidden(self::FK, ['value' => $sid]);
                    echo Html::submit(_x('button', 'Delete permanently'), ['name' => 'purge', 'class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('Confirm the final deletion?')]);
                    Html::closeForm();
                }
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr class='tab_bg_1'><td colspan='4' class='center'>" . __('No item found') . "</td></tr>";
        }
        echo "</table>";
        echo "</div>";
    }

    protected static function getItemLink(string $itemtype, int $items_id)
    {
        if (!class_exists($itemtype)) {
            return "$itemtype #$items_id";
        }
        $obj = new $itemtype();
        if ($obj->getFromDB($items_id)) {
            return $obj->getLink();
        }
        return "#$items_id";
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $fk = self::FK;
            $query = "CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `$fk` int unsigned NOT NULL DEFAULT 0,
                `itemtype` varchar(100) DEFAULT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `impact` tinyint NOT NULL DEFAULT 2,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `$fk` (`$fk`),
                KEY `item` (`itemtype`,`items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
            $DB->doQuery($query);
        }
    }

    public static function uninstall(Migration $migration)
    {
        global $DB;
        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }
}
