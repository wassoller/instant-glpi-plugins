<?php
/**
 * Objeto principal do plugin: Serviço Gerenciado.
 *
 * Tabela: glpi_plugin_managedservices_managedservices
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginManagedservicesManagedservice extends CommonDBTM
{
    /** @var string Direito associado (registrado em glpi_profilerights). */
    public static $rightname = 'plugin_managedservices';

    /** @var bool Mantém histórico de alterações. */
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Serviço Gerenciado', 'Serviços Gerenciados', $nb, 'managedservices');
    }

    public static function getMenuName()
    {
        return self::getTypeName(Session::getPluralNumber());
    }

    public static function getIcon()
    {
        return 'ti ti-server-cog';
    }

    /**
     * Classes de ativo que podem ser cobertas / compor um serviço.
     *
     * @return array<int,string> lista de itemtypes
     */
    public static function getAssetItemtypes()
    {
        return [
            'Computer',
            'Monitor',
            'NetworkEquipment',
            'Peripheral',
            'Printer',
            'Phone',
            'Rack',
            'Enclosure',
            'PDU',
            'PassiveDCEquipment',
            'Cable',
            'CartridgeItem',
            'ConsumableItem',
            'Software',
            'PluginManagedservicesManagedservice',
        ];
    }

    /**
     * Classes de "tipo" de ativo (para valor monetário por classe de ativo).
     *
     * @return array<int,string>
     */
    public static function getAssetTypeItemtypes()
    {
        return [
            'ComputerType',
            'MonitorType',
            'NetworkEquipmentType',
            'PeripheralType',
            'PrinterType',
            'CartridgeItemType',
            'ConsumableItemType',
            'PhoneType',
            'RackType',
            'PDUType',
            'PassiveDCEquipmentType',
            'CableType',
        ];
    }

    /**
     * Ícone/menu em "Ativos". Habilita busca e criação a partir do menu.
     */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent() ?: [];

        $menu['title'] = self::getMenuName();
        $menu['page']  = self::getSearchURL(false);
        $menu['icon']  = self::getIcon();
        $menu['links']['search'] = self::getSearchURL(false);
        if (self::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }

        return $menu;
    }

    /**
     * Opções de busca (search engine do GLPI).
     */
    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(2),
        ];

        $tab[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '2',
            'table'    => self::getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '3',
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Cliente', 'managedservices'),
            'datatype' => 'dropdown',
            'linkfield' => 'users_id',
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => 'glpi_itilcategories',
            'field'    => 'completename',
            'name'     => __('Categoria de chamado', 'managedservices'),
            'datatype' => 'dropdown',
            'linkfield' => 'itilcategories_id',
        ];

        $tab[] = [
            'id'        => '5',
            'table'     => 'glpi_contracts',
            'field'     => 'name',
            'name'      => Contract::getTypeName(1),
            'datatype'  => 'dropdown',
            'linkfield' => 'contracts_id',
        ];

        $tab[] = [
            'id'       => '16',
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => '19',
            'table'    => self::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '121',
            'table'    => self::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Creation date'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '80',
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => Entity::getTypeName(1),
            'datatype' => 'dropdown',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '86',
            'table'    => self::getTable(),
            'field'    => 'is_recursive',
            'name'     => __('Child entities'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    /**
     * Formulário principal do serviço gerenciado.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Name') . "</td>";
        echo "<td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo "</td>";
        echo "<td rowspan='4' style='vertical-align: top'>" . __('Comments') . "</td>";
        echo "<td rowspan='4' style='vertical-align: top'>";
        echo "<textarea class='form-control' name='comment' rows='6'>"
            . Html::cleanInputText($this->fields['comment'] ?? '') . "</textarea>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Cliente', 'managedservices') . "</td>";
        echo "<td>";
        User::dropdown([
            'name'   => 'users_id',
            'value'  => $this->fields['users_id'] ?? 0,
            'right'  => 'all',
            'entity' => $this->fields['entities_id'] ?? $_SESSION['glpiactive_entity'],
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Categoria de chamado', 'managedservices') . "</td>";
        echo "<td>";
        // permit_select_parent: sem isso o GLPI marca as categorias-pai como
        // `disabled` (só rótulo da hierarquia) e não dá para escolher, p.ex.,
        // "Suporte Avançado" quando ela tem filhas.
        ITILCategory::dropdown([
            'name'                 => 'itilcategories_id',
            'value'                => $this->fields['itilcategories_id'] ?? 0,
            'permit_select_parent' => true,
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . Contract::getTypeName(1) . "</td>";
        echo "<td>";
        Contract::dropdown([
            'name'   => 'contracts_id',
            'value'  => $this->fields['contracts_id'] ?? 0,
            'entity' => $this->fields['entities_id'] ?? $_SESSION['glpiactive_entity'],
        ]);
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);

        return true;
    }

    /**
     * Criação da tabela na instalação.
     */
    public static function install(Migration $migration)
    {
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `name` varchar(255) DEFAULT NULL,
                `comment` text,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `contracts_id` int unsigned NOT NULL DEFAULT 0,
                `itilcategories_id` int unsigned NOT NULL DEFAULT 0,
                `is_supporthours` tinyint NOT NULL DEFAULT 0,
                `support_hours` decimal(10,2) NOT NULL DEFAULT 0,
                `is_hourslimit` tinyint NOT NULL DEFAULT 0,
                `hours_limit` decimal(10,2) NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `users_id` (`users_id`),
                KEY `contracts_id` (`contracts_id`),
                KEY `itilcategories_id` (`itilcategories_id`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
            $DB->doQuery($query);
        }
    }

    /**
     * Remoção da tabela na desinstalação.
     */
    public static function uninstall(Migration $migration)
    {
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }
}
