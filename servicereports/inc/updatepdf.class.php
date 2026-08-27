<?php
/**
 * "Relatório de atualização - Cliente" em PDF (TCPDF), A4 paisagem — capa +
 * uma seção por página, na ordem da tela.
 *
 * Herda de PluginServicereportsCentralpdf: cabeçalho, rodapé, moldura de
 * seção, grade, barras, barras horizontais e rosca são os mesmos do relatório
 * central — só o título muda. O que é próprio daqui:
 *   - `drawCombo()`, barras + linha de backlog num eixo que desce abaixo de
 *     zero (o `drawBars()` herdado só sobe);
 *   - `drawMonthTable()`, a tabela MÊS × INC × REQ do deck.
 *
 * Como no relatório central, os gráficos são **redesenhados** com primitivas
 * do TCPDF a partir do mesmo array de
 * `PluginServicereportsUpdatereport::getReport()` que alimenta o SVG da tela —
 * mexeu num, mexa no outro.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsUpdatepdf extends PluginServicereportsCentralpdf
{
    public static function title(): string
    {
        return __('Relatório de atualização - Cliente', 'servicereports');
    }

    // =====================================================================
    //  Capa
    // =====================================================================

    private function drawUpdateCover(array $d): void
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
        $this->Cell(200, 14, self::title(), 0, 2, 'L');

        $this->SetX(20);
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(...self::C_ACCENT);
        $this->Cell(200, 10, __('Dados do relatório', 'servicereports'), 0, 2, 'L');

        $this->SetDrawColor(...self::C_LINE);
        $this->Line(20, $this->GetY() + 2, 150, $this->GetY() + 2);

        $rows = [
            [__('Cliente', 'servicereports'), $d['client'] !== '' ? $d['client'] : '-'],
            [__('Chamados abertos', 'servicereports'), (string) (int) $d['total_open']],
            [__('Chamados fechados', 'servicereports'), (string) (int) $d['total_closed']],
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
    //  Legenda dos status (seção sem gráfico)
    // =====================================================================

    /** @param array<int,array{0:string,1:string}> $statuses */
    private function drawGlossary(array $statuses, float $y0): void
    {
        // Coluna fixa para o nome do status: com a largura variando por nome as
        // descrições começariam cada uma num x diferente.
        $nameX = 30.0;
        $descX = 66.0;
        $descW = self::W - $descX + 4;

        $y = $y0 + 4;
        foreach ($statuses as [$name, $desc]) {
            $this->SetFillColor(...self::C_ACCENT);
            $this->Rect(24, $y + 1.5, 2.6, 2.6, 'F');

            $this->SetXY($nameX, $y);
            $this->SetFont('helvetica', 'B', 10.5);
            $this->SetTextColor(...self::C_INK);
            $this->Cell($descX - $nameX - 3, 5.5, $name, 0, 0, 'L');

            // MultiCell (e não Cell): as descrições longas precisam quebrar.
            $this->SetFont('helvetica', '', 9.5);
            $this->SetTextColor(...self::C_SOFT);
            $this->SetXY($descX, $y);
            $this->MultiCell($descW, 5.5, self::plain($desc), 0, 'L');

            $y = max($this->GetY(), $y + 5.5) + 4;
        }
    }

    // =====================================================================
    //  Tabela MÊS × INC × REQ
    // =====================================================================

    /**
     * @param array<string,string>                  $months  'Y-m' => 'DEZ/25'
     * @param array<string,array{inc:int,req:int}>  $byMonth
     */
    private function drawMonthTable(array $months, array $byMonth, float $x0, float $y0, float $w): float
    {
        $cols = [$w * 0.44, $w * 0.28, $w * 0.28];
        // Cabeçalho + meses + total têm de caber na folha; num período longo a
        // linha encolhe em vez de invadir o rodapé.
        $h    = min(7.0, 120.0 / max(3, count($months) + 2));

        $this->SetFillColor(...self::C_HEAD);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetXY($x0, $y0);
        foreach ([__('Mês', 'servicereports'), __('INC', 'servicereports'), __('REQ', 'servicereports')] as $i => $label) {
            $this->Cell($cols[$i], $h, self::upper($label), 0, 0, 'C', true);
        }

        $y = $y0 + $h;
        $i = 0;
        $ti = 0;
        $tr = 0;
        foreach ($months as $key => $label) {
            $inc = (int) ($byMonth[$key]['inc'] ?? 0);
            $req = (int) ($byMonth[$key]['req'] ?? 0);
            $ti += $inc;
            $tr += $req;

            $zebra = $i++ % 2 === 1;
            if ($zebra) {
                $this->SetFillColor(244, 246, 248);
            }
            $this->SetTextColor(...self::C_INK);
            $this->SetFont('helvetica', '', $h >= 6 ? 9 : 7);
            $this->SetXY($x0, $y);
            $this->Cell($cols[0], $h, $label, 0, 0, 'C', $zebra);
            $this->Cell($cols[1], $h, (string) $inc, 0, 0, 'C', $zebra);
            $this->Cell($cols[2], $h, (string) $req, 0, 0, 'C', $zebra);
            $y += $h;
        }

        $this->SetDrawColor(...self::C_LINE);
        $this->Line($x0, $y, $x0 + $w, $y);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(...self::C_INK);
        $this->SetXY($x0, $y + 0.5);
        $this->Cell($cols[0], $h, self::upper(__('Total', 'servicereports')), 0, 0, 'C');
        $this->Cell($cols[1], $h, (string) $ti, 0, 0, 'C');
        $this->Cell($cols[2], $h, (string) $tr, 0, 0, 'C');

        return $y + $h + 0.5;
    }

    // =====================================================================
    //  Rosca por tipo — à direita da tabela, legenda por baixo
    // =====================================================================

    /**
     * O `drawDonut()` herdado ocupa a folha inteira (legenda à esquerda, em
     * x=40) e passaria por cima da tabela de meses. Aqui a rosca fica na
     * metade direita e a legenda desce para debaixo dela.
     *
     * @param array<int,array{label:string,value:int,color:array}> $slices
     */
    private function drawTypeDonut(array $slices, float $y0, float $cx): void
    {
        $total = 0;
        foreach ($slices as $s) {
            $total += (int) $s['value'];
        }
        if ($total <= 0) {
            $this->SetXY($cx - 45, $y0 + 40);
            $this->SetFont('helvetica', '', 9);
            $this->SetTextColor(...self::C_SOFT);
            $this->Cell(90, 6, __('Sem dados para construir o gráfico.', 'servicereports'), 0, 0, 'C');
            return;
        }

        $cy   = $y0 + 52;
        $rOut = 44.0;
        $rIn  = 22.0;

        $acc = 0.0;
        foreach ($slices as $s) {
            $v = (int) $s['value'];
            if ($v <= 0) {
                continue;
            }
            $sweep = 360 * $v / $total;
            $this->SetFillColor(...$s['color']);
            // cw=true a partir das 12h ($o=90): mesma direção do SVG da tela —
            // com false a rosca sai anti-horária e o rótulo cai na fatia errada.
            $this->PieSector($cx, $cy, $rOut, $acc, $acc + $sweep, 'F', true, 90);
            if ($sweep > 18) {
                $mid = deg2rad(90 - ($acc + $sweep / 2));
                $lr  = ($rOut + $rIn) / 2;
                $this->SetFont('helvetica', 'B', 9);
                $this->SetTextColor(255, 255, 255);
                $this->SetXY($cx + cos($mid) * $lr - 10, $cy - sin($mid) * $lr - 2);
                $this->Cell(20, 4, (string) $v, 0, 0, 'C');
            }
            $acc += $sweep;
        }

        $this->SetFillColor(255, 255, 255);
        $this->Circle($cx, $cy, $rIn, 0, 360, 'F');

        $y = $cy + $rOut + 6;
        foreach ($slices as $s) {
            $v    = (int) $s['value'];
            $pct  = number_format($v / $total * 100, 1, ',', '.');
            $text = $v . ' - ' . $s['label'] . '  (' . $pct . '%)';
            $this->SetFont('helvetica', '', 9);
            $w = $this->GetStringWidth($text) + 2;
            $this->SetFillColor(...$s['color']);
            $this->Rect($cx - ($w + 6) / 2, $y + 1.6, 3.4, 3.4, 'F');
            $this->SetTextColor(...self::C_INK);
            $this->SetXY($cx - ($w + 6) / 2 + 5.5, $y);
            $this->Cell($w, 6, $text, 0, 0, 'L');
            $y += 6.5;
        }
    }

    // =====================================================================
    //  Barras agrupadas + linha (chamados por dia × backlog)
    // =====================================================================

    /**
     * @param array<int,string> $labels
     * @param array<int,array{name:string,color:array,data:array<int,int>}> $series
     * @param array{name:string,color:array,data:array<int,int>} $line
     */
    private function drawCombo(array $labels, array $series, array $line, float $y0): void
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
        $max = max($max, (int) max($line['data'] ?: [0]));
        $min = (int) min($line['data'] ?: [0]);
        [$bottom, $top, $step] = PluginServicereportsChart::niceRange($min, $max);

        $base = $y0 + $plotH;
        $span = max(1, $top - $bottom);
        $yOf  = static fn (float $v): float => $base - (($v - $bottom) / $span) * $plotH;

        $this->SetFont('helvetica', '', 6.5);
        for ($v = $bottom; $v <= $top; $v += $step) {
            $y = $yOf((float) $v);
            $this->SetDrawColor(...self::C_LINE);
            $this->Line($x0, $y, $x1, $y);
            $this->SetTextColor(...self::C_FAINT);
            $this->SetXY(10, $y - 2);
            $this->Cell($x0 - 12, 4, (string) $v, 0, 0, 'R');
        }

        // A base das barras é a linha do zero, não o pé da área de plotagem.
        $zero = $yOf(0);
        $this->SetDrawColor(...self::C_HEAD);
        $this->SetLineWidth(0.3);
        $this->Line($x0, $zero, $x1, $zero);
        $this->SetLineWidth(0.2);

        $slot     = $plotW / $n;
        $barW     = min(6.0, ($slot - 1.2) / $k);
        $showVals = $n <= 32;

        foreach (array_values($labels) as $i => $label) {
            $bx = $x0 + $i * $slot + ($slot - $barW * $k) / 2;
            foreach (array_values($series) as $j => $s) {
                $v = (int) ($s['data'][$i] ?? 0);
                if ($v <= 0) {
                    continue;
                }
                $x  = $bx + $j * $barW;
                $y  = $yOf((float) $v);
                $this->SetFillColor(...$s['color']);
                $this->Rect($x, $y, $barW, $zero - $y, 'F');
                if ($showVals) {
                    $this->SetFont('helvetica', '', 5.5);
                    $this->SetTextColor(...self::C_SOFT);
                    $this->SetXY($x - 4, $y - 3.6);
                    $this->Cell($barW + 8, 3.2, (string) $v, 0, 0, 'C');
                }
            }
        }

        $this->SetDrawColor(...$line['color']);
        $this->SetLineWidth(0.5);
        $prev = null;
        foreach (array_values($line['data']) as $i => $v) {
            $x = $x0 + $i * $slot + $slot / 2;
            $y = $yOf((float) $v);
            if ($prev !== null) {
                $this->Line($prev[0], $prev[1], $x, $y);
            }
            $prev = [$x, $y];
        }
        $this->SetLineWidth(0.2);
        $this->SetFillColor(...$line['color']);
        foreach (array_values($line['data']) as $i => $v) {
            $this->Circle($x0 + $i * $slot + $slot / 2, $yOf((float) $v), 1.1, 0, 360, 'F');
        }

        $this->xLabels($labels, $x0, $slot, $base, $n > 20, (int) max(1, ceil($n / 34)));
    }

    // =====================================================================
    //  Entrada
    // =====================================================================

    /**
     * Monta o relatório inteiro e devolve os bytes.
     *
     * @param array $d saída de PluginServicereportsUpdatereport::getReport()
     */
    public static function build(array $d): string
    {
        // d/m/Y fixo, como na tela — não `Html::convDate()`, que é preferência
        // de cada usuário e variaria o cabeçalho conforme quem imprime.
        $period = date('d/m/Y', strtotime(substr($d['start'], 0, 10))) . ' ' . __('a', 'servicereports') . ' '
                . date('d/m/Y', strtotime(substr($d['end'], 0, 10)));

        $pdf = new self(self::plain((string) $d['client']), $period, self::title());
        $pdf->drawUpdateCover($d);

        $navy  = self::rgb(PluginServicereportsChart::NAVY);
        $steel = self::rgb(PluginServicereportsChart::STEEL);
        $red   = self::rgb(PluginServicereportsChart::RED);

        // 2) Relatório de atendimentos — legenda dos status
        $y = $pdf->startSection(
            __('Relatório de atendimentos', 'servicereports'),
            __('O que cada status de chamado significa no acompanhamento do atendimento.', 'servicereports')
        );
        $pdf->drawGlossary($d['statuses'], $y);

        // 3) Chamados por mês
        $monthLabels = array_values($d['months']);
        $incData     = [];
        $reqData     = [];
        foreach (array_keys($d['months']) as $m) {
            $incData[] = (int) ($d['by_month'][$m]['inc'] ?? 0);
            $reqData[] = (int) ($d['by_month'][$m]['req'] ?? 0);
        }
        $y = $pdf->startSection(
            __('Chamados por mês', 'servicereports'),
            __('Chamados abertos em cada mês do período, separados por tipo.', 'servicereports'),
            [
                ['label' => array_sum($incData) . ' - ' . __('Incidente', 'servicereports'), 'color' => $navy],
                ['label' => array_sum($reqData) . ' - ' . __('Requisição', 'servicereports'), 'color' => $steel],
            ]
        );
        $pdf->drawBars($monthLabels, [
            ['name' => __('Incidente', 'servicereports'), 'color' => $navy, 'data' => $incData],
            ['name' => __('Requisição', 'servicereports'), 'color' => $steel, 'data' => $reqData],
        ], $y);

        // 4) Chamados por tipo — tabela + rosca
        $y = $pdf->startSection(
            __('Chamados por tipo', 'servicereports'),
            __('Incidente e Requisição são os dois tipos de chamado do GLPI; o total é o de chamados '
             . 'abertos no período.', 'servicereports')
        );
        $pdf->drawMonthTable($d['months'], $d['by_month'], 24.0, $y + 6, 96.0);
        $pdf->drawTypeDonut([
            ['label' => __('Incidente', 'servicereports'), 'value' => (int) $d['types']['inc'], 'color' => $navy],
            ['label' => __('Requisição', 'servicereports'), 'value' => (int) $d['types']['req'], 'color' => $steel],
        ], $y, 205.0);

        // 5) Top 5 chamados por categoria
        $y = $pdf->startSection(
            __('Top 5 - Chamados por categoria', 'servicereports'),
            sprintf(
                __('As 5 categorias com mais chamados abertos no período (de %s no total).', 'servicereports'),
                (int) $d['categories']['total']
            ),
            [['label' => __('Chamados abertos', 'servicereports'), 'color' => $navy]]
        );
        $pdf->drawHBars($d['categories']['rows'], $navy, $y);

        // 6) Chamados por dia
        $opened  = array_values($d['opened']);
        $closed  = array_values($d['closed']);
        $backlog = array_values($d['backlog']);
        $last    = $backlog === [] ? 0 : (int) end($backlog);
        $y = $pdf->startSection(
            __('Chamados por dia', 'servicereports'),
            sprintf(
                __('Abertos pela data de abertura e fechados pela data de fechamento. O backlog é a fila '
                 . 'acumulada: parte dos %d chamados que já estavam em aberto na véspera do período e, a cada '
                 . 'dia, soma os abertos e subtrai os fechados.', 'servicereports'),
                (int) $d['backlog_initial']
            ),
            [
                ['label' => array_sum($opened) . ' - ' . __('Aberto', 'servicereports'), 'color' => $navy],
                ['label' => array_sum($closed) . ' - ' . __('Fechado', 'servicereports'), 'color' => $steel],
                ['label' => $last . ' - ' . __('Backlog', 'servicereports'), 'color' => $red],
            ]
        );
        $pdf->drawCombo(
            array_values($d['days']),
            [
                ['name' => __('Aberto', 'servicereports'), 'color' => $navy, 'data' => $opened],
                ['name' => __('Fechado', 'servicereports'), 'color' => $steel, 'data' => $closed],
            ],
            ['name' => __('Backlog', 'servicereports'), 'color' => $red, 'data' => $backlog],
            $y
        );

        // 7) Chamados por horário
        $hourLabels = [];
        $hourData   = [];
        foreach ($d['hours'] as $h) {
            $hourLabels[] = $h['label'];
            $hourData[]   = (int) $h['value'];
        }
        $y = $pdf->startSection(
            __('Chamados por horário', 'servicereports'),
            __('Chamados abertos por hora do dia, pela hora de abertura. Só as horas com pelo menos um '
             . 'chamado aparecem.', 'servicereports'),
            [['label' => array_sum($hourData) . ' - ' . __('Chamados abertos', 'servicereports'), 'color' => $navy]]
        );
        $pdf->drawBars($hourLabels, [
            ['name' => __('Chamados abertos', 'servicereports'), 'color' => $navy, 'data' => $hourData],
        ], $y);

        return $pdf->Output('', 'S');
    }
}
