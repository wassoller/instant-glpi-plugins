<?php
/**
 * "Chamados por grupo" em PDF (TCPDF), A4 paisagem — capa, gráfico e tabela.
 *
 * Estende PluginServicereportsCentralpdf: cabeçalho, rodapé, `startSection()`
 * e `drawHBars()` vêm de lá, com o mesmo layout "Institucional" dos outros
 * relatórios do bloco. Ao mexer no `centralpdf`, lembre que **quatro**
 * relatórios usam aquele código (central, ANUAL, MENSAL e este).
 *
 * O array de entrada é o de PluginServicereportsGroupreport::getReport() — o
 * mesmo da tela, para papel e tela baterem.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsGroupreportpdf extends PluginServicereportsCentralpdf
{
    /** Larguras da tabela: Grupo + Chamados + % (soma = W). */
    private const COLS = [177.0, 50.0, 50.0];

    /** Altura de uma linha da tabela. */
    private const ROW_H = 6.5;

    /** Capa: título, cliente, números do período. */
    private function drawCoverPage(array $d): void
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
        $this->Cell(200, 14, PluginServicereportsGroupreport::title(), 0, 2, 'L');

        $this->SetX(20);
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(...self::C_ACCENT);
        $this->Cell(200, 10, __('Dados do relatório', 'servicereports'), 0, 2, 'L');

        $this->SetDrawColor(...self::C_LINE);
        $this->Line(20, $this->GetY() + 2, 150, $this->GetY() + 2);

        $rows = [
            [__('Cliente', 'servicereports'), $d['client'] !== '' ? $d['client'] : '-'],
            [__('Chamados no período', 'servicereports'), (string) (int) $d['total_tickets']],
            [__('Grupos com chamado', 'servicereports'), (string) (int) $d['groups']],
            [__('Sem grupo atribuído', 'servicereports'), (string) (int) $d['no_group']],
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

    /** Cabeçalho escuro da tabela. */
    private function drawTableHead(float $y): float
    {
        $this->SetFillColor(...self::C_HEAD);
        $this->Rect(10, $y, self::W, 7.0, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 7);

        $cells = [
            [__('Grupo', 'servicereports'), 'L'],
            [__('Chamados', 'servicereports'), 'C'],
            [__('% do total', 'servicereports'), 'C'],
        ];
        $x = 10.0;
        foreach ($cells as $k => [$text, $align]) {
            $this->SetXY($x, $y);
            $this->Cell(self::COLS[$k], 7.0, self::upper((string) $text), 0, 0, $align);
            $x += self::COLS[$k];
        }
        return $y + 7.0;
    }

    /**
     * Tabela grupo × chamados. O `SetAutoPageBreak` do bloco é **false** (uma
     * seção por folha), então a quebra é manual: com muitos grupos a tabela
     * continua na folha seguinte, com o cabeçalho repetido.
     */
    private function drawTable(array $d, float $y): void
    {
        $limit = $this->getPageHeight() - 20;
        $y     = $this->drawTableHead($y);
        $zebra = false;

        foreach ($d['rows'] as $row) {
            if ($y + self::ROW_H > $limit) {
                $y     = $this->startSection(PluginServicereportsGroupreport::tableTitle(), '');
                $y     = $this->drawTableHead($y);
                $zebra = false;
            }
            if ($zebra) {
                $this->SetFillColor(245, 248, 250);
                $this->Rect(10, $y, self::W, self::ROW_H, 'F');
            }
            $zebra = !$zebra;

            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(...self::C_INK);
            $this->SetXY(10, $y);
            $this->Cell(self::COLS[0], self::ROW_H, self::shorten(self::plain((string) $row['label']), 120), 0, 0, 'L');
            $this->SetFont('helvetica', 'B', 7.5);
            $this->SetTextColor(...self::C_ACCENT);
            $this->SetXY(10 + self::COLS[0], $y);
            $this->Cell(self::COLS[1], self::ROW_H, (string) (int) $row['value'], 0, 0, 'C');
            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(...self::C_SOFT);
            $this->SetXY(10 + self::COLS[0] + self::COLS[1], $y);
            $this->Cell(self::COLS[2], self::ROW_H, (string) $row['note'], 0, 0, 'C');

            $this->SetDrawColor(...self::C_LINE);
            $this->Line(10, $y + self::ROW_H, 10 + self::W, $y + self::ROW_H);
            $y += self::ROW_H;
        }

        // Rodapé de totais: a soma das linhas (não os chamados distintos).
        if ($y + 8 > $limit) {
            $y = $this->startSection(PluginServicereportsGroupreport::tableTitle(), '');
            $y = $this->drawTableHead($y);
        }
        $this->SetFillColor(245, 248, 250);
        $this->Rect(10, $y, self::W, 7.5, 'F');
        $this->SetDrawColor(...self::C_HEAD);
        $this->SetLineWidth(0.3);
        $this->Line(10, $y, 10 + self::W, $y);
        $this->SetLineWidth(0.2);

        $this->SetFont('helvetica', 'B', 7.5);
        $this->SetTextColor(...self::C_INK);
        $this->SetXY(10, $y);
        $this->Cell(self::COLS[0], 7.5, self::upper(__('Total', 'servicereports')), 0, 0, 'L');
        $this->SetTextColor(...self::C_ACCENT);
        $this->SetXY(10 + self::COLS[0], $y);
        $this->Cell(self::COLS[1], 7.5, (string) (int) $d['total_links'], 0, 0, 'C');
        $this->SetTextColor(...self::C_INK);
        $this->SetXY(10 + self::COLS[0] + self::COLS[1], $y);
        $this->Cell(self::COLS[2], 7.5, $d['total_links'] > 0 ? '100,00%' : '', 0, 0, 'C');
    }

    /**
     * Monta o relatório e devolve os bytes: capa, gráfico e tabela.
     *
     * @param array $d saída de PluginServicereportsGroupreport::getReport()
     */
    public static function build(array $d): string
    {
        // d/m/Y fixo, como na tela — não `Html::convDate()`, que é preferência
        // de cada usuário e mudaria o cabeçalho conforme quem imprime.
        $period = date('d/m/Y', strtotime(substr($d['start'], 0, 10))) . ' ' . __('a', 'servicereports') . ' '
                . date('d/m/Y', strtotime(substr($d['end'], 0, 10)));

        $pdf = new self(self::plain((string) $d['client']), $period, PluginServicereportsGroupreport::title());
        $pdf->drawCoverPage($d);

        $navy = self::rgb(PluginServicereportsChart::NAVY);

        // 2) Gráfico
        $y = $pdf->startSection(
            PluginServicereportsGroupreport::chartTitle(),
            PluginServicereportsGroupreport::hint(),
            [['label' => (int) $d['total_links'] . ' - ' . __('Chamados', 'servicereports'), 'color' => $navy]]
        );
        $pdf->drawHBars($d['rows'], $navy, $y);

        // 3) Tabela
        $y = $pdf->startSection(
            PluginServicereportsGroupreport::tableTitle(),
            sprintf(
                __('%1$d chamados no período · %2$d grupos com chamado · %3$d sem grupo atribuído.', 'servicereports'),
                (int) $d['total_tickets'],
                (int) $d['groups'],
                (int) $d['no_group']
            )
        );
        $pdf->drawTable($d, $y);

        return $pdf->Output('', 'S');
    }
}
