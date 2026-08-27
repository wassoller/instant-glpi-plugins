<?php
/**
 * Gráficos em SVG montados no PHP — linha, barras agrupadas, barras
 * horizontais e rosca.
 *
 * Mesma escolha do relatório 61 (Analistas): nada de biblioteca JS. O GLPI 10
 * traz o Chartist, mas ele exigiria plugin para tooltip e **não serve para o
 * PDF** — aqui a mesma série é redesenhada com primitivas do TCPDF, e um SVG
 * gerado no PHP é a única forma de garantir que tela e papel batem.
 *
 * O tooltip é comum a todos: qualquer elemento com `data-tip-title` /
 * `data-tip-body` (linhas separadas por `|`) acende a caixinha. O conteúdo é
 * montado com `textContent` — nunca `innerHTML`, que com nome de usuário
 * dentro é convite a XSS.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsChart
{
    /** Paleta — o azul-marinho e o azul-aço são os do relatório original. */
    public const NAVY  = '#2b3a54';
    public const STEEL = '#93a9c6';
    public const GREEN = '#4fae4f';
    public const RED   = '#e03131';

    /** CSS/JS emitidos uma única vez por página. */
    private static bool $assetsDone = false;

    public static function assets(): void
    {
        if (self::$assetsDone) {
            return;
        }
        self::$assetsDone = true;

        echo "<style>
            .sr-ch-wrap { overflow-x: auto; padding-bottom: .25rem; }
            svg.sr-ch { display: block; margin: 0 auto; }
            .sr-ch-grid { stroke: var(--tblr-border-color, #e6e7e9); stroke-width: 1; }
            .sr-ch-base { stroke: var(--tblr-body-color, #232b31); stroke-width: 1.4; }
            .sr-ch-axis { font-size: 11px; fill: var(--tblr-secondary, #626976); }
            .sr-ch-name { font-size: 11px; fill: var(--tblr-body-color, #232b31); }
            .sr-ch-val  { font-size: 10.5px; fill: var(--tblr-secondary, #626976); }
            .sr-ch-badge-t { font-size: 10px; fill: #fff; font-weight: 600; }
            .sr-ch-hit { cursor: default; }
            .sr-ch-hit:hover { opacity: .8; }
            .sr-ch-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; justify-content: center;
                            font-size: .82rem; margin-bottom: .35rem; color: var(--tblr-secondary, #626976); }
            .sr-ch-key { display: inline-flex; align-items: center; gap: .35rem; }
            .sr-ch-key i { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
            .sr-ch-tip { position: fixed; z-index: 1080; display: none; pointer-events: none;
                         background: #1f2933; color: #fff; border-radius: 4px; padding: .4rem .6rem;
                         font-size: .78rem; line-height: 1.35; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
            .sr-ch-tip b { font-weight: 600; }
        </style>";

        echo Html::scriptBlock(<<<'JS'
(function () {
    var tip = document.createElement('div');
    tip.className = 'sr-ch-tip';
    document.body.appendChild(tip);

    function place(e) {
        var x = e.clientX + 14, y = e.clientY + 14;
        if (x + tip.offsetWidth > window.innerWidth) { x = e.clientX - tip.offsetWidth - 14; }
        if (y + tip.offsetHeight > window.innerHeight) { y = e.clientY - tip.offsetHeight - 14; }
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
    }

    function fill(el) {
        tip.textContent = '';
        var head = document.createElement('div');
        head.appendChild(document.createElement('b')).textContent = el.getAttribute('data-tip-title') || '';
        tip.appendChild(head);
        (el.getAttribute('data-tip-body') || '').split('|').forEach(function (line) {
            if (!line) { return; }
            var d = document.createElement('div');
            d.textContent = line;
            tip.appendChild(d);
        });
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target;
        if (!el.getAttribute || !el.hasAttribute('data-tip-title')) { return; }
        fill(el);
        tip.style.display = 'block';
        place(e);
    });
    document.addEventListener('mousemove', function (e) {
        if (tip.style.display === 'block') { place(e); }
    });
    document.addEventListener('mouseout', function (e) {
        if (e.target.getAttribute && e.target.hasAttribute('data-tip-title')) { tip.style.display = 'none'; }
    });
})();
JS);
    }

    /** Atributos de tooltip de um elemento SVG. */
    private static function tip(string $title, array $lines): string
    {
        return " data-tip-title='" . Html::cleanInputText(self::plain($title)) . "'"
            . " data-tip-body='" . Html::cleanInputText(self::plain(implode('|', $lines))) . "'";
    }

    /** Texto sem entidades HTML (o GLPI devolve conteúdo escapado). */
    public static function plain(string $v): string
    {
        return html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Escapa para nó de texto do SVG. */
    private static function esc(string $v): string
    {
        return htmlspecialchars(self::plain($v), ENT_QUOTES, 'UTF-8');
    }

    /** Corta com reticências. */
    public static function shorten(string $v, int $max): string
    {
        $v = self::plain($v);
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    }

    /** Legenda acima do gráfico. @param array<int,array{label:string,color:string}> $keys */
    public static function legend(array $keys): void
    {
        echo "<div class='sr-ch-legend'>";
        foreach ($keys as $k) {
            echo "<span class='sr-ch-key'><i style='background:" . $k['color'] . "'></i>" . $k['label'] . "</span>";
        }
        echo "</div>";
    }

    // =====================================================================
    //  Gráfico de linha (abertos por dia)
    // =====================================================================

    /**
     * @param array<int,string> $labels rótulos do eixo X
     * @param array<int,int>    $values valores na mesma ordem
     */
    public static function line(array $labels, array $values, string $seriesName, string $color = self::NAVY): void
    {
        self::assets();
        self::legend([['label' => $seriesName, 'color' => $color]]);

        $n = count($values);
        if ($n === 0) {
            self::nodata();
            return;
        }

        [$top, $step] = PluginServicereportsAnalysts::niceScale((int) max($values));
        $slot  = $n > 60 ? 22 : 36;
        $padL  = 46;
        $padR  = 24;
        $padT  = 26;
        $padB  = 46;
        $plotH = 300;
        $plotW = max($n * $slot, 240);
        $w     = $padL + $plotW + $padR;
        $h     = $padT + $plotH + $padB;
        $base  = $padT + $plotH;
        // Com muitos dias, mostra 1 rótulo a cada N para não virar borrão.
        $every = (int) max(1, ceil($n / 34));
        $badge = $n <= 40; // o número dentro do balãozinho, como no original

        echo "<div class='sr-ch-wrap'><svg class='sr-ch' width='$w' height='$h' viewBox='0 0 $w $h' role='img'>";
        self::grid($padL, $plotW, $base, $plotH, $top, $step);

        $pts = [];
        foreach (array_values($values) as $i => $v) {
            $x     = $padL + $i * $slot + $slot / 2;
            $y     = $base - ($top > 0 ? ($v / $top) * $plotH : 0);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        echo "<polyline fill='none' stroke='$color' stroke-width='1.6' points='" . implode(' ', $pts) . "'/>";

        foreach (array_values($values) as $i => $v) {
            $x = $padL + $i * $slot + $slot / 2;
            $y = $base - ($top > 0 ? ($v / $top) * $plotH : 0);
            $t = self::tip((string) ($labels[$i] ?? ''), [$seriesName . ': ' . (int) $v]);
            if ($badge && $v > 0) {
                $bw = 12 + 6 * strlen((string) (int) $v);
                echo "<rect class='sr-ch-hit' x='" . round($x - $bw / 2, 1) . "' y='" . round($y - 24, 1) . "'"
                    . " width='$bw' height='15' rx='3' fill='$color'$t></rect>";
                echo "<text class='sr-ch-badge-t' x='" . round($x, 1) . "' y='" . round($y - 13, 1) . "' text-anchor='middle'>"
                    . (int) $v . "</text>";
            }
            echo "<circle class='sr-ch-hit' cx='" . round($x, 1) . "' cy='" . round($y, 1) . "' r='4' fill='$color'$t></circle>";
            if ($i % $every === 0) {
                echo "<text class='sr-ch-axis' x='" . round($x, 1) . "' y='" . ($base + 16) . "' text-anchor='middle'>"
                    . self::esc((string) ($labels[$i] ?? '')) . "</text>";
            }
        }
        echo "</svg></div>";
    }

    // =====================================================================
    //  Barras verticais agrupadas (abertos × encerrados, SLA por dia)
    // =====================================================================

    /**
     * @param array<int,string> $labels
     * @param array<int,array{name:string,color:string,data:array<int,int>}> $series
     */
    public static function bars(array $labels, array $series): void
    {
        self::assets();
        $keys = [];
        foreach ($series as $s) {
            $total  = array_sum($s['data']);
            $keys[] = ['label' => $total . ' - ' . $s['name'], 'color' => $s['color']];
        }
        self::legend($keys);

        $n = count($labels);
        if ($n === 0 || empty($series)) {
            self::nodata();
            return;
        }

        $max = 0;
        foreach ($series as $s) {
            $max = max($max, (int) max($s['data'] ?: [0]));
        }
        [$top, $step] = PluginServicereportsAnalysts::niceScale($max);

        $k     = count($series);
        $slot  = $n > 45 ? 20 : ($n > 25 ? 34 : 48);
        $barW  = max(3.0, ($slot - 8) / $k);
        $padL  = 46;
        $padR  = 24;
        $padT  = 26;
        $padB  = $n > 20 ? 62 : 40;
        $plotH = 300;
        $plotW = max($n * $slot, 240);
        $w     = $padL + $plotW + $padR;
        $h     = $padT + $plotH + $padB;
        $base  = $padT + $plotH;
        $every = (int) max(1, ceil($n / 34));
        $rot   = $n > 20;
        $vals  = $n <= 32; // números acima das barras

        echo "<div class='sr-ch-wrap'><svg class='sr-ch' width='$w' height='$h' viewBox='0 0 $w $h' role='img'>";
        self::grid($padL, $plotW, $base, $plotH, $top, $step);

        foreach (array_values($labels) as $i => $label) {
            $x0 = $padL + $i * $slot + ($slot - $barW * $k) / 2;
            foreach (array_values($series) as $j => $s) {
                $v = (int) ($s['data'][$i] ?? 0);
                $x = $x0 + $j * $barW;
                if ($v > 0) {
                    $bh = ($v / $top) * $plotH;
                    $t  = self::tip((string) $label, [$s['name'] . ': ' . $v]);
                    echo "<rect class='sr-ch-hit' x='" . round($x, 1) . "' y='" . round($base - $bh, 1) . "'"
                        . " width='" . round($barW, 1) . "' height='" . round($bh, 1) . "' fill='" . $s['color'] . "'$t></rect>";
                    if ($vals) {
                        echo "<text class='sr-ch-val' x='" . round($x + $barW / 2, 1) . "' y='" . round($base - $bh - 4, 1) . "'"
                            . " text-anchor='middle'>$v</text>";
                    }
                } elseif ($vals) {
                    echo "<text class='sr-ch-val' x='" . round($x + $barW / 2, 1) . "' y='" . ($base - 4) . "' text-anchor='middle'>0</text>";
                }
            }
            if ($i % $every === 0) {
                $lx = $padL + $i * $slot + $slot / 2;
                $ly = $base + 14;
                if ($rot) {
                    echo "<text class='sr-ch-axis' x='$lx' y='$ly' text-anchor='end' transform='rotate(-40 $lx $ly)'>"
                        . self::esc((string) $label) . "</text>";
                } else {
                    echo "<text class='sr-ch-axis' x='$lx' y='" . ($base + 16) . "' text-anchor='middle'>"
                        . self::esc((string) $label) . "</text>";
                }
            }
        }
        echo "</svg></div>";
    }

    // =====================================================================
    //  Barras horizontais (top categorias / top usuários)
    // =====================================================================

    /**
     * @param array<int,array{label:string,value:int,note?:string}> $rows já ordenadas
     */
    public static function hbars(array $rows, string $seriesName, string $color = self::NAVY): void
    {
        self::assets();
        self::legend([['label' => $seriesName, 'color' => $color]]);

        if (empty($rows)) {
            self::nodata();
            return;
        }

        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) $r['value']);
        }
        [$top, $step] = PluginServicereportsAnalysts::niceScale($max, 5);

        $rowH  = 30;
        $barH  = 16;
        $padL  = 340;   // espaço do rótulo (categoria em árvore é longa)
        $padR  = 40;
        $padT  = 12;
        $padB  = 30;
        $plotW = 560;
        $rowsN = count($rows);
        $w     = $padL + $plotW + $padR;
        $h     = $padT + $rowsN * $rowH + $padB;

        echo "<div class='sr-ch-wrap'><svg class='sr-ch' width='$w' height='$h' viewBox='0 0 $w $h' role='img'>";

        for ($v = 0; $v <= $top; $v += $step) {
            $x = $padL + ($v / $top) * $plotW;
            echo "<line class='sr-ch-grid' x1='" . round($x, 1) . "' y1='$padT' x2='" . round($x, 1) . "' y2='"
                . ($padT + $rowsN * $rowH) . "'/>";
            echo "<text class='sr-ch-axis' x='" . round($x, 1) . "' y='" . ($padT + $rowsN * $rowH + 16)
                . "' text-anchor='middle'>$v</text>";
        }

        foreach (array_values($rows) as $i => $r) {
            $y   = $padT + $i * $rowH + ($rowH - $barH) / 2;
            $bw  = $top > 0 ? ((int) $r['value'] / $top) * $plotW : 0;
            $note = (string) ($r['note'] ?? '');
            $t   = self::tip((string) $r['label'], [$seriesName . ': ' . (int) $r['value']
                . ($note !== '' ? ' (' . $note . ')' : '')]);
            echo "<text class='sr-ch-name' x='" . ($padL - 10) . "' y='" . ($y + $barH - 3) . "' text-anchor='end'$t>"
                . self::esc((int) $r['value'] . ' - ' . self::shorten((string) $r['label'], 52))
                . ($note !== '' ? self::esc(': ' . $note) : '') . "</text>";
            echo "<rect class='sr-ch-hit' x='$padL' y='" . round($y, 1) . "' width='" . round(max($bw, 1), 1) . "'"
                . " height='$barH' fill='$color'$t></rect>";
        }
        echo "</svg></div>";
    }

    // =====================================================================
    //  Rosca (nível de serviço)
    // =====================================================================

    /**
     * @param array<int,array{label:string,value:int,color:string}> $slices
     */
    public static function donut(array $slices): void
    {
        self::assets();

        $total = 0;
        foreach ($slices as $s) {
            $total += (int) $s['value'];
        }
        if ($total <= 0) {
            self::nodata();
            return;
        }

        $size = 300;
        $cx   = $size / 2;
        $cy   = $size / 2;
        $rOut = 130;
        $rIn  = 66;

        echo "<div class='d-flex flex-wrap align-items-center justify-content-center gap-4'>";
        echo "<div class='sr-ch-legend' style='flex-direction:column; align-items:flex-start; justify-content:center'>";
        foreach ($slices as $s) {
            $pct = round((int) $s['value'] / $total * 100, 1);
            echo "<span class='sr-ch-key'><i style='background:" . $s['color'] . "'></i>"
                . (int) $s['value'] . ' - ' . $s['label'] . " <span class='text-muted'>($pct%)</span></span>";
        }
        echo "</div>";

        echo "<svg class='sr-ch' width='$size' height='$size' viewBox='0 0 $size $size' role='img'>";
        $angle = -M_PI / 2; // começa às 12 horas
        foreach ($slices as $s) {
            $v = (int) $s['value'];
            if ($v <= 0) {
                continue;
            }
            $sweep = 2 * M_PI * ($v / $total);
            $pct   = round($v / $total * 100, 1);
            $t     = self::tip((string) $s['label'], [$v . ' (' . $pct . '%)']);
            echo "<path class='sr-ch-hit' d='" . self::arc($cx, $cy, $rOut, $rIn, $angle, $angle + $sweep) . "'"
                . " fill='" . $s['color'] . "'$t></path>";
            if ($sweep > 0.28) {
                $mid = $angle + $sweep / 2;
                $lr  = ($rOut + $rIn) / 2;
                echo "<text class='sr-ch-badge-t' x='" . round($cx + cos($mid) * $lr, 1) . "' y='"
                    . round($cy + sin($mid) * $lr + 4, 1) . "' text-anchor='middle'>$v</text>";
            }
            $angle += $sweep;
        }
        echo "</svg></div>";
    }

    /** Caminho SVG de um anel entre dois ângulos (radianos). */
    private static function arc(float $cx, float $cy, float $rOut, float $rIn, float $a0, float $a1): string
    {
        // Um círculo completo não fecha com um único arco — encolhe um triz.
        if ($a1 - $a0 >= 2 * M_PI - 0.0001) {
            $a1 = $a0 + 2 * M_PI - 0.0001;
        }
        $large = ($a1 - $a0) > M_PI ? 1 : 0;
        $x0 = $cx + cos($a0) * $rOut;
        $y0 = $cy + sin($a0) * $rOut;
        $x1 = $cx + cos($a1) * $rOut;
        $y1 = $cy + sin($a1) * $rOut;
        $x2 = $cx + cos($a1) * $rIn;
        $y2 = $cy + sin($a1) * $rIn;
        $x3 = $cx + cos($a0) * $rIn;
        $y3 = $cy + sin($a0) * $rIn;
        return sprintf(
            'M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 0 %.2f %.2f Z',
            $x0, $y0, $rOut, $rOut, $large, $x1, $y1, $x2, $y2, $rIn, $rIn, $large, $x3, $y3
        );
    }

    // =====================================================================
    //  Auxiliares
    // =====================================================================

    /** Linhas de grade horizontais + rótulos do eixo Y. */
    private static function grid(int $padL, float $plotW, float $base, float $plotH, int $top, int $step): void
    {
        for ($v = 0; $v <= $top; $v += $step) {
            $y = round($base - ($v / $top) * $plotH, 1);
            echo "<line class='sr-ch-grid' x1='$padL' y1='$y' x2='" . round($padL + $plotW, 1) . "' y2='$y'/>";
            echo "<text class='sr-ch-axis' x='" . ($padL - 8) . "' y='" . ($y + 4) . "' text-anchor='end'>$v</text>";
        }
        echo "<line class='sr-ch-base' x1='$padL' y1='$base' x2='" . round($padL + $plotW, 1) . "' y2='$base'/>";
    }

    private static function nodata(): void
    {
        echo "<div class='text-muted text-center py-4'><i class='ti ti-alert-circle me-1'></i>"
            . __('Sem dados para construir o gráfico.', 'servicereports') . "</div>";
    }
}
