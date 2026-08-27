<?php
/**
 * "Relatório de atualização - Cliente" (ANUAL e MENSAL) em PDF (TCPDF),
 * A4 paisagem — capa + uma seção por página, na ordem da tela.
 *
 * Herda de PluginServicereportsCentralpdf: cabeçalho, rodapé, moldura de
 * seção, grade, barras, barras horizontais e rosca são os mesmos do relatório
 * central. O que é próprio daqui: a capa, o glossário de status com a tabela de
 * totais, `drawBucketTable()` (a tabela MÊS/DIA × INC × REQ) e
 * `drawTypeDonut()` (a rosca ao lado dela).
 *
 * A **mesma** classe monta as duas variantes: o que muda é a granularidade dos
 * buckets, que já vem resolvida no array de
 * `PluginServicereportsUpdatereport::getReport()`.
 *
 * Como no relatório central, os gráficos são **redesenhados** com primitivas do
 * TCPDF a partir do mesmo array que alimenta o SVG da tela — mexeu num, mexa no
 * outro.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsUpdatepdf extends PluginServicereportsCentralpdf
{
    // =====================================================================
    //  Capa
    // =====================================================================

    /**
     * Faixa escura de sangria com o título e o cliente; sobre o branco, o
     * período, quatro números do período e a logo.
     *
     * A logo é escura e sumiria sobre a faixa — por isso fica na parte branca.
     */
    private function drawUpdateCover(array $d): void
    {
        $this->isCover = true;
        $this->AddPage();

        // Sangria: Rect() vai de x=0 à largura da folha, ignorando as margens.
        // É a única coisa da capa que encosta na borda.
        $this->SetFillColor(...self::C_HEAD);
        $this->Rect(0, 0, 297, 84, 'F');

        $this->SetXY(24, 24);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(...self::C_ACCENT);
        $this->Cell(200, 5, self::upper(__('Relatório', 'servicereports')), 0, 2, 'L');

        $this->SetX(24);
        $this->SetFont('helvetica', 'B', 26);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(250, 14, $this->reportTitle, 0, 2, 'L');

        $this->SetFillColor(...self::C_ACCENT);
        $this->Rect(24, 60, 46, 1.1, 'F');

        $this->SetXY(24, 65);
        $this->SetFont('helvetica', '', 13);
        $this->SetTextColor(226, 233, 240);
        $this->Cell(250, 8, $this->client !== '' ? $this->client : '-', 0, 0, 'L');

        // --- área branca ---
        $this->SetXY(24, 96);
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(...self::C_FAINT);
        $this->Cell(120, 4, self::upper(__('Período', 'servicereports')), 0, 2, 'L');
        $this->SetX(24);
        $this->SetFont('helvetica', 'B', 13);
        $this->SetTextColor(...self::C_INK);
        $this->Cell(120, 7, $this->period, 0, 0, 'L');

        $this->SetDrawColor(...self::C_LINE);
        $this->Line(24, 118, 273, 118);

        $stats = [
            [(int) $d['total_open'], __('Chamados abertos', 'servicereports')],
            [(int) $d['total_closed'], __('Chamados fechados', 'servicereports')],
            [(int) $d['types']['inc'], __('Incidentes', 'servicereports')],
            [(int) $d['types']['req'], __('Requisições', 'servicereports')],
        ];
        $colW = 249 / count($stats);
        $x    = 24.0;
        foreach ($stats as $i => [$value, $label]) {
            if ($i > 0) {
                // Filete entre as colunas, no lugar de caixas.
                $this->SetDrawColor(...self::C_LINE);
                $this->Line($x - 4, 126, $x - 4, 152);
            }
            $this->SetXY($x, 126);
            $this->SetFont('helvetica', 'B', 25);
            $this->SetTextColor(...self::C_HEAD);
            $this->Cell($colW - 8, 13, (string) $value, 0, 2, 'L');
            $this->SetX($x);
            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(...self::C_FAINT);
            $this->Cell($colW - 8, 4, self::upper($label), 0, 0, 'L');
            $x += $colW;
        }

        $logo = self::logoFile();
        if ($logo !== '') {
            $this->Image($logo, 243, 172, 0, 16, '', '', '', true, 300);
        }

        $this->isCover = false;
    }

    // =====================================================================
    //  Seção 2 — total por status + legenda
    // =====================================================================

    /**
     * A tabela do deck: faixa escura com "TOTAL DE CHAMADOS <PERÍODO>" e o
     * total à direita, e uma linha por status. Devolve o y de baixo.
     *
     * @param array<int,array{label:string,value:int}> $rows
     */
    private function drawStatusTable(array $rows, string $periodLabel, int $total, float $x0, float $y0, float $w): float
    {
        $h    = 8.0;
        $numW = 26.0;

        $this->SetFillColor(...self::C_HEAD);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 9.5);
        $this->SetXY($x0, $y0);
        $this->Cell(
            $w - $numW,
            $h,
            self::upper(sprintf(__('Total de chamados %s', 'servicereports'), $periodLabel)),
            0,
            0,
            'L',
            true
        );
        $this->Cell($numW, $h, (string) $total, 0, 0, 'R', true);

        $y = $y0 + $h;
        foreach (array_values($rows) as $i => $r) {
            $zebra = $i % 2 === 0;
            if ($zebra) {
                $this->SetFillColor(233, 238, 244);
            }
            $this->SetTextColor(...self::C_INK);
            $this->SetFont('helvetica', '', 9.5);
            $this->SetXY($x0, $y);
            $this->Cell($w - $numW, $h, self::plain((string) $r['label']), 0, 0, 'L', $zebra);
            $this->Cell($numW, $h, (string) (int) $r['value'], 0, 0, 'R', $zebra);
            $y += $h;
        }
        return $y;
    }

    /** @param array<int,array{0:string,1:string}> $statuses */
    private function drawGlossary(array $statuses, float $y0): void
    {
        // Coluna fixa para o nome do status: com a largura variando por nome as
        // descrições começariam cada uma num x diferente.
        $nameX = 30.0;
        $descX = 66.0;
        $descW = self::W - $descX + 4;

        $y = $y0;
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
    //  Tabela do bucket (MÊS ou DIA) × INC × REQ
    // =====================================================================

    /**
     * @param array<string,string>                 $buckets 'chave' => rótulo
     * @param array<string,array{inc:int,req:int}> $byType
     */
    private function drawBucketTable(
        array $buckets,
        array $byType,
        string $bucketLabel,
        float $x0,
        float $y0,
        float $w
    ): float {
        $cols = [$w * 0.44, $w * 0.28, $w * 0.28];
        // Cabeçalho + buckets + total têm de caber na folha; no MENSAL são 31
        // linhas, e com altura fixa a tabela invadiria o rodapé.
        $h    = min(7.0, 120.0 / max(3, count($buckets) + 2));
        $fs   = $h >= 6 ? 9 : ($h >= 4.5 ? 7.5 : 6);

        $this->SetFillColor(...self::C_HEAD);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', min(8.5, $fs + 0.5));
        $this->SetXY($x0, $y0);
        foreach ([$bucketLabel, __('INC', 'servicereports'), __('REQ', 'servicereports')] as $i => $label) {
            $this->Cell($cols[$i], $h, self::upper($label), 0, 0, 'C', true);
        }

        $y  = $y0 + $h;
        $i  = 0;
        $ti = 0;
        $tr = 0;
        foreach ($buckets as $key => $label) {
            $inc = (int) ($byType[$key]['inc'] ?? 0);
            $req = (int) ($byType[$key]['req'] ?? 0);
            $ti += $inc;
            $tr += $req;

            $zebra = $i++ % 2 === 1;
            if ($zebra) {
                $this->SetFillColor(244, 246, 248);
            }
            $this->SetTextColor(...self::C_INK);
            $this->SetFont('helvetica', '', $fs);
            $this->SetXY($x0, $y);
            $this->Cell($cols[0], $h, $label, 0, 0, 'C', $zebra);
            $this->Cell($cols[1], $h, (string) $inc, 0, 0, 'C', $zebra);
            $this->Cell($cols[2], $h, (string) $req, 0, 0, 'C', $zebra);
            $y += $h;
        }

        $this->SetDrawColor(...self::C_LINE);
        $this->Line($x0, $y, $x0 + $w, $y);
        $this->SetFont('helvetica', 'B', $fs);
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
     * x=40) e passaria por cima da tabela de buckets. Aqui a rosca fica na
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

        $pdf = new self(self::plain((string) $d['client']), $period, (string) $d['title']);
        $pdf->drawUpdateCover($d);

        $navy   = self::rgb(PluginServicereportsChart::NAVY);
        $steel  = self::rgb(PluginServicereportsChart::STEEL);
        $titles = $d['series_titles'];

        // 2) Relatório de atendimentos — total por status + legenda
        $y = $pdf->startSection(
            __('Relatório de atendimentos', 'servicereports'),
            __('Os chamados abertos no período pelo status em que estão agora, e o que cada status '
             . 'significa no acompanhamento do atendimento.', 'servicereports')
        );
        $y = $pdf->drawStatusTable(
            $d['by_status'],
            self::plain((string) $d['status_period']),
            (int) $d['total_open'],
            24.0,
            $y + 2,
            140.0
        );
        $pdf->drawGlossary($d['statuses'], $y + 12);

        // 3) Chamados por mês/dia, por tipo
        $bucketLabels = array_values($d['buckets']);
        $incData      = [];
        $reqData      = [];
        foreach (array_keys($d['buckets']) as $k) {
            $incData[] = (int) ($d['by_type'][$k]['inc'] ?? 0);
            $reqData[] = (int) ($d['by_type'][$k]['req'] ?? 0);
        }
        $y = $pdf->startSection(
            $titles['types'],
            __('Chamados abertos no período, separados por tipo.', 'servicereports'),
            [
                ['label' => array_sum($incData) . ' - ' . __('Incidente', 'servicereports'), 'color' => $navy],
                ['label' => array_sum($reqData) . ' - ' . __('Requisição', 'servicereports'), 'color' => $steel],
            ]
        );
        $pdf->drawBars($bucketLabels, [
            ['name' => __('Incidente', 'servicereports'), 'color' => $navy, 'data' => $incData],
            ['name' => __('Requisição', 'servicereports'), 'color' => $steel, 'data' => $reqData],
        ], $y);

        // 4) Chamados por tipo — tabela + rosca
        $y = $pdf->startSection(
            __('Chamados por tipo', 'servicereports'),
            __('Incidente e Requisição são os dois tipos de chamado do GLPI; o total é o de chamados '
             . 'abertos no período.', 'servicereports')
        );
        $pdf->drawBucketTable($d['buckets'], $d['by_type'], (string) $d['bucket_label'], 24.0, $y + 6, 96.0);
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

        // 6) Abertos × Fechados por mês/dia
        $opened = array_values($d['opened']);
        $closed = array_values($d['closed']);
        $y = $pdf->startSection(
            $titles['flow'],
            __('Abertos pela data de abertura e fechados pela data de fechamento (status Fechado; chamado '
             . 'só Solucionado ainda não conta).', 'servicereports'),
            [
                ['label' => array_sum($opened) . ' - ' . __('Aberto', 'servicereports'), 'color' => $navy],
                ['label' => array_sum($closed) . ' - ' . __('Fechado', 'servicereports'), 'color' => $steel],
            ]
        );
        $pdf->drawBars($bucketLabels, [
            ['name' => __('Aberto', 'servicereports'), 'color' => $navy, 'data' => $opened],
            ['name' => __('Fechado', 'servicereports'), 'color' => $steel, 'data' => $closed],
        ], $y);

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
