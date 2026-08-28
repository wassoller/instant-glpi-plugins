<?php
/**
 * Relatório 61 — "Chamados por Status e Técnico" em PDF (TCPDF), A4 paisagem.
 *
 * São **duas seções**, uma por folha: chamados por status e técnico e chamados
 * por tipo (Incidente × Requisição) e técnico. Os dois usam o mesmo desenho —
 * a pilha, as cores e os rótulos vêm do próprio array
 * (`keys`/`legend`/`labels`/`colors`), como na tela.
 *
 * Mesmo motivo do extrato financeiro (ver extratopdf.class.php): PDF de
 * verdade, e não a impressão do navegador — aqui o gráfico é desenhado com
 * primitivas do TCPDF (`Rect`/`Line`/`Text` girado), o cabeçalho e o rodapé se
 * repetem em toda folha com "Página X de Y", e a tabela quebra sozinha.
 *
 * Layout "Institucional", a mesma paleta do extrato; as cores das barras vêm
 * de PluginServicereportsAnalysts::STATUS_COLORS, então tela e papel batem.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsStatustechpdf extends TCPDF
{
    /** Largura útil da folha (A4 paisagem, margens de 10mm). */
    private const W = 277.0;

    /** Largura da 1ª coluna (Técnico) e da última (Total) da tabela. */
    private const COL_NAME  = 72.0;
    private const COL_TOTAL = 31.0;

    /**
     * Larguras da tabela: Técnico + $n colunas iguais + Total (soma = W).
     * São 6 status (29mm cada) ou 2 tipos (87mm cada).
     *
     * @return array<int,float>
     */
    private static function cols(int $n): array
    {
        $w = (self::W - self::COL_NAME - self::COL_TOTAL) / max(1, $n);
        return array_merge([self::COL_NAME], array_fill(0, $n, $w), [self::COL_TOTAL]);
    }

    // Paleta do layout "Institucional" (igual à do extrato).
    private const C_INK    = [22, 32, 42];
    private const C_SOFT   = [92, 107, 121];
    private const C_FAINT  = [139, 152, 165];
    private const C_LINE   = [216, 222, 228];
    private const C_HEAD   = [34, 49, 64];
    private const C_ZEBRA  = [245, 248, 250];
    private const C_ACCENT = [15, 111, 140];

    private string $start = '';
    private string $end   = '';
    private string $techLabel = '';
    private string $printedBy = '';
    /** Título da seção em curso — vai no cabeçalho da folha (muda por seção). */
    private string $section = '';

    public function __construct(string $start, string $end, string $techLabel)
    {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8');

        $this->start     = $start;
        $this->end       = $end;
        $this->techLabel = $techLabel;
        $this->printedBy = getUserName(Session::getLoginUserID());

        $this->SetCreator('GLPI · servicereports');
        $this->SetTitle(__('Chamados por status e técnico', 'servicereports'));
        $this->SetMargins(10, 30, 10);
        $this->SetAutoPageBreak(true, 16);
        $this->setImageScale(1.25);
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
        $this->Cell(120, 6, $this->section, 0, 2, 'L');
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(...self::C_SOFT);
        $this->Cell(120, 4, __('Desempenho de analistas', 'servicereports'), 0, 0, 'L');

        $rows = [
            [__('Técnico', 'servicereports'), $this->techLabel],
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
        // Nome do relatório (o do seletor), **não** o da seção: o TCPDF chama o
        // Footer da folha anterior dentro do AddPage, ou seja depois de o
        // título da seção nova já ter sido trocado — a folha do status sairia
        // com o rodapé do tipo.
        $this->Cell(self::W / 2, 5, __('Chamados por Status e Técnico', 'servicereports'), 0, 0, 'L');
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

    /** Legenda da pilha, na ordem que o relatório definiu (`legend`). */
    private function drawLegend(array $data): void
    {
        $y = $this->GetY();
        $x = 10.0;
        $this->SetFont('helvetica', '', 7);
        foreach ($data['legend'] as $st) {
            $text = self::plain($data['labels'][$st]);
            $w    = $this->GetStringWidth($text) + 10;
            $this->SetFillColor(...PluginServicereportsAnalysts::rgb($data['colors'][$st]));
            $this->Rect($x, $y + 1.2, 3.2, 3.2, 'F');
            $this->SetTextColor(...self::C_SOFT);
            $this->SetXY($x + 4.5, $y);
            $this->Cell($w - 4.5, 5.5, $text, 0, 0, 'L');
            $x += $w;
        }
        $this->SetXY(10, $y + 7);
    }

    /**
     * Gráfico de barras empilhadas (mesmo desenho para as duas seções).
     *
     * @param array $data saída de getStatusByTechnician()/getTypeByTechnician()
     */
    private function drawChart(array $data, float $plotH): void
    {
        $rows = $data['rows'];
        [$top, $step] = PluginServicereportsAnalysts::niceScale((int) $data['max']);

        $x0   = 22.0;                 // espaço à esquerda p/ os rótulos do eixo Y
        $x1   = 10.0 + self::W;
        $plotW = $x1 - $x0;
        $y0   = $this->GetY();
        $base = $y0 + $plotH;

        $n    = max(1, count($rows));
        $slot = $plotW / $n;
        $barW = min(14.0, $slot * 0.62);

        // Grade horizontal + rótulos do eixo Y.
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

        // Barras + rótulos.
        $i = 0;
        foreach ($rows as $row) {
            $bx = $x0 + $i * $slot + ($slot - $barW) / 2;
            $by = $base;
            foreach ($data['keys'] as $st) {
                $c = (int) ($row['counts'][$st] ?? 0);
                if ($c <= 0) {
                    continue;
                }
                $segH = ($c / $top) * $plotH;
                $by  -= $segH;
                $this->SetFillColor(...PluginServicereportsAnalysts::rgb($data['colors'][$st]));
                $this->Rect($bx, $by, $barW, $segH, 'F');
            }

            // Total acima da barra.
            if ((int) $row['total'] > 0) {
                $this->SetFont('helvetica', 'B', 6.5);
                $this->SetTextColor(...self::C_SOFT);
                $this->SetXY($bx - 4, $by - 4.2);
                $this->Cell($barW + 8, 4, (string) (int) $row['total'], 0, 0, 'C');
            }

            // Nome do técnico girado 40° (termina embaixo da barra e sobe p/ a
            // direita) — horizontal só caberia com pouquíssimos técnicos.
            $px = $bx + $barW / 2;
            $py = $base + 2.5;
            $this->StartTransform();
            $this->Rotate(40, $px, $py);
            $this->SetFont('helvetica', '', 6);
            $this->SetTextColor(...self::C_INK);
            // A célula vai de x=0 até o pé da barra, com o texto alinhado à
            // direita: `SetXY` com x **negativo** significa "a partir da borda
            // direita" no TCPDF, e o rótulo da primeira barra sumia da folha.
            $this->SetXY(0, $py - 1.6);
            $this->Cell($px, 3.2, self::shorten(self::plain((string) $row['name']), 30), 0, 0, 'R');
            $this->StopTransform();

            $i++;
        }

        // Espaço reservado para os rótulos girados.
        $this->SetXY(10, $base + 26);
    }

    /** Altura do cabeçalho da tabela (duas linhas de rótulo). */
    private const HEAD_H = 10.0;

    /**
     * Cabeçalho escuro da tabela. Os rótulos saem em `MultiCell` porque
     * "Em atendimento (atribuído)" não cabe numa linha de 29mm — e o `Cell`
     * do TCPDF não quebra: transborda por cima da coluna vizinha.
     */
    private function drawTableHead(array $data): void
    {
        $cols = self::cols(count($data['keys']));
        $h    = self::HEAD_H;
        $y    = $this->GetY();
        $x    = 10.0;
        $this->SetFillColor(...self::C_HEAD);
        $this->Rect($x, $y, self::W, $h, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 6);

        $cells = [__('Técnico', 'servicereports')];
        foreach ($data['keys'] as $k) {
            $cells[] = $data['labels'][$k];
        }
        $cells[] = __('Total', 'servicereports');

        foreach ($cells as $k => $text) {
            $this->MultiCell(
                $cols[$k],
                $h,
                self::upper(self::plain((string) $text)),
                0,
                $k === 0 ? 'L' : 'C',
                false,
                0,
                $x,
                $y,
                true,
                0,
                false,
                true,
                $h,
                'M'
            );
            $x += $cols[$k];
        }
        $this->SetXY(10, $y + $h);
    }

    /** Tabela técnico × pilha, com quebra de página e cabeçalho repetido. */
    private function drawTable(array $data): void
    {
        $cols = self::cols(count($data['keys']));
        $last = count($cols) - 1;

        // Evita a tabela nascer no pé da folha e derramar duas linhas na
        // seguinte: se ela não cabe aqui mas cabe inteira numa folha nova,
        // começa numa folha nova.
        $h      = 6.0;
        $needed    = self::HEAD_H + $h * count($data['rows']) + 8.0;
        $limit     = $this->getPageHeight() - 20;
        $fitsHere  = $this->GetY() + $needed <= $limit;
        $fitsFresh = 30.0 + $needed <= $limit;
        // Também troca de folha quando não sobra espaço nem para o cabeçalho
        // com duas linhas (senão ele sairia sozinho no pé da página).
        $noRoom = $this->GetY() + self::HEAD_H + 2 * $h > $limit;
        if (!$fitsHere && ($fitsFresh || $noRoom)) {
            $this->AddPage();
        }

        $this->drawTableHead($data);

        $zebra = false;
        foreach ($data['rows'] as $row) {
            if ($this->GetY() + $h > $this->getPageHeight() - 20) {
                $this->AddPage();
                $this->drawTableHead($data);
                $zebra = false;
            }
            $y = $this->GetY();
            $x = 10.0;
            if ($zebra) {
                $this->SetFillColor(...self::C_ZEBRA);
                $this->Rect($x, $y, self::W, $h, 'F');
            }
            $zebra = !$zebra;

            $this->SetFont('helvetica', '', 7);
            $this->SetTextColor(...self::C_INK);
            $this->SetXY($x, $y);
            $this->Cell($cols[0], $h, self::shorten(self::plain((string) $row['name']), 52), 0, 0, 'L');
            $x += $cols[0];

            $k = 1;
            foreach ($data['keys'] as $st) {
                $c = (int) ($row['counts'][$st] ?? 0);
                $this->SetTextColor(...($c > 0 ? self::C_INK : self::C_FAINT));
                $this->SetXY($x, $y);
                $this->Cell($cols[$k], $h, (string) $c, 0, 0, 'C');
                $x += $cols[$k];
                $k++;
            }
            $this->SetFont('helvetica', 'B', 7);
            $this->SetTextColor(...self::C_ACCENT);
            $this->SetXY($x, $y);
            $this->Cell($cols[$last], $h, (string) (int) $row['total'], 0, 0, 'C');

            $this->SetDrawColor(...self::C_LINE);
            $this->Line(10, $y + $h, 10 + self::W, $y + $h);
            $this->SetXY(10, $y + $h);
        }

        // Rodapé de totais.
        if ($this->GetY() + 8 > $this->getPageHeight() - 20) {
            $this->AddPage();
            $this->drawTableHead($data);
        }
        $y = $this->GetY();
        $x = 10.0;
        $this->SetFillColor(...self::C_ZEBRA);
        $this->Rect($x, $y, self::W, 7.5, 'F');
        $this->SetDrawColor(...self::C_HEAD);
        $this->SetLineWidth(0.3);
        $this->Line(10, $y, 10 + self::W, $y);
        $this->SetLineWidth(0.2);

        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(...self::C_INK);
        $this->SetXY($x, $y);
        $this->Cell($cols[0], 7.5, self::upper(__('Total', 'servicereports')), 0, 0, 'L');
        $x += $cols[0];
        $k = 1;
        foreach ($data['keys'] as $st) {
            $this->SetXY($x, $y);
            $this->Cell($cols[$k], 7.5, (string) (int) ($data['totals'][$st] ?? 0), 0, 0, 'C');
            $x += $cols[$k];
            $k++;
        }
        $this->SetTextColor(...self::C_ACCENT);
        $this->SetXY($x, $y);
        $this->Cell($cols[$last], 7.5, (string) (int) $data['grand'], 0, 0, 'C');
        $this->SetXY(10, $y + 9);
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

    /** Texto puro: o GLPI devolve conteúdo HTML-escapado (ex.: `&#62;`). */
    private static function plain(string $v): string
    {
        return html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Caixa alta ciente de acento (mb_strtoupper — strtoupper erra em UTF-8). */
    private static function upper(string $v): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
    }

    /** Corta o texto com reticências (o `Cell` do TCPDF transborda, não corta). */
    private static function shorten(string $v, int $max): string
    {
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    }

    // =====================================================================
    //  Entrada
    // =====================================================================

    /**
     * Uma seção: linha de resumo, legenda, gráfico e tabela.
     *
     * @param array  $data    saída de getStatusByTechnician()/getTypeByTechnician()
     * @param string $summary linha de resumo impressa acima da legenda
     */
    private function drawSection(array $data, string $summary): void
    {
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(...self::C_SOFT);
        $this->Cell(self::W, 5, $summary, 0, 1, 'L');

        $this->drawLegend($data);

        // Altura do gráfico: se a tabela cabe embaixo dele na mesma folha, o
        // gráfico fica no tamanho padrão; se não cabe (ela vai para a folha
        // seguinte de qualquer forma), ele ocupa a folha toda em vez de deixar
        // meia página em branco.
        $usable  = $this->getPageHeight() - 30 - 20;            // margens sup./inf.
        $labelsH = 26.0;                                        // rótulos girados
        $tableH  = self::HEAD_H + 6.0 * count($data['rows']) + 8.0;
        $used    = 5.0 + 7.0 + $labelsH;                        // resumo + legenda
        $plotH   = ($used + 72.0 + $tableH <= $usable) ? 72.0 : max(72.0, $usable - $used);

        $this->drawChart($data, $plotH);
        $this->drawTable($data);
    }

    /**
     * Monta o PDF e devolve os bytes: **uma seção por folha** — chamados por
     * status e técnico na 1ª, chamados por tipo (Incidente × Requisição) e
     * técnico na seguinte. Cada seção leva gráfico + tabela, e a tabela
     * continua quebrando para a folha seguinte quando há muitos técnicos.
     *
     * @param array $data     saída de PluginServicereportsAnalysts::getStatusByTechnician()
     * @param array $typeData saída de PluginServicereportsAnalysts::getTypeByTechnician()
     */
    public static function build(array $data, array $typeData, string $start, string $end, string $techLabel): string
    {
        $pdf = new self($start, $end, self::plain($techLabel));
        // O título da seção vai no cabeçalho da folha e tem de estar definido
        // ANTES do AddPage (é o AddPage que dispara o Header do TCPDF).
        $pdf->section = __('Chamados por status e técnico', 'servicereports');
        $pdf->AddPage();

        if (empty($data['rows'])) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(...self::C_SOFT);
            $pdf->Cell(self::W, 6, __('Nenhum chamado encontrado no período.', 'servicereports'), 0, 1, 'L');
            return $pdf->Output('', 'S');
        }

        $chamados = sprintf(_n('%d chamado', '%d chamados', (int) $data['grand'], 'servicereports'), (int) $data['grand']);
        $tecnicos = sprintf(_n('%d técnico', '%d técnicos', count($data['rows']), 'servicereports'), count($data['rows']));

        $pdf->drawSection($data, sprintf(
            __('%1$s · %2$s · contagem pelo técnico atribuído, pela data de abertura', 'servicereports'),
            $chamados,
            $tecnicos
        ));

        // A quebra por tipo sai sempre em folha nova: são os mesmos chamados,
        // só muda a pilha — por isso os totais das duas seções são iguais.
        $pdf->section = __('Chamados por tipo e técnico', 'servicereports');
        $pdf->AddPage();
        $pdf->drawSection($typeData, sprintf(
            __('%1$s · %2$s · os mesmos chamados, quebrados em Incidente e Requisição', 'servicereports'),
            $chamados,
            $tecnicos
        ));

        return $pdf->Output('', 'S');
    }
}
