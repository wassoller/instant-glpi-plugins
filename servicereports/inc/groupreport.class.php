<?php
/**
 * Relatório "Chamados por grupo" (Central de serviços › Relatórios, id 4).
 *
 * Responde "quantos chamados do período foram para cada fila": conta os
 * **chamados** (não tarefas) pelo **grupo atribuído** — o ator *Atribuído* de
 * `glpi_groups_tickets` (tipo `ASSIGN`), o mesmo "Instant N1" que aparece no
 * chamado ao lado do técnico. O recorte do período é a **data de abertura**
 * (`glpi_tickets.date`), como no relatório 61 e no "aberto" do relatório
 * central de serviços; o status do chamado não entra na conta.
 *
 * Decisões que valem lembrar antes de mexer:
 *  - **Chamado com dois grupos atribuídos conta para cada um** (o GLPI permite),
 *    então a soma das linhas pode passar o número de chamados do período. Por
 *    isso o cabeçalho traz os dois números: chamados (distintos) e soma.
 *  - O **percentual é sobre a soma das linhas**, para a coluna fechar em 100%.
 *  - **"Sem grupo atribuído"** entra como última linha (fora da ordenação): é
 *    informação útil — chamado que ninguém colocou numa fila —, mas ordenado
 *    junto costuma ser a maior barra e esconde o ranking das filas de verdade.
 *  - O nome do grupo é o **completename** (`Dropdown::getDropdownName`), sem
 *    somar subgrupo no pai: a barra mostra a fila que está no chamado.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsGroupreport
{
    /** Nome do relatório — seletor, título da tela, PDF e CSV. */
    public static function title(): string
    {
        return __('Chamados por grupo', 'servicereports');
    }

    /**
     * Títulos das duas seções (gráfico e tabela), usados **na tela e no PDF**.
     * Não repetem o nome do relatório: no PDF ele já sai no subtítulo do
     * cabeçalho de toda folha, e a folha ficaria com a mesma frase duas vezes.
     */
    public static function chartTitle(): string
    {
        return __('Chamados por grupo atribuído', 'servicereports');
    }

    public static function tableTitle(): string
    {
        return __('Detalhamento por grupo', 'servicereports');
    }

    /** @return array<int,array<string,mixed>> */
    private static function rows(string $sql): array
    {
        global $DB;
        $out = [];
        $res = $DB->doQuery($sql);
        while ($row = $DB->fetchAssoc($res)) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Tudo o que a tela, o CSV e o PDF precisam, numa passada só.
     *
     * @return array{client:string, start:string, end:string,
     *               rows:array<int,array{id:int,label:string,value:int,note:string}>,
     *               groups:int, total_tickets:int, total_links:int, no_group:int}
     */
    public static function getReport(string $start, string $end): array
    {
        global $DB;

        $s   = $DB->escape($start);
        $e   = $DB->escape($end);
        $ent = getEntitiesRestrictRequest('AND', 'glpi_tickets');

        $where = "WHERE glpi_tickets.is_deleted=0 AND glpi_tickets.date BETWEEN '$s' AND '$e' $ent";

        // Chamados por grupo atribuído. O DISTINCT protege do chamado com o
        // mesmo grupo lançado duas vezes (o GLPI não impede).
        $all = self::rows(
            "SELECT gt.groups_id gid, COUNT(DISTINCT glpi_tickets.id) n
             FROM glpi_tickets
             INNER JOIN glpi_groups_tickets gt ON gt.tickets_id=glpi_tickets.id
                    AND gt.type=" . CommonITILActor::ASSIGN . " AND gt.groups_id>0
             $where
             GROUP BY gt.groups_id
             ORDER BY n DESC"
        );

        // Chamados do período que não têm grupo atribuído nenhum.
        $noGroup = 0;
        foreach (self::rows(
            "SELECT COUNT(*) n FROM glpi_tickets
             $where AND NOT EXISTS (
                 SELECT 1 FROM glpi_groups_tickets gt
                  WHERE gt.tickets_id=glpi_tickets.id
                    AND gt.type=" . CommonITILActor::ASSIGN . " AND gt.groups_id>0
             )"
        ) as $r) {
            $noGroup = (int) $r['n'];
        }

        // Chamados distintos do período — o número da capa. Não é a soma das
        // linhas: chamado em dois grupos aparece nas duas.
        $totalTickets = 0;
        foreach (self::rows("SELECT COUNT(*) n FROM glpi_tickets $where") as $r) {
            $totalTickets = (int) $r['n'];
        }

        $links = $noGroup;
        foreach ($all as $r) {
            $links += (int) $r['n'];
        }

        $rows = [];
        foreach ($all as $r) {
            $id   = (int) $r['gid'];
            $name = Dropdown::getDropdownName('glpi_groups', $id);
            if (trim(strip_tags((string) $name)) === '') {
                // Grupo apagado (ou fora do escopo da sessão): sem isso a barra
                // sairia sem rótulo e ninguém saberia de que fila se trata.
                $name = sprintf(__('Grupo #%d', 'servicereports'), $id);
            }
            $rows[] = [
                'id'    => $id,
                'label' => (string) $name,
                'value' => (int) $r['n'],
                'note'  => $links > 0 ? number_format((int) $r['n'] / $links * 100, 2, ',', '.') . '%' : '',
            ];
        }

        // Última linha, sempre fora da ordenação (ver o cabeçalho do arquivo).
        if ($noGroup > 0) {
            $rows[] = [
                'id'    => 0,
                'label' => __('Sem grupo atribuído', 'servicereports'),
                'value' => $noGroup,
                'note'  => $links > 0 ? number_format($noGroup / $links * 100, 2, ',', '.') . '%' : '',
            ];
        }

        return [
            'client'        => (string) ($_SESSION['glpiactive_entity_shortname'] ?? ''),
            'start'         => $start,
            'end'           => $end,
            'rows'          => $rows,
            'groups'        => count($all),
            'total_tickets' => $totalTickets,
            'total_links'   => $links,
            'no_group'      => $noGroup,
        ];
    }

    /** Texto de ajuda — o mesmo na tela e no PDF. */
    public static function hint(): string
    {
        return __('Conta os CHAMADOS abertos no período pelo GRUPO ATRIBUÍDO ao chamado (o mesmo grupo que '
                . 'aparece ao lado do técnico). Chamado atribuído a mais de um grupo conta para cada um, então a '
                . 'soma pode passar o número de chamados do período; o percentual é sobre essa soma. '
                . 'Subgrupo não soma no grupo-pai: vale a fila que está no chamado.', 'servicereports');
    }
}
