<?php
/**
 * "Relatório central de serviços" em PDF (TCPDF), A4 paisagem — capa + uma
 * seção por página, na ordem da tela.
 *
 * Os gráficos são **redesenhados** com primitivas do TCPDF a partir do mesmo
 * array que alimenta o SVG da tela (PluginServicereportsServicecentral::getReport()),
 * com as cores de PluginServicereportsChart — é o que garante que papel e tela
 * batem. Layout "Institucional", igual ao do extrato financeiro.
 *
 * O TCPDF já vem no vendor do GLPI 10 (o core usa em `GLPIPDF`); nada a instalar.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsCentralpdf extends TCPDF
{
    /** Largura útil da folha (A4 paisagem, margens de 10mm). */
    protected const W = 277.0;

    // Paleta do layout "Institucional" (mesma do extrato).
    protected const C_INK    = [22, 32, 42];
    protected const C_SOFT   = [92, 107, 121];
    protected const C_FAINT  = [139, 152, 165];
    protected const C_LINE   = [216, 222, 228];
    protected const C_HEAD   = [34, 49, 64];
    protected const C_ACCENT = [15, 111, 140];

    /** Título da seção corrente — o cabeçalho é redesenhado a cada folha. */
    public string $sectionTitle = '';
    /** A capa não leva a faixa de cabeçalho. */
    protected bool $isCover = false;

    protected string $client = '';
    protected string $period = '';
    protected string $printedBy = '';
    /** Nome do relatório — sai no subtítulo do cabeçalho e no rodapé. */
    protected string $reportTitle = '';

    public function __construct(string $client, string $period, string $reportTitle = '')
    {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8');

        $this->client      = $client;
        $this->period      = $period;
        $this->printedBy   = getUserName(Session::getLoginUserID());
        $this->reportTitle = $reportTitle !== ''
            ? $reportTitle
            : __('Relatório central de serviços', 'servicereports');

        $this->SetCreator('GLPI · servicereports');
        $this->SetTitle($this->reportTitle);
        $this->SetMargins(10, 30, 10);
        $this->SetAutoPageBreak(false); // uma seção por folha, sem quebra automática
        $this->setImageScale(1.25);
        $this->setHeaderMargin(5);
        $this->setFooterMargin(10);
        $this->SetFont('helvetica', '', 8);
    }

    // =====================================================================
    //  Cabeçalho e rodapé
    // =====================================================================

    public function Header() // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        if ($this->isCover) {
            return;
        }

        $logo = self::logoFile();
        $x    = 10.0;
        $y    = 8.0;

        if ($logo !== '') {
            $this->Image($logo, $x, $y - 1, 0, 12, '', '', '', true, 300);
            $x += 15;
        }

        $this->SetXY($x, $y);
        $this->SetTextColor(...self::C_INK);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(130, 6, $this->sectionTitle, 0, 2, 'L');
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(...self::C_SOFT);
        $this->Cell(130, 4, $this->reportTitle, 0, 0, 'L');

        $rows = [
            [__('Cliente', 'servicereports'), $this->client],
            [__('Período', 'servicereports'), $this->period],
            [__('Emissão', 'servicereports'), Html::convDateTime(date('Y-m-d H:i:s'))],
        ];
        $ry = $y;
        foreach ($rows as [$label, $value]) {
            $this->SetXY(150, $ry);
            $this->SetFont('helvetica', '', 6.5);
            $this->SetTextColor(...self::C_FAINT);
            $this->Cell(40, 4, self::upper($label), 0, 0, 'R');
            $this->SetFont('helvetica', 'B', 7.5);
            $this->SetTextColor(...self::C_INK);
            $this->Cell(97, 4, $value, 0, 0, 'R');
            $ry += 4.2;
        }

        $this->SetLineWidth(0.5);
        $this->SetDrawColor(...self::C_HEAD);
        $this->Line(10, 24.5, 10 + self::W, 24.5);
        $this->SetLineWidth(0.2);
    }

    public function Footer() // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        $this->SetY(-12);
        $this->SetDrawColor(...self::C_LINE);
        $this->Line(10, $this->GetY(), 10 + self::W, $this->GetY());

        $this->SetY(-11);
        $this->SetFont('helvetica', '', 6.5);
        $this->SetTextColor(...self::C_FAINT);
        $this->Cell(self::W / 2, 5, $this->reportTitle . ' · ' . $this->client, 0, 0, 'L');
        $this->Cell(
            self::W / 2,
            5,
            sprintf(
                __('Impresso por %1$s em %2$s', 'servicereports'),
                $this->printedBy,
                Html::convDateTime(date('Y-m-d H:i:s'))
            ) . ' · ' . sprintf(
                __('Página %1$s de %2$s', 'servicereports'),
                $this->getAliasNumPage(),
                $this->getAliasNbPages()
            ),
            0,
            0,
            'R'
        );
    }

    // =====================================================================
    //  Capa
    // =====================================================================

    private function drawCover(array $d): void
    {
        $this->isCover = true;
        $this->AddPage();

        $logo = self::logoFile();
        if ($logo !== '') {
            $this->Image($logo, 232, 18, 0, 22, '', '', '', true, 300);
        }

        $this->SetXY(20, 62);
        $this->SetTextColor(...self::C_INK);
        $this->SetFont('helvetica', 'B', 30);
        $this->Cell(200, 14, __('Relatório central de serviços', 'servicereports'), 0, 2, 'L');

        $this->SetX(20);
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(...self::C_ACCENT);
        $this->Cell(200, 10, __('Dados do relatório', 'servicereports'), 0, 2, 'L');

        $this->SetDrawColor(...self::C_LINE);
        $this->Line(20, $this->GetY() + 2, 150, $this->GetY() + 2);

        $rows = [
            [__('Cliente', 'servicereports'), $d['client'] !== '' ? $d['client'] : '-'],
            [__('Total de chamados abertos', 'servicereports'), (string) (int) $d['total_open']],
            [__('Período', 'servicereports'), $this->period],
        ];
        $y = $this->GetY() + 8;
        foreach ($rows as [$label, $value]) {
            $this->SetXY(20, $y);
            $this->SetFont('helvetica', 'B', 11);
            $this->SetTextColor(...self::C_SOFT);
            $w = $this->GetStringWidth($label . ': ') + 1;
            $this->Cell($w, 7, $label . ': ', 0, 0, 'L');
            $this->SetFont('helvetica', '', 11);
            $this->SetTextColor(...self::C_INK);
            $this->Cell(160, 7, $value, 0, 0, 'L');
            $y += 8;
        }

        $this->isCover = false;
    }

    // =====================================================================
    //  Moldura de uma seção (título na faixa + explicação + legenda)
    // =====================================================================

    /** @param array<int,array{label:string,color:array}> $keys */
    protected function startSection(string $title, string $hint, array $keys = []): float
    {
        $this->sectionTitle = $title;
        $this->AddPage();

        $this->SetXY(10, 29);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(...self::C_SOFT);
        $this->MultiCell(self::W, 4, $hint, 0, 'L');

        $y = max($this->GetY() + 2, 37.0);
        if (!empty($keys)) {
            $totalW = 0.0;
            $this->SetFont('helvetica', '', 7.5);
            foreach ($keys as $k) {
                $totalW += $this->GetStringWidth($k['label']) + 11;
            }
            $x = 10 + (self::W - $totalW) / 2;
            foreach ($keys as $k) {
                $this->SetFillColor(...$k['color']);
                $this->Rect($x, $y + 1.2, 3.2, 3.2, 'F');
                $this->SetTextColor(...self::C_SOFT);
                $this->SetXY($x + 4.5, $y);
                $w = $this->GetStringWidth($k['label']) + 6.5;
                $this->Cell($w, 5.5, $k['label'], 0, 0, 'L');
                $x += $w + 4.5;
            }
            $y += 8;
        }
        return $y;
    }

    // =====================================================================
    //  Gráficos
    // =====================================================================

    /** Grade horizontal + eixo Y. Devolve [topo, base]. */
    protected function grid(float $x0, float $x1, float $y0, float $plotH, int $top, int $step): float
    {
        $base = $y0 + $plotH;
        $this->SetFont('helvetica', '', 6.5);
        for ($v = 0; $v <= $top; $v += $step) {
            $y = $base - ($v / $top) * $plotH;
            $this->SetDrawColor(...self::C_LINE);
            $this->Line($x0, $y, $x1, $y);
            $this->SetTextColor(...self::C_FAINT);
            $this->SetXY(10, $y - 2);
            $this->Cell($x0 - 12, 4, (string) $v, 0, 0, 'R');
        }
        $this->SetDrawColor(...self::C_HEAD);
        $this->SetLineWidth(0.3);
        $this->Line($x0, $base, $x1, $base);
        $this->SetLineWidth(0.2);
        return $base;
    }

    /** Rótulos do eixo X, girados 40° quando são muitos. */
    protected function xLabels(array $labels, float $x0, float $slot, float $base, bool $rotate, int $every): void
    {
        $this->SetFont('helvetica', '', 6);
        $this->SetTextColor(...self::C_INK);
        foreach (array_values($labels) as $i => $label) {
            if ($i % $every !== 0) {
                continue;
            }
            $px = $x0 + $i * $slot + $slot / 2;
            if ($rotate) {
                $py = $base + 2.5;
                $this->StartTransform();
                $this->Rotate(40, $px, $py);
                // x=0 até o pé da coluna, texto à direita: `SetXY` com x negativo
                // no TCPDF significa "a partir da borda direita" e o rótulo somem.
                $this->SetXY(0, $py - 1.6);
                $this->Cell($px, 3.2, (string) $label, 0, 0, 'R');
                $this->StopTransform();
            } else {
                $this->SetXY($px - 10, $base + 1.5);
                $this->Cell(20, 4, (string) $label, 0, 0, 'C');
            }
        }
    }

    /** Linha com marcadores (Total de atendimento). */
    protected function drawLine(array $labels, array $values, array $color, float $y0): void
    {
        $x0    = 24.0;
        $x1    = 10.0 + self::W;
        $plotW = $x1 - $x0;
        $plotH = 108.0;
        $n     = max(1, count($values));
        [$top, $step] = PluginServicereportsAnalysts::niceScale((int) max($values ?: [0]));
        $base  = $this->grid($x0, $x1, $y0, $plotH, $top, $step);
        $slot  = $plotW / $n;

        // Linha.
        $this->SetDrawColor(...$color);
        $this->SetLineWidth(0.4);
        $prev = null;
        foreach (array_values($values) as $i => $v) {
            $x = $x0 + $i * $slot + $slot / 2;
            $y = $base - ($v / $top) * $plotH;
            if ($prev !== null) {
                $this->Line($prev[0], $prev[1], $x, $y);
            }
            $prev = [$x, $y];
        }
        $this->SetLineWidth(0.2);

        // Marcadores + números.
        $showVals = $n <= 45;
        foreach (array_values($values) as $i => $v) {
            $x = $x0 + $i * $slot + $slot / 2;
            $y = $base - ($v / $top) * $plotH;
            $this->SetFillColor(...$color);
            $this->Circle($x, $y, 1.2, 0, 360, 'F');
            if ($showVals && $v > 0) {
                $this->SetFont('helvetica', 'B', 6);
                $this->SetTextColor(...self::C_SOFT);
                $this->SetXY($x - 8, $y - 6);
                $this->Cell(16, 3.5, (string) (int) $v, 0, 0, 'C');
            }
        }

        $this->xLabels($labels, $x0, $slot, $base, $n > 20, (int) max(1, ceil($n / 34)));
    }

    /**
     * Barras verticais agrupadas.
     *
     * @param array<int,array{name:string,color:array,data:array<int,int>}> $series
     */
    protected function drawBars(array $labels, array $series, float $y0): void
    {
        $x0    = 24.0;
        $x1    = 10.0 + self::W;
        $plotW = $x1 - $x0;
        $plotH = 108.0;
        $n     = max(1, count($labels));
        $k     = max(1, count($series));

        $max = 0;
        foreach ($series as $s) {
            $max = max($max, (int) max($s['data'] ?: [0]));
        }
        [$top, $step] = PluginServicereportsAnalysts::niceScale($max);
        $base = $this->grid($x0, $x1, $y0, $plotH, $top, $step);

        $slot = $plotW / $n;
        $barW = min(6.0, ($slot - 1.2) / $k);
        $showVals = $n <= 32;

        foreach (array_values($labels) as $i => $label) {
            $bx = $x0 + $i * $slot + ($slot - $barW * $k) / 2;
            foreach (array_values($series) as $j => $s) {
                $v = (int) ($s['data'][$i] ?? 0);
                $x = $bx + $j * $barW;
                if ($v > 0) {
                    $bh = ($v / $top) * $plotH;
                    $this->SetFillColor(...$s['color']);
                    $this->Rect($x, $base - $bh, $barW, $bh, 'F');
                    if ($showVals) {
                        $this->SetFont('helvetica', '', 5.5);
                        $this->SetTextColor(...self::C_SOFT);
                        $this->SetXY($x - 4, $base - $bh - 3.6);
                        $this->Cell($barW + 8, 3.2, (string) $v, 0, 0, 'C');
                    }
                }
            }
        }

        $this->xLabels($labels, $x0, $slot, $base, $n > 20, (int) max(1, ceil($n / 34)));
    }

    /**
     * Barras horizontais (top categorias / top usuários).
     *
     * @param array<int,array{label:string,value:int,note?:string}> $rows
     */
    protected function drawHBars(array $rows, array $color, float $y0): void
    {
        if (empty($rows)) {
            $this->SetXY(10, $y0);
            $this->SetFont('helvetica', '', 9);
            $this->SetTextColor(...self::C_SOFT);
            $this->Cell(self::W, 6, __('Sem dados para construir o gráfico.', 'servicereports'), 0, 1, 'L');
            return;
        }

        $labelW = 150.0;
        $x0     = 10.0 + $labelW;
        $x1     = 10.0 + self::W;
        $plotW  = $x1 - $x0;
        $rowH   = min(12.0, max(7.0, 110.0 / count($rows)));
        $barH   = min(6.0, $rowH - 3);

        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) $r['value']);
        }
        [$top, $step] = PluginServicereportsAnalysts::niceScale($max, 5);

        $bottom = $y0 + count($rows) * $rowH;
        $this->SetFont('helvetica', '', 6.5);
        for ($v = 0; $v <= $top; $v += $step) {
            $x = $x0 + ($v / $top) * $plotW;
            $this->SetDrawColor(...self::C_LINE);
            $this->Line($x, $y0, $x, $bottom);
            $this->SetTextColor(...self::C_FAINT);
            $this->SetXY($x - 10, $bottom + 1);
            $this->Cell(20, 4, (string) $v, 0, 0, 'C');
        }

        foreach (array_values($rows) as $i => $r) {
            $y    = $y0 + $i * $rowH;
            $bw   = $top > 0 ? ((int) $r['value'] / $top) * $plotW : 0;
            $note = (string) ($r['note'] ?? '');
            $text = (int) $r['value'] . ' - ' . self::plain((string) $r['label']) . ($note !== '' ? ': ' . $note : '');

            $this->SetFont('helvetica', '', 7);
            $this->SetTextColor(...self::C_INK);
            $this->SetXY(10, $y + ($rowH - 4) / 2);
            $this->Cell($labelW - 3, 4, self::shorten($text, 92), 0, 0, 'R');

            $this->SetFillColor(...$color);
            $this->Rect($x0, $y + ($rowH - $barH) / 2, max($bw, 0.4), $barH, 'F');
        }
    }

    /**
     * Rosca (nível de serviço): setor de pizza + miolo branco.
     *
     * @param array<int,array{label:string,value:int,color:array}> $slices
     */
    protected function drawDonut(array $slices, float $y0): void
    {
        $total = 0;
        foreach ($slices as $s) {
            $total += (int) $s['value'];
        }
        if ($total <= 0) {
            $this->SetXY(10, $y0);
            $this->SetFont('helvetica', '', 9);
            $this->SetTextColor(...self::C_SOFT);
            $this->Cell(self::W, 6, __('Sem dados para construir o gráfico.', 'servicereports'), 0, 1, 'L');
            return;
        }

        $cx   = 180.0;
        $cy   = $y0 + 55;
        $rOut = 48.0;
        $rIn  = 24.0;

        // PieSector: ângulos em graus, sentido horário a partir das 12h ($o=90).
        $acc = 0.0;
        foreach ($slices as $s) {
            $v = (int) $s['value'];
            if ($v <= 0) {
                continue;
            }
            $sweep = 360 * $v / $total;
            $this->SetFillColor(...$s['color']);
            // cw=true: ângulos crescem no sentido horário a partir das 12h — é a
            // mesma direção do SVG da tela, e é o que a conta do rótulo assume.
            $this->PieSector($cx, $cy, $rOut, $acc, $acc + $sweep, 'F', true, 90);
            // Número no meio da fatia, se ela for grande o bastante.
            if ($sweep > 18) {
                $mid = deg2rad(90 - ($acc + $sweep / 2)); // 12h = 90°, sentido horário
                $lr  = ($rOut + $rIn) / 2;
                $this->SetFont('helvetica', 'B', 8);
                $this->SetTextColor(255, 255, 255);
                $this->SetXY($cx + cos($mid) * $lr - 10, $cy - sin($mid) * $lr - 2);
                $this->Cell(20, 4, (string) $v, 0, 0, 'C');
            }
            $acc += $sweep;
        }

        // Miolo.
        $this->SetFillColor(255, 255, 255);
        $this->Circle($cx, $cy, $rIn, 0, 360, 'F');

        // Legenda à esquerda, com valor e percentual.
        $y = $cy - 8;
        foreach ($slices as $s) {
            $v   = (int) $s['value'];
            $pct = number_format($v / $total * 100, 1, ',', '.');
            $this->SetFillColor(...$s['color']);
            $this->Rect(40, $y + 1.4, 3.4, 3.4, 'F');
            $this->SetFont('helvetica', '', 9);
            $this->SetTextColor(...self::C_INK);
            $this->SetXY(46, $y);
            $this->Cell(70, 6, $v . ' - ' . $s['label'], 0, 0, 'L');
            $this->SetFont('helvetica', '', 8);
            $this->SetTextColor(...self::C_SOFT);
            $this->Cell(20, 6, $pct . '%', 0, 0, 'L');
            $y += 9;
        }
    }

    // =====================================================================
    //  Utilitários
    // =====================================================================

    protected static function logoFile(): string
    {
        foreach (['instant-logo.png', 'logo.png', 'logo.jpg'] as $file) {
            $path = GLPI_ROOT . '/plugins/servicereports/pics/' . $file;
            if (file_exists($path)) {
                return $path;
            }
        }
        return '';
    }

    /** Texto puro: o GLPI devolve conteúdo HTML-escapado (ex.: `&#62;`). */
    protected static function plain(string $v): string
    {
        return html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected static function upper(string $v): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
    }

    /** O `Cell()` do TCPDF não corta: transborda. */
    protected static function shorten(string $v, int $max): string
    {
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    }

    /** '#rrggbb' → [r,g,b]. */
    protected static function rgb(string $hex): array
    {
        return PluginServicereportsAnalysts::rgb($hex);
    }

    // =====================================================================
    //  Entrada
    // =====================================================================

    /**
     * Monta o relatório inteiro e devolve os bytes.
     *
     * @param array $d saída de PluginServicereportsServicecentral::getReport()
     */
    public static function build(array $d): string
    {
        // d/m/Y fixo, como na tela e no relatório original — não o formato de
        // data da preferência do usuário (`Html::convDate`), que aqui variaria
        // o cabeçalho de folha para folha conforme quem imprime.
        $period = date('d/m/Y', strtotime(substr($d['start'], 0, 10))) . ' ' . __('a', 'servicereports') . ' '
                . date('d/m/Y', strtotime(substr($d['end'], 0, 10)));

        $pdf = new self(self::plain((string) $d['client']), $period);
        $pdf->drawCover($d);

        $labels = array_values($d['days']);
        $navy   = self::rgb(PluginServicereportsChart::NAVY);
        $steel  = self::rgb(PluginServicereportsChart::STEEL);
        $green  = self::rgb(PluginServicereportsChart::GREEN);
        $red    = self::rgb(PluginServicereportsChart::RED);

        // 2) Total de atendimento
        $opened = array_values($d['opened']);
        $y = $pdf->startSection(
            __('Total de atendimento', 'servicereports'),
            __('Chamados abertos por dia, pela data de abertura.', 'servicereports'),
            [['label' => array_sum($opened) . ' - ' . __('Abertos por dia', 'servicereports'), 'color' => $navy]]
        );
        $pdf->drawLine($labels, $opened, $navy, $y);

        // 3) Atendimento diário
        $solved = array_values($d['solved']);
        $y = $pdf->startSection(
            __('Atendimento diário', 'servicereports'),
            __('Abertos pela data de abertura; encerrados pela data de solução — inclui os chamados que já '
             . 'avançaram para Fechado, por isso o total de encerrados pode passar o de abertos.', 'servicereports'),
            [
                ['label' => array_sum($opened) . ' - ' . __('Abertos', 'servicereports'), 'color' => $navy],
                ['label' => array_sum($solved) . ' - ' . __('Encerrados', 'servicereports'), 'color' => $steel],
            ]
        );
        $pdf->drawBars($labels, [
            ['name' => __('Abertos', 'servicereports'), 'color' => $navy, 'data' => $opened],
            ['name' => __('Encerrados', 'servicereports'), 'color' => $steel, 'data' => $solved],
        ], $y);

        // 4) Top categorias
        $y = $pdf->startSection(
            __('Atendimentos por categoria', 'servicereports'),
            sprintf(
                __('As 7 categorias com mais chamados abertos no período (de %s no total).', 'servicereports'),
                (int) $d['categories']['total']
            ),
            [['label' => __('Chamados abertos', 'servicereports'), 'color' => $navy]]
        );
        $pdf->drawHBars($d['categories']['rows'], $navy, $y);

        // 5) SLA — não conformidade
        $tto = array_values($d['late_tto']);
        $ttr = array_values($d['late_ttr']);
        $y = $pdf->startSection(
            __('Atendimento SLA — (Não conformidade)', 'servicereports'),
            __('Dos chamados abertos em cada dia: quantos estouraram o prazo para o analista ASSUMIR o chamado '
             . '(SLA de atendimento) e quantos estouraram o prazo de SOLUÇÃO. Chamado sem SLA não entra.', 'servicereports'),
            [
                ['label' => array_sum($tto) . ' - ' . __('Fora do SLA de atendimento', 'servicereports'), 'color' => $navy],
                ['label' => array_sum($ttr) . ' - ' . __('Fora do SLA de solução', 'servicereports'), 'color' => $steel],
            ]
        );
        $pdf->drawBars($labels, [
            ['name' => __('Fora do SLA de atendimento', 'servicereports'), 'color' => $navy, 'data' => $tto],
            ['name' => __('Fora do SLA de solução', 'servicereports'), 'color' => $steel, 'data' => $ttr],
        ], $y);

        // 6) SLA — nível de serviço
        $y = $pdf->startSection(
            __('Atendimento SLA — (Nível de serviço)', 'servicereports'),
            __('Chamados abertos no período, pelo prazo de solução. Chamado sem SLA definido conta como dentro do prazo.', 'servicereports')
        );
        $pdf->drawDonut([
            ['label' => __('Dentro do prazo', 'servicereports'), 'value' => (int) $d['sla']['dentro'], 'color' => $green],
            ['label' => __('Fora do prazo', 'servicereports'), 'value' => (int) $d['sla']['fora'], 'color' => $red],
        ], $y);

        // 7) Top usuários requerentes
        $y = $pdf->startSection(
            __('Top usuários requerentes', 'servicereports'),
            __('Os 10 usuários com mais chamados abertos no período, pelo ator Requerente.', 'servicereports'),
            [['label' => __('Chamados abertos', 'servicereports'), 'color' => $navy]]
        );
        $pdf->drawHBars($d['requesters']['rows'], $navy, $y);

        return $pdf->Output('', 'S');
    }
}
