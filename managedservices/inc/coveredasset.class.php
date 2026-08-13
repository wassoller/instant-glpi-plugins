<?php
/**
 * Aba "Ativos cobertos pelo serviço".
 * Tabela: glpi_plugin_managedservices_coveredassets
 * Ativo (itemtype+items_id) coberto por um serviço, com data de entrada em
 * contrato. Remoção é soft-delete (is_deleted=1 => "Ativos removidos").
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginManagedservicesCoveredasset extends CommonDBTM
{
    public static $rightname = 'plugin_managedservices';

    public const FK = 'plugin_managedservices_managedservices_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Ativo coberto', 'Ativos cobertos', $nb, 'managedservices');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof PluginManagedservicesManagedservice) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs'] ?? 0) {
                $nb = countElementsInTable(self::getTable(), [self::FK => $item->getID(), 'is_deleted' => 0]);
            }
            return self::createTabEntry(__('Ativos cobertos pelo serviço', 'managedservices'), $nb);
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
        $sid     = (int) $service->getID();
        $canedit = $service->canUpdateItem();

        // --- Formulário de adição ---
        if ($canedit) {
            echo "<div class='spaced'>";
            echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
            echo Html::hidden(self::FK, ['value' => $sid]);
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='4'>" . __('Adicionar ativo coberto pelo serviço', 'managedservices') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Classe de ativo', 'managedservices') . " / " . _n('Item', 'Items', 1) . "</td>";
            echo "<td>";
            Dropdown::showSelectItemFromItemtypes([
                'itemtypes'       => PluginManagedservicesManagedservice::getAssetItemtypes(),
                'entity_restrict' => $service->fields['entities_id'],
                'checkright'      => true,
            ]);
            echo "</td>";
            echo "<td>" . __('Data de entrada em contrato', 'managedservices') . "</td>";
            echo "<td>";
            Html::showDateField('contract_entry_date', ['value' => date('Y-m-d')]);
            echo "</td>";
            echo "</tr>";
            echo "<tr class='tab_bg_2'><td colspan='4' class='center'>";
            echo Html::submit(_sx('button', 'Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        self::showList($service, 0, __('Ativos cobertos pelo serviço', 'managedservices'), $canedit);
        self::showList($service, 1, __('Ativos removidos', 'managedservices'), $canedit);
    }

    protected static function showList(PluginManagedservicesManagedservice $service, int $deleted, string $title, bool $canedit)
    {
        global $DB;

        $sid = (int) $service->getID();
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::FK => $sid, 'is_deleted' => $deleted],
            'ORDER' => 'itemtype',
        ]);
        $count = count($rows);

        echo "<div class='spaced'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='4'>" . $title . " - " . $count . "</th></tr>";
        if ($count) {
            echo "<tr class='noHover'>";
            echo "<th>" . _n('Type', 'Types', 1) . "</th>";
            echo "<th>" . _n('Item', 'Items', 1) . "</th>";
            echo "<th>" . __('Data de entrada em contrato', 'managedservices') . "</th>";
            echo "<th>" . _n('Action', 'Actions', 1) . "</th>";
            echo "</tr>";
            foreach ($rows as $row) {
                echo "<tr class='tab_bg_1'>";
                $itemtype = $row['itemtype'];
                $typename = class_exists($itemtype) ? $itemtype::getTypeName(1) : $itemtype;
                echo "<td>" . $typename . "</td>";
                echo "<td>" . self::getItemLink($itemtype, (int) $row['items_id']) . "</td>";
                echo "<td>" . Html::convDate($row['contract_entry_date']) . "</td>";
                echo "<td>";
                if ($canedit) {
                    echo "<form method='post' style='display:inline' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo Html::hidden(self::FK, ['value' => $sid]);
                    if ($deleted) {
                        echo Html::submit(__('Restore'), ['name' => 'restore', 'class' => 'btn btn-sm btn-outline-secondary']);
                        echo ' ';
                        echo Html::submit(_x('button', 'Delete permanently'), ['name' => 'purge', 'class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('Confirm the final deletion?')]);
                    } else {
                        echo Html::submit(__('Remove'), ['name' => 'softdelete', 'class' => 'btn btn-sm btn-outline-danger']);
                    }
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
                `contract_entry_date` date DEFAULT NULL,
                `is_deleted` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `$fk` (`$fk`),
                KEY `item` (`itemtype`,`items_id`),
                KEY `is_deleted` (`is_deleted`)
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
