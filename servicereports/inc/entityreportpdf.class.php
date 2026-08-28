<?php
/**
 * "Chamados por entidade" em PDF (TCPDF), A4 paisagem — gráfico e tabela.
 *
 * Estende **PluginServicereportsStatustechpdf**, e não o `centralpdf` como os
 * outros relatórios da Central de serviços: o gráfico daqui é o **mesmo** do
 * relatório 61 (barras empilhadas por status, com o total em cima), então o
 * desenho, a legenda e a tabela vêm prontos de lá. O que muda são os rótulos
 * (CLIENTE no lugar de TÉCNICO, Entidade no lugar de Técnico) e o nome do
 * relatório no rodapé — tudo em propriedades do pai.
 *
 * A entrada é a saída de PluginServicereportsEntityreport::getReport(), a
 * mesma da tela.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsEntityreportpdf extends PluginServicereportsStatustechpdf
{
    public function __construct(string $start, string $end, string $client)
    {
        parent::__construct($start, $end, $client);

        $this->metaLabel  = __('Cliente', 'servicereports');
        $this->firstCol   = __('Entidade', 'servicereports');
        $this->reportName = PluginServicereportsEntityreport::title();
        $this->subtitle   = __('Central de serviços', 'servicereports');
        // Na tabela cabe o nome completo da entidade; no eixo do gráfico, não
        // (por isso `$row['name']` é o nome curto).
        $this->rowLabelKey = 'fullname';

        $this->SetTitle($this->reportName);
    }

    /**
     * Monta o PDF (gráfico na 1ª folha, tabela na seguinte) e devolve os bytes.
     *
     * Não se chama `build()` de propósito: a assinatura do pai é outra
     * (`build($data, $typeData, …)`, com os dois gráficos do relatório 61) e o
     * PHP não deixa sobrescrever um método estático com assinatura incompatível.
     *
     * @param array $data saída de PluginServicereportsEntityreport::getReport()
     */
    public static function buildEntity(array $data, string $start, string $end, string $client): string
    {
        $pdf = new self($start, $end, self::plain($client) !== '' ? self::plain($client) : '-');
        $pdf->section = PluginServicereportsEntityreport::title();
        $pdf->AddPage();

        if (empty($data['rows'])) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(...self::C_SOFT);
            $pdf->Cell(self::W, 6, __('Nenhum chamado encontrado no período.', 'servicereports'), 0, 1, 'L');
            return $pdf->Output('', 'S');
        }

        $pdf->drawSection($data, sprintf(
            __('%1$s · %2$s · todos os status, pela data de abertura', 'servicereports'),
            sprintf(_n('%d chamado', '%d chamados', (int) $data['grand'], 'servicereports'), (int) $data['grand']),
            sprintf(_n('%d entidade', '%d entidades', (int) $data['entities'], 'servicereports'), (int) $data['entities'])
        ));

        return $pdf->Output('', 'S');
    }
}
