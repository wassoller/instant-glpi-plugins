<?php
/**
 * Aba "Financeiro": valores monetários (historizados por data de contrato) e
 * configurações (horas de suporte / limite de horas) de um serviço gerenciado.
 *
 * Tabela unificada: glpi_plugin_managedservices_financialvalues
 *   discriminada por `value_type`. As configs (is_supporthours/support_hours,
 *   is_hourslimit/hours_limit) ficam na própria tabela do serviço.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginManagedservicesFinancialvalue extends CommonDBTM
{
    public static $rightname = 'plugin_managedservices';

    public const FK = 'plugin_managedservices_managedservices_id';

    public const MONTHLY  = 'monthly';
    public const HOURLY   = 'hourly';
    public const PERCLASS = 'perclass';
    public const PERUSER  = 'peruser';
    public const DATABASE = 'database';
    public const STORAGE  = 'storage';

    public static function getTypeName($nb = 0)
    {
        return __('Financeiro', 'managedservices');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof PluginManagedservicesManagedservice) {
            return self::createTabEntry(__('Financeiro', 'managedservices'));
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
        $canedit = $service->canUpdateItem();

        self::showValueSection($service, self::MONTHLY, __('Valor monetário mensal', 'managedservices'), ['description' => true], $canedit);
        self::showValueSection($service, self::HOURLY, __('Valor monetário por hora', 'managedservices'), [], $canedit);
        self::showConfigSection($service, 'supporthours', __('Horas de suporte', 'managedservices'), __('Há horas de suporte', 'managedservices'), __('Horas de suporte', 'managedservices'), $canedit);
        self::showConfigSection($service, 'hourslimit', __('Limite de horas', 'managedservices'), __('Há limite de horas', 'managedservices'), __('Limite de horas', 'managedservices'), $canedit);
        self::showValueSection($service, self::PERCLASS, __('Valor monetário por classe de ativos cobertos', 'managedservices'), ['assettype' => true], $canedit);
        self::showValueSection($service, self::PERUSER, __('Valor monetário por usuário', 'managedservices'), ['user' => true], $canedit);
        self::showValueSection($service, self::DATABASE, __('Valor monetário por banco de dados', 'managedservices'), [], $canedit);
        self::showValueSection($service, self::STORAGE, __('Valor monetário por espaço de armazenamento', 'managedservices'), [], $canedit);
    }

    /**
     * Seção genérica de valor historizado.
     *
     * @param array{description?:bool,user?:bool,assettype?:bool} $opts
     */
    protected static function showValueSection(
        PluginManagedservicesManagedservice $service,
        string $type,
        string $title,
        array $opts,
        bool $canedit
    ) {
        global $DB;

        $sid = (int) $service->getID();

        // --- Formulário de adição ---
        if ($canedit) {
            echo "<div class='spaced'>";
            echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
            echo Html::hidden(self::FK, ['value' => $sid]);
            echo Html::hidden('value_type', ['value' => $type]);
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='4'>" . $title . "</th></tr>";

            if (!empty($opts['assettype'])) {
                echo "<tr class='tab_bg_1'><td style='width:200px'>" . __('Classe', 'managedservices') . " / " . __('Tipo', 'managedservices') . "</td><td colspan='3'>";
                Dropdown::showSelectItemFromItemtypes([
                    'itemtypes'  => PluginManagedservicesManagedservice::getAssetTypeItemtypes(),
                    'checkright' => false,
                ]);
                echo "</td></tr>";
            }

            if (!empty($opts['user'])) {
                echo "<tr class='tab_bg_1'><td style='width:200px'>" . User::getTypeName(1) . "</td><td colspan='3'>";
                User::dropdown([
                    'name'   => 'users_id',
                    'right'  => 'all',
                    'entity' => $service->fields['entities_id'],
                ]);
                echo "</td></tr>";
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td style='width:200px'>" . __('Valor', 'managedservices') . " (R$)</td>";
            echo "<td><input type='number' step='0.01' min='0' class='form-control' name='value' required></td>";
            echo "<td>" . __('Data de entrada em contrato', 'managedservices') . "</td>";
            echo "<td>";
            Html::showDateField('record_date', ['value' => date('Y-m-d')]);
            echo "</td></tr>";

            if (!empty($opts['description'])) {
                echo "<tr class='tab_bg_1'><td>" . __('Descrição do valor', 'managedservices') . "</td>";
                echo "<td colspan='3'><textarea class='form-control' name='description' rows='2'></textarea></td></tr>";
            }

            echo "<tr class='tab_bg_2'><td colspan='4' class='center'>";
            echo Html::submit(_sx('button', 'Add'), ['name' => 'add_value', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // --- Histórico ---
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::FK => $sid, 'value_type' => $type],
            'ORDER' => 'record_date DESC',
        ]);
        $count = count($rows);

        echo "<div class='spaced'>";
        echo "<table class='tab_cadre_fixe'>";
        $ncols = 3 + (!empty($opts['user']) || !empty($opts['assettype']) || !empty($opts['description']) ? 1 : 0);

        if ($count) {
            echo "<tr class='noHover'>";
            echo "<th>" . __('Valor', 'managedservices') . "</th>";
            echo "<th>" . __('Data de entrada em contrato', 'managedservices') . "</th>";
            if (!empty($opts['user'])) {
                echo "<th>" . User::getTypeName(1) . "</th>";
            } elseif (!empty($opts['assettype'])) {
                echo "<th>" . __('Tipo', 'managedservices') . "</th>";
            } elseif (!empty($opts['description'])) {
                echo "<th>" . __('Descrição', 'managedservices') . "</th>";
            }
            echo "<th>" . _n('Action', 'Actions', 1) . "</th>";
            echo "</tr>";

            foreach ($rows as $row) {
                echo "<tr class='tab_bg_1'>";
                echo "<td>R$ " . Html::formatNumber($row['value']) . "</td>";
                echo "<td>" . Html::convDate($row['record_date']) . "</td>";
                if (!empty($opts['user'])) {
                    echo "<td>" . getUserName((int) $row['users_id']) . "</td>";
                } elseif (!empty($opts['assettype'])) {
                    echo "<td>" . self::getTypeItemName($row['itemtype'], (int) $row['items_id']) . "</td>";
                } elseif (!empty($opts['description'])) {
                    echo "<td>" . Html::resume_text((string) $row['description'], 60) . "</td>";
                }
                echo "<td>";
                if ($canedit) {
                    echo "<form method='post' style='display:inline' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo Html::hidden(self::FK, ['value' => $sid]);
                    echo Html::submit(_x('button', 'Delete permanently'), ['name' => 'delete_value', 'class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('Confirm the final deletion?')]);
                    Html::closeForm();
                }
                echo "</td></tr>";
            }
        } else {
            echo "<tr class='tab_bg_1'><td class='center' colspan='$ncols'>" . __('Nenhum valor cadastrado', 'managedservices') . "</td></tr>";
        }
        echo "</table>";
        echo "</div>";
    }

    /**
     * Seção de configuração (horas de suporte / limite de horas), 1:1 com o serviço.
     */
    protected static function showConfigSection(
        PluginManagedservicesManagedservice $service,
        string $key,
        string $title,
        string $toggleLabel,
        string $valueLabel,
        bool $canedit
    ) {
        $sid       = (int) $service->getID();
        $isField   = 'is_' . $key;
        $valField  = $key === 'supporthours' ? 'support_hours' : 'hours_limit';
        $isValue   = (int) ($service->fields[$isField] ?? 0);
        $valValue  = $service->fields[$valField] ?? 0;

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(self::class) . "'>";
        }
        echo Html::hidden(self::FK, ['value' => $sid]);
        echo Html::hidden('config_key', ['value' => $key]);
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . $title . "</th></tr>";
        echo "<tr class='tab_bg_1'><td style='width:200px'>" . $toggleLabel . "</td><td>";
        Dropdown::showYesNo($isField, $isValue);
        echo "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . $valueLabel . "</td><td>";
        echo "<input type='number' step='0.01' min='0' class='form-control' name='$valField' value='" . Html::cleanInputText((string) $valValue) . "' style='max-width:200px'>";
        echo "</td></tr>";
        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'save_config', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
        }
        echo "</table>";
        if ($canedit) {
            Html::closeForm();
        }
        echo "</div>";
    }

    protected static function getTypeItemName($itemtype, int $items_id)
    {
        if (!$itemtype || !class_exists($itemtype)) {
            return '';
        }
        $obj = new $itemtype();
        if ($obj->getFromDB($items_id)) {
            return $obj->getName();
        }
        return "#$items_id";
    }

    // ---- Operações chamadas pelo handler front ----

    public static function addValue(array $input)
    {
        $sid = (int) ($input[self::FK] ?? 0);
        $type = (string) ($input['value_type'] ?? '');
        if ($sid <= 0 || $type === '') {
            return false;
        }
        $data = [
            self::FK       => $sid,
            'value_type'   => $type,
            'value'        => (float) str_replace(',', '.', (string) ($input['value'] ?? 0)),
            'record_date'  => $input['record_date'] ?: null,
            'description'  => $input['description'] ?? null,
            'itemtype'     => $input['itemtype'] ?? null,
            'items_id'     => (int) ($input['items_id'] ?? 0),
            'users_id'     => (int) ($input['users_id'] ?? 0),
        ];
        $obj = new self();
        return $obj->add($data);
    }

    public static function saveConfig(array $input)
    {
        $sid = (int) ($input[self::FK] ?? 0);
        $key = (string) ($input['config_key'] ?? '');
        if ($sid <= 0 || !in_array($key, ['supporthours', 'hourslimit'], true)) {
            return;
        }
        $isField  = 'is_' . $key;
        $valField = $key === 'supporthours' ? 'support_hours' : 'hours_limit';

        $service = new PluginManagedservicesManagedservice();
        if ($service->getFromDB($sid)) {
            $service->update([
                'id'      => $sid,
                $isField  => (int) ($input[$isField] ?? 0),
                $valField => (float) str_replace(',', '.', (string) ($input[$valField] ?? 0)),
            ]);
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
                `value_type` varchar(20) NOT NULL DEFAULT '',
                `value` decimal(15,4) NOT NULL DEFAULT 0,
                `description` text,
                `record_date` date DEFAULT NULL,
                `itemtype` varchar(100) DEFAULT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `service_type` (`$fk`,`value_type`),
                KEY `record_date` (`record_date`),
                KEY `item` (`itemtype`,`items_id`),
                KEY `users_id` (`users_id`)
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
