<?php
/**
 * Paginação simples (10 em 10) para as listagens dos relatórios.
 *
 * O offset vem da URL no parâmetro `start` (mesma convenção do core do GLPI);
 * os formulários de filtro não enviam `start`, então filtrar volta à 1ª página.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsPager
{
    /** Itens por página dos relatórios. */
    public const PER_PAGE = 10;

    /** Offset atual (parâmetro `start`), normalizado ao total e ao tamanho da página. */
    public static function offset(int $total, int $perPage = self::PER_PAGE): int
    {
        $start = (int) ($_GET['start'] ?? 0);
        if ($start < 0 || $start >= $total) {
            $start = 0;
        }
        return $start - ($start % $perPage);
    }

    /**
     * Barra de paginação (Bootstrap) + contagem "X a Y de Z".
     *
     * @param string               $baseUrl  URL da página (sem query string)
     * @param array<string,mixed>  $params   filtros a preservar nos links
     */
    public static function show(string $baseUrl, array $params, int $start, int $total, int $perPage = self::PER_PAGE): void
    {
        if ($total <= 0) {
            return;
        }
        $pages   = (int) ceil($total / $perPage);
        $current = (int) floor($start / $perPage) + 1;
        $from    = $start + 1;
        $to      = min($total, $start + $perPage);

        $link = static function (int $page) use ($baseUrl, $params, $perPage) {
            return $baseUrl . '?' . http_build_query(array_merge($params, ['start' => ($page - 1) * $perPage]));
        };

        echo "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2 my-2'>";
        echo "<div class='text-muted' style='font-size:.85rem'>"
            . sprintf(__('%1$s a %2$s de %3$s', 'servicereports'), $from, $to, $total) . "</div>";

        if ($pages > 1) {
            // janela de no máximo 5 páginas em volta da atual
            $first = max(1, min($current - 2, $pages - 4));
            $last  = min($pages, $first + 4);

            echo "<nav><ul class='pagination pagination-sm mb-0'>";
            $prevDis = $current <= 1 ? ' disabled' : '';
            echo "<li class='page-item$prevDis'><a class='page-link' href='" . Html::cleanInputText($link(max(1, $current - 1))) . "'>&laquo;</a></li>";
            if ($first > 1) {
                echo "<li class='page-item'><a class='page-link' href='" . Html::cleanInputText($link(1)) . "'>1</a></li>";
                if ($first > 2) {
                    echo "<li class='page-item disabled'><span class='page-link'>&hellip;</span></li>";
                }
            }
            for ($p = $first; $p <= $last; $p++) {
                $act = $p === $current ? ' active' : '';
                echo "<li class='page-item$act'><a class='page-link' href='" . Html::cleanInputText($link($p)) . "'>$p</a></li>";
            }
            if ($last < $pages) {
                if ($last < $pages - 1) {
                    echo "<li class='page-item disabled'><span class='page-link'>&hellip;</span></li>";
                }
                echo "<li class='page-item'><a class='page-link' href='" . Html::cleanInputText($link($pages)) . "'>$pages</a></li>";
            }
            $nextDis = $current >= $pages ? ' disabled' : '';
            echo "<li class='page-item$nextDis'><a class='page-link' href='" . Html::cleanInputText($link(min($pages, $current + 1))) . "'>&raquo;</a></li>";
            echo "</ul></nav>";
        }
        echo "</div>";
    }
}
