<?php
/**
 * Aba "Configuração NMS": endereço (URL) do NMS do serviço.
 * Tabela: glpi_plugin_managedservices_nmsconfigs (1 linha por serviço).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginManagedservicesNmsconfig extends CommonDBTM
{
    public static $rightname = 'plugin_managedservices';

    public const FK = 'plugin_managedservices_managedservices_id';

    public static function getTypeName($nb = 0)
    {
        return __('Configuração NMS', 'managedservices');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof PluginManagedservicesManagedservice) {
            return self::createTabEntry(__('Configuração NMS', 'managedservices'));
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

        $url = '';
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => [self::FK => $sid], 'LIMIT' => 1])->current();
        if ($row) {
            $url = $row['url_nms'];
        }

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
        }
        echo Html::hidden(self::FK, ['value' => $sid]);
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . __('Configuração URL', 'managedservices') . "</th></tr>";
        echo "<tr class='tab_bg_1'>";
        echo "<td style='width:200px'>" . __('Endereço NMS', 'managedservices') . "</td>";
        echo "<td>";
        echo Html::input('url_nms', ['value' => $url, 'size' => 60]);
        echo "</td>";
        echo "</tr>";
        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'save_nms', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
        }
        echo "</table>";
        if ($canedit) {
            Html::closeForm();
        }
        echo "</div>";
    }

    /**
     * Upsert: grava a URL do NMS do serviço (uma linha por serviço).
     */
    public static function saveForService(array $input)
    {
        global $DB;

        $sid = (int) ($input[self::FK] ?? 0);
        if ($sid <= 0) {
            return;
        }
        $url = trim((string) ($input['url_nms'] ?? ''));

        $existing = $DB->request(['FROM' => self::getTable(), 'WHERE' => [self::FK => $sid], 'LIMIT' => 1])->current();
        $obj = new self();
        if ($existing) {
            $obj->update(['id' => (int) $existing['id'], 'url_nms' => $url]);
        } else {
            $obj->add([self::FK => $sid, 'url_nms' => $url]);
        }
    }

    public function prepareInputForAdd($input)
    {
        return PluginManagedservicesManagedservice::stampEntity($input, self::FK);
    }

    public function prepareInputForUpdate($input)
    {
        return PluginManagedservicesManagedservice::stampEntity($input, self::FK);
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
                `url_nms` varchar(255) DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `service` (`$fk`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
            $DB->doQuery($query);
        }

        // Herda a entidade do serviço: sem `entities_id` na tabela o core
        // devolve isEntityAssign()=false e NÃO restringe nada (API REST inclusa).
        PluginManagedservicesManagedservice::inheritEntity($migration, $table, self::FK);
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
