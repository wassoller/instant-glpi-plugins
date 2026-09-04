<?php
/**
 * Aba "Gerência": gerentes (usuários e grupos) de um serviço gerenciado.
 * Tabela: glpi_plugin_managedservices_managers
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginManagedservicesManager extends CommonDBTM
{
    public static $rightname = 'plugin_managedservices';

    public const FK = 'plugin_managedservices_managedservices_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Gerente', 'Gerentes', $nb, 'managedservices');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof PluginManagedservicesManagedservice) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs'] ?? 0) {
                $nb = countElementsInTable(self::getTable(), [self::FK => $item->getID()]);
            }
            return self::createTabEntry(__('Gerência', 'managedservices'), $nb);
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

        $sid    = (int) $service->getID();
        $canedit = $service->canUpdateItem();

        $userIds = [];
        $groupIds = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::FK => $sid]]) as $row) {
            if ((int) $row['users_id'] > 0) {
                $userIds[] = (int) $row['users_id'];
            }
            if ((int) $row['groups_id'] > 0) {
                $groupIds[] = (int) $row['groups_id'];
            }
        }

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
        }
        echo Html::hidden(self::FK, ['value' => $sid]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . __('Gerentes', 'managedservices') . "</th></tr>";

        echo "<tr class='tab_bg_1'><td style='width:200px'>" . __('Usuário Gerente', 'managedservices') . "</td><td>";
        User::dropdown([
            'name'     => 'users_managers[]',
            'multiple' => true,
            'value'    => $userIds,
            'right'    => 'all',
            'entity'   => $service->fields['entities_id'],
            'width'    => '80%',
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Grupo Gerente', 'managedservices') . "</td><td>";
        Group::dropdown([
            'name'     => 'groups_managers[]',
            'multiple' => true,
            'value'    => $groupIds,
            'entity'   => $service->fields['entities_id'],
            'width'    => '80%',
        ]);
        echo "</td></tr>";

        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update_managers', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
        }
        echo "</table>";

        if ($canedit) {
            Html::closeForm();
        }
        echo "</div>";
    }

    /**
     * Substitui o conjunto de gerentes do serviço.
     */
    public static function updateForService(array $input)
    {
        global $DB;

        $sid = (int) ($input[self::FK] ?? 0);
        if ($sid <= 0) {
            return;
        }

        $users  = $input['users_managers'] ?? [];
        $groups = $input['groups_managers'] ?? [];

        $DB->delete(self::getTable(), [self::FK => $sid]);

        $obj = new self();
        foreach ($users as $uid) {
            if ((int) $uid > 0) {
                $obj->add([self::FK => $sid, 'users_id' => (int) $uid, 'groups_id' => 0]);
            }
        }
        foreach ($groups as $gid) {
            if ((int) $gid > 0) {
                $obj->add([self::FK => $sid, 'users_id' => 0, 'groups_id' => (int) $gid]);
            }
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
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `groups_id` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `$fk` (`$fk`),
                KEY `users_id` (`users_id`),
                KEY `groups_id` (`groups_id`)
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
