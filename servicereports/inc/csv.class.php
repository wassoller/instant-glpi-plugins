<?php
/**
 * Saída CSV dos relatórios — um lugar só, para não repetir três cuidados
 * em cada `fputcsv()` espalhado pelas telas.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsCsv
{
    /**
     * Escreve uma linha do CSV.
     *
     * Usa `;` (Excel pt-BR) e passa o `$escape` explícito — o PHP 8.4 depreciou
     * o default. Cada célula vai por `cell()`.
     *
     * @param resource $out
     * @param array<int,mixed> $line
     */
    public static function row($out, array $line): void
    {
        fputcsv($out, array_map([self::class, 'cell'], $line), ';', '"', '');
    }

    /**
     * Prepara uma célula.
     *
     * 1. **Desescapa o HTML** — no GLPI 10 o banco devolve texto escapado
     *    (`&#62;` nas categorias em árvore) e o CSV não é HTML.
     * 2. **Neutraliza fórmula de planilha**: Excel e LibreOffice *executam* a
     *    célula que começa com `=`, `+`, `-` ou `@` (e ignoram TAB/CR antes do
     *    gatilho), então um chamado intitulado `=HYPERLINK("http://…")` vira
     *    ataque quando alguém abre o relatório. O apóstrofo à frente força texto.
     *    Número continua número — `-5` não vira `'-5`, senão a planilha deixa de
     *    somar a coluna.
     */
    public static function cell($v): string
    {
        $v = html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($v === '' || is_numeric($v)) {
            return $v;
        }
        if (strpbrk($v[0], "=+-@\t\r") !== false) {
            $v = "'" . $v;
        }
        return $v;
    }
}
