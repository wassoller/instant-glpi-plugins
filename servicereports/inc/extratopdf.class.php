<?php
/**
 * Extrato financeiro em PDF de verdade (TCPDF), no layout "Institucional".
 *
 * Por que TCPDF e não a impressão do navegador (2026-08-25): a rota antiga
 * (`Html::popHeader` + `window.print()`) **cortava dados** — as células da
 * listagem eram truncadas com reticências para as colunas alinharem, e
 * categoria/título longos chegavam ao papel pela metade. Aqui cada célula é
 * um `MultiCell` que **quebra em linhas**, a altura da linha vem da coluna
 * mais alta, e o cabeçalho/rodapé se repetem em toda folha com "Página X de Y"
 * (`getAliasNumPage()`/`getAliasNbPages()`), o que HTML impresso não faz.
 *
 * O TCPDF já vem no vendor do GLPI 10 (o core usa em `GLPIPDF`); nada a instalar.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsExtratopdf extends TCPDF
{
    /** Largura útil da folha (A4 paisagem, margens de 10mm). */
    private const W = 277.0;

    /**
     * Larguras das 10 colunas da listagem de chamados (soma = W).
     * As três últimas são largas para o rótulo caber: "CUSTO CHAMADO" em caixa
     * alta transborda a célula e colide com a vizinha se a coluna for estreita.
     */
    private const COLS = [12.0, 49.0, 18.0, 49.0, 28.0, 27.0, 27.0, 16.0, 24.0, 27.0];

    // Paleta do layout "Institucional" (mesma do CSS em financial.class.php).
    private const C_INK   = [22, 32, 42];
    private const C_SOFT  = [92, 107, 121];
    private const C_FAINT = [139, 152, 165];
    private const C_LINE  = [216, 222, 228];
    private const C_HAIR  = [234, 238, 242];
    private const C_HEAD  = [34, 49, 64];
    private const C_ZEBRA = [245, 248, 250];
    private const C_BAND  = [240, 244, 247];
    private const C_ACCENT = [15, 111, 140];

    /** Empresa da página corrente (o cabeçalho é redesenhado a cada folha). */
    public string $entityName = '';
    private string $start = '';
    private string $end   = '';
    private string $printedBy = '';

    public function __construct(string $start, string $end)
    {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8');

        $this->start     = $start;
        $this->end       = $end;
        $this->printedBy = getUserName(Session::getLoginUserID());

        $this->SetCreator('GLPI · servicereports');
        $this->SetTitle(__('Extrato de consumo de serviços', 'servicereports'));
        $this->SetMargins(10, 30, 10);
        $this->SetAutoPageBreak(true, 16);
        $this->setImageScale(1.25);
        // Sem o cabeçalho/rodapé padrão do TCPDF — usamos Header()/Footer() daqui.
        $this->setHeaderMargin(5);
        $this->setFooterMargin(10);
        $this->SetFont('helvetica', '', 8);
    }

    // =====================================================================
    //  Cabeçalho e rodapé — repetidos automaticamente em toda folha
    // =====================================================================

    public function Header() // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
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
        $this->Cell(120, 6, __('Extrato de consumo de serviços', 'servicereports'), 0, 2, 'L');
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(...self::C_SOFT);
        $this->Cell(120, 4, __('Serviços gerenciados', 'servicereports'), 0, 0, 'L');

        // Empresa / Período / Emissão, alinhados à direita.
        $rows = [
            [__('Empresa', 'servicereports'), $this->entityName],
            [__('Período', 'servicereports'), Html::convDate($this->start) . ' – ' . Html::convDate($this->end)],
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

        // Régua da faixa.
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
        $this->Cell(
            self::W / 2,
            5,
            __('Extrato de consumo de serviços', 'servicereports') . ' · ' . $this->entityName,
            0,
            0,
            'L'
        );
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
    //  Corpo
    // =====================================================================

    /** Resumo da entidade: 4 cartões (total em destaque) + linha fina. */
    public function drawSummary(array $s): void
    {
        $y   = $this->GetY();
        $gap = 3.0;
        $cards = [
            [__('Valor monetário total', 'servicereports'), PluginServicereportsFinancial::money($s['total']), true, 82.0],
            [__('Valores fixos', 'servicereports'), PluginServicereportsFinancial::money($s['fixos']), false, 62.0],
            [__('Valores de hora', 'servicereports'), PluginServicereportsFinancial::money($s['hora']), false, 62.0],
            [__('Valores de ativos', 'servicereports'), PluginServicereportsFinancial::money($s['ativos']), false, 62.0],
        ];

        $x = 10.0;
        $h = 16.0;
        foreach ($cards as [$label, $value, $lead, $w]) {
            if ($lead) {
                $this->SetFillColor(...self::C_BAND);
                $this->Rect($x, $y, $w, $h, 'F');
            }
            $this->SetDrawColor(...self::C_LINE);
            $this->Rect($x, $y, $w, $h, 'D');
            // Filete superior: azul no cartão do total, cinza nos demais.
            $this->SetFillColor(...($lead ? self::C_ACCENT : self::C_LINE));
            $this->Rect($x, $y, $w, 1.0, 'F');

            $this->SetXY($x + 3, $y + 2.5);
            $this->SetFont('helvetica', 'B', 6);
            $this->SetTextColor(...self::C_FAINT);
            $this->Cell($w - 6, 3, self::upper($label), 0, 2, 'L');

            $this->SetFont('helvetica', 'B', $lead ? 15 : 13);
            if ($lead) {
                $this->SetTextColor(...self::C_ACCENT);
            } else {
                $this->SetTextColor(...self::C_INK);
            }
            $this->SetXY($x + 3, $y + 6.5);
            $this->Cell($w - 6, 7, $value, 0, 0, 'L');

            $x += $w + $gap;
        }

        $this->SetXY(10, $y + $h + 2);
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(...self::C_SOFT);
        $line = __('Categorias de chamado', 'servicereports') . ': ' . PluginServicereportsFinancial::money($s['categorias'])
            . '     ' . __('Extras relacionados a chamados', 'servicereports') . ': ' . PluginServicereportsFinancial::money($s['extras'])
            . '     ' . __('Tempo total de tarefas', 'servicereports') . ': ' . PluginServicereportsFinancial::duration((int) ($s['segundos'] ?? 0));
        $this->Cell(self::W, 4, $line, 0, 1, 'L');
        $this->Ln(2);
    }

    /** Bloco de um serviço: barra, grade de 6 valores e listagem de chamados. */
    public function drawService(array $svc): void
    {
        // Barra + grade + cabeçalho da tabela não devem ficar órfãos no pé da folha.
        $this->keepTogether(30.0);

        $y = $this->GetY();

        // --- barra do serviço ---
        $this->SetFillColor(...self::C_BAND);
        $this->Rect(10, $y, self::W, 7.5, 'F');
        $this->SetFillColor(...self::C_ACCENT);
        $this->Rect(10, $y, 1.4, 7.5, 'F');

        // Rótulo e valor ficam colados no canto direito: com a célula do nome
        // ocupando "o que sobra", o "CUSTO TOTAL" descolava do número.
        $this->SetXY(13, $y + 0.5);
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(...self::C_INK);
        $this->Cell(self::W - 75, 6.5, $svc['name'], 0, 0, 'L');

        $this->SetFont('helvetica', 'B', 6.5);
        $this->SetTextColor(...self::C_SOFT);
        $this->Cell(30, 6.5, self::upper(__('Custo total', 'servicereports')), 0, 0, 'R');
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(...self::C_INK);
        $this->Cell(39, 6.5, PluginServicereportsFinancial::money($svc['total']), 0, 1, 'R');

        // --- grade de 6 valores ---
        $y = $this->GetY() + 1.5;
        $w = self::W / 6;
        $vals = [
            [__('Mensal', 'servicereports'), PluginServicereportsFinancial::money($svc['mensal'])],
            [__('Ativos', 'servicereports'), PluginServicereportsFinancial::money($svc['ativos'])],
            [__('Categoria', 'servicereports'), PluginServicereportsFinancial::money($svc['categoria'])],
            [__('Extras', 'servicereports'), PluginServicereportsFinancial::money($svc['extras'])],
            [__('Tarefas', 'servicereports'), PluginServicereportsFinancial::money($svc['task_value'])],
            [__('Tempo de tarefas', 'servicereports'), PluginServicereportsFinancial::duration((int) $svc['task_seconds'])],
        ];
        $x = 10.0;
        foreach ($vals as $i => [$label, $value]) {
            if ($i > 0) {
                $this->SetDrawColor(...self::C_HAIR);
                $this->Line($x, $y, $x, $y + 9);
            }
            $this->SetXY($x + 2.5, $y);
            $this->SetFont('helvetica', 'B', 5.5);
            $this->SetTextColor(...self::C_FAINT);
            $this->Cell($w - 3, 3.2, self::upper($label), 0, 2, 'L');
            $this->SetFont('helvetica', 'B', 8);
            $this->SetTextColor(...self::C_INK);
            $this->Cell($w - 3, 4.5, $value, 0, 0, 'L');
            $x += $w;
        }
        $this->SetXY(10, $y + 10.5);

        $this->drawTicketList($svc);
        $this->Ln(3);
    }

    /** Listagem de chamados do serviço, com quebra de linha dentro da célula. */
    private function drawTicketList(array $svc): void
    {
        $n = count($svc['tickets']);

        $this->SetFont('helvetica', 'B', 6.5);
        $this->SetTextColor(...self::C_SOFT);
        $this->Cell(
            self::W,
            4,
            self::upper(__('Chamados vinculados ao serviço, fechados no período', 'servicereports'))
                . ($n > 0 ? ' — ' . $n : ''),
            0,
            1,
            'L'
        );

        if ($n === 0) {
            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(...self::C_SOFT);
            $this->Cell(self::W, 4, __('Não há chamados vinculados ao serviço fechados no período', 'servicereports'), 0, 1, 'L');
            if (empty($svc['cat']) && empty($svc['coveredassets'])) {
                $this->SetX(10);
                $this->SetTextColor(...self::C_SOFT);
                $this->MultiCell(
                    self::W,
                    4,
                    __('O serviço não tem "Categoria de chamado" definida em Serviços Gerenciados nem ativos cobertos — sem um dos dois não há como vincular chamados, e os valores de hora/tarefa ficam zerados.', 'servicereports'),
                    0,
                    'L'
                );
            }
            return;
        }

        $headers = [
            __('Nº', 'servicereports'),
            __('Título', 'servicereports'),
            __('Tipo', 'servicereports'),
            __('Categoria', 'servicereports'),
            __('Requerente', 'servicereports'),
            __('Abertura', 'servicereports'),
            __('Fechamento', 'servicereports'),
            __('Horas', 'servicereports'),
            __('Custo hora', 'servicereports'),
            __('Custo chamado', 'servicereports'),
        ];
        $align = ['L', 'L', 'L', 'L', 'L', 'L', 'L', 'R', 'R', 'R'];

        $this->drawTicketHeader($headers, $align);

        $zebra = false;
        foreach ($svc['tickets'] as $t) {
            $row = [
                (string) $t['id'],
                self::plain((string) $t['name']),
                Ticket::getTicketTypeName($t['type']),
                $t['cat'] ? self::plain(Dropdown::getDropdownName('glpi_itilcategories', $t['cat'])) : '-',
                $t['requester'] !== '' ? self::plain($t['requester']) : '-',
                Html::convDateTime($t['date']),
                $t['closedate'] !== '' ? Html::convDateTime($t['closedate']) : '-',
                PluginServicereportsFinancial::hms((int) $t['seconds']),
                PluginServicereportsFinancial::money((float) $t['cost_hour']),
                PluginServicereportsFinancial::money((float) $t['cost_total']),
            ];
            $this->drawTicketRow($row, $align, $zebra, $headers);
            $zebra = !$zebra;
        }
    }

    /** Cabeçalho da tabela (redesenhado quando a listagem vira a folha). */
    private function drawTicketHeader(array $headers, array $align): void
    {
        $y = $this->GetY();
        $this->SetFillColor(...self::C_HEAD);
        $this->Rect(10, $y, self::W, 6, 'F');

        $this->SetFont('helvetica', 'B', 6);
        $this->SetTextColor(255, 255, 255);
        $x = 10.0;
        foreach ($headers as $i => $h) {
            $this->SetXY($x, $y + 0.4);
            $this->Cell(self::COLS[$i] - 2, 5.2, self::upper($h), 0, 0, $align[$i]);
            $x += self::COLS[$i];
        }
        $this->SetXY(10, $y + 6);
    }

    /**
     * Uma linha da listagem: mede a coluna que precisa de mais linhas e desenha
     * todas as células com essa altura — é assim que o texto **quebra** em vez
     * de ser cortado, que era o defeito da versão impressa pelo navegador.
     */
    private function drawTicketRow(array $row, array $align, bool $zebra, array $headers): void
    {
        $this->SetFont('helvetica', '', 7);

        $lines = 1;
        foreach ($row as $i => $cell) {
            $lines = max($lines, (int) $this->getNumLines($cell, self::COLS[$i] - 3));
        }
        $h = max(5.0, $lines * 3.6 + 1.4);

        // Quebra de página no meio da tabela: nova folha + cabeçalho repetido.
        if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage();
            $this->drawTicketHeader($headers, $align);
        }

        $y = $this->GetY();
        if ($zebra) {
            $this->SetFillColor(...self::C_ZEBRA);
            $this->Rect(10, $y, self::W, $h, 'F');
        }

        $this->SetTextColor(...self::C_INK);
        $x = 10.0;
        foreach ($row as $i => $cell) {
            $this->SetXY($x + 1.5, $y + 0.7);
            $this->MultiCell(self::COLS[$i] - 3, $h - 1.4, $cell, 0, $align[$i], false, 0, '', '', true, 0, false, true, 0, 'T');
            $x += self::COLS[$i];
        }

        $this->SetDrawColor(...self::C_HAIR);
        $this->Line(10, $y + $h, 10 + self::W, $y + $h);
        $this->SetXY(10, $y + $h);
    }

    /** Abre folha nova se não couber `$need` mm de bloco no que resta da atual. */
    private function keepTogether(float $need): void
    {
        if ($this->GetY() + $need > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage();
        }
    }

    // =====================================================================
    //  Utilitários
    // =====================================================================

    /** Caminho da logo no disco (o TCPDF lê arquivo, não URL). */
    private static function logoFile(): string
    {
        foreach (['instant-logo.png', 'logo.png', 'logo.jpg'] as $file) {
            $path = GLPI_ROOT . '/plugins/servicereports/pics/' . $file;
            if (file_exists($path)) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Texto puro para o PDF: o GLPI devolve conteúdo HTML-escapado
     * (ex.: `&#62;` no lugar de `>` nas categorias em árvore) e o TCPDF
     * imprimiria a entidade literal.
     */
    private static function plain(string $v): string
    {
        return html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Caixa alta ciente de acento (mb_strtoupper — strtoupper erra em UTF-8). */
    private static function upper(string $v): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
    }

    // =====================================================================
    //  Entrada
    // =====================================================================

    /**
     * Monta o PDF do extrato inteiro (uma empresa por página) e devolve os bytes.
     *
     * @param array $extrato saída de PluginServicereportsFinancial::getExtrato()
     */
    public static function build(array $extrato, string $start, string $end): string
    {
        $pdf = new self($start, $end);

        if (empty($extrato)) {
            $pdf->entityName = '-';
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(...self::C_SOFT);
            $pdf->Cell(self::W, 6, __('Nenhum serviço encontrado para o período.', 'servicereports'), 0, 1, 'L');
            return $pdf->Output('', 'S');
        }

        foreach ($extrato as $ent) {
            $pdf->entityName = self::plain((string) $ent['name']);
            $pdf->AddPage();
            $pdf->drawSummary($ent['summary']);
            foreach ($ent['services'] as $svc) {
                $svc['name'] = self::plain((string) $svc['name']);
                $pdf->drawService($svc);
            }
        }

        return $pdf->Output('', 'S');
    }
}
