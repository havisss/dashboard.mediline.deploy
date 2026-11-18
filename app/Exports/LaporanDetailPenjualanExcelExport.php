<?php

namespace App\Exports;

use App\Models\OutboundInvoiceItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

class LaporanDetailPenjualanExcelExport
{
    /**
     * Generate a styled XLSX file for Laporan Detail Penjualan Barang.
     *
     * @param  Builder<OutboundInvoiceItem>  $query
     * @param  array<int, string>  $filterSummaries
     */
    public function generateTempFile(Builder $query, array $filterSummaries = []): string
    {
        $tempBase = tempnam(sys_get_temp_dir(), 'laporan_detail_penjualan_');
        $filePath = $tempBase.'.xlsx';
        if (file_exists($tempBase)) {
            @unlink($tempBase);
        }

        $options = new Options;
        $options->DEFAULT_ROW_STYLE = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10);

        $options->setPageSetup(new PageSetup(
            pageOrientation: PageOrientation::LANDSCAPE,
            paperSize: PaperSize::A4,
            fitToHeight: 0,
            fitToWidth: 1,
        ));

        // Merge title & metadata rows across all 15 columns (A..O => 0..14)
        $options->mergeCells(0, 1, 14, 1);
        $options->mergeCells(0, 2, 14, 2);
        $options->mergeCells(0, 3, 14, 3);

        $writer = new Writer($options);
        $writer->openToFile($filePath);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Detail Penjualan');

        // Freeze header rows (Rows 1..5 stay frozen while scrolling)
        $sheet->setSheetView(
            (new SheetView)->setFreezeRow(6)
        );

        // Column widths (1-indexed: A=1 .. O=15)
        $columnWidths = [
            1 => 6.0,   // No
            2 => 18.0,  // No Faktur
            3 => 28.0,  // Customer
            4 => 14.0,  // Tanggal
            5 => 36.0,  // Nama Barang
            6 => 18.0,  // Divisi
            7 => 18.0,  // Merek
            8 => 18.0,  // Sales
            9 => 14.0,  // Status Faktur
            10 => 10.0, // Qty
            11 => 11.0, // Satuan
            12 => 18.0, // Harga Jual
            13 => 11.0, // Diskon 1
            14 => 11.0, // Diskon 2
            15 => 20.0, // Total (Net)
        ];

        foreach ($columnWidths as $colIndex => $width) {
            $sheet->setColumnWidth($width, $colIndex);
        }

        // Borders
        $thinGridBorder = new Border(
            new BorderPart(Border::TOP, 'CBD5E1', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, 'CBD5E1', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, 'CBD5E1', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, 'CBD5E1', Border::WIDTH_THIN, Border::STYLE_SOLID),
        );

        $headerBorder = new Border(
            new BorderPart(Border::TOP, '0F5132', Border::WIDTH_MEDIUM, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, '0F5132', Border::WIDTH_MEDIUM, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, '196F46', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, '196F46', Border::WIDTH_THIN, Border::STYLE_SOLID),
        );

        $totalBorder = new Border(
            new BorderPart(Border::TOP, '0F5132', Border::WIDTH_MEDIUM, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, '0F5132', Border::WIDTH_MEDIUM, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, 'CBD5E1', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, 'CBD5E1', Border::WIDTH_THIN, Border::STYLE_SOLID),
        );

        // Title & metadata styles
        $titleStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(15)
            ->setFontBold()
            ->setFontColor('0F5132')
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        $subtitleStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setFontColor('475569')
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        $filterStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(9)
            ->setFontItalic()
            ->setFontColor('64748B')
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        $headerStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setFontBold()
            ->setFontColor('FFFFFF')
            ->setBackgroundColor('0F5132')
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText(true)
            ->setBorder($headerBorder);

        // Row 1: Title
        $writer->addRow(
            Row::fromValues(['LAPORAN DETAIL PENJUALAN BARANG'], $titleStyle)->setHeight(28)
        );

        // Row 2: Subtitle
        $downloadedAt = now()->format('d M Y H:i');
        $writer->addRow(
            Row::fromValues(["Global Mediline Indonesia | Diunduh pada: {$downloadedAt}"], $subtitleStyle)->setHeight(18)
        );

        // Row 3: Active filters
        $filterText = ! empty($filterSummaries)
            ? 'Filter Aktif: '.implode(' | ', $filterSummaries)
            : 'Filter Aktif: Semua Data';
        $writer->addRow(
            Row::fromValues([$filterText], $filterStyle)->setHeight(18)
        );

        // Row 4: Spacer
        $writer->addRow(Row::fromValues([])->setHeight(8));

        // Row 5: Table Header
        $headers = [
            'No',
            'No Faktur',
            'Customer',
            'Tanggal',
            'Nama Barang',
            'Divisi',
            'Merek',
            'Sales',
            'Status Faktur',
            'Qty',
            'Satuan',
            'Harga Jual',
            'Diskon 1',
            'Diskon 2',
            'Total (Net)',
        ];
        $writer->addRow(Row::fromValues($headers, $headerStyle)->setHeight(26));

        // Reusable data cell styles (odd/even + cancelled)
        $styles = $this->buildDataStyles($thinGridBorder);

        $rowNumber = 0;
        $totalQty = 0;
        $grandTotal = 0.0;

        foreach ($query->lazy(500) as $item) {
            $rowNumber++;
            $invoice = $item->invoice;
            $status = (string) ($invoice?->status ?? '-');
            $isCancelled = $status === 'Cancelled';
            $variant = $isCancelled ? 'cancelled' : ($rowNumber % 2 === 0 ? 'even' : 'odd');

            $qty = (int) $item->quantity;
            $unitPrice = (float) $item->unit_price;
            $subtotal = (float) $item->subtotal;

            if (! $isCancelled) {
                $totalQty += $qty;
                $grandTotal += $subtotal;
            }

            $issueDate = $invoice?->issue_date
                ? Carbon::parse($invoice->issue_date)->format('d M Y')
                : '-';

            $discount1 = (float) ($item->discount_percentage ?? 0);
            $discount2 = (float) ($item->discount_2_percentage ?? 0);

            $cells = [
                Cell::fromValue($rowNumber, $styles[$variant]['int_center']),
                Cell::fromValue((string) ($invoice?->invoice_number ?? '-'), $styles[$variant]['text_bold']),
                Cell::fromValue((string) ($invoice?->client?->name ?? '-'), $styles[$variant]['text_left']),
                Cell::fromValue($issueDate, $styles[$variant]['text_center']),
                Cell::fromValue((string) $item->item_name, $styles[$variant]['text_left']),
                Cell::fromValue((string) $item->division, $styles[$variant]['text_left']),
                Cell::fromValue((string) $item->brand, $styles[$variant]['text_left']),
                Cell::fromValue((string) ($invoice?->sales ?: '-'), $styles[$variant]['text_left']),
                Cell::fromValue($status, $styles[$variant]['text_center']),
                Cell::fromValue($qty, $styles[$variant]['int_center']),
                Cell::fromValue((string) ($item->unit ?: 'Pcs'), $styles[$variant]['text_center']),
                Cell::fromValue($unitPrice, $styles[$variant]['currency']),
                Cell::fromValue($discount1 > 0 ? $discount1.'%' : '-', $styles[$variant]['text_center']),
                Cell::fromValue($discount2 > 0 ? $discount2.'%' : '-', $styles[$variant]['text_center']),
                Cell::fromValue($subtotal, $styles[$variant]['currency_bold']),
            ];

            $writer->addRow((new Row($cells))->setHeight(21));
        }

        // Handle empty dataset cleanly
        if ($rowNumber === 0) {
            $emptyRowIndex = 6;
            $options->mergeCells(0, $emptyRowIndex, 14, $emptyRowIndex);
            $emptyCells = [
                Cell::fromValue('Tidak ada data penjualan untuk filter yang dipilih.', $styles['odd']['text_center']),
            ];
            for ($i = 1; $i < 15; $i++) {
                $emptyCells[] = Cell::fromValue('', $styles['odd']['text_center']);
            }
            $writer->addRow((new Row($emptyCells))->setHeight(24));
            $lastDataRow = $emptyRowIndex;
        } else {
            $lastDataRow = 5 + $rowNumber;
        }

        // Grand Total Row
        $grandTotalRow = $lastDataRow + 1;
        $options->mergeCells(0, $grandTotalRow, 8, $grandTotalRow);

        $totalLabelStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setFontBold()
            ->setFontColor('0F5132')
            ->setBackgroundColor('E2F1E8')
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setBorder($totalBorder);

        $totalQtyStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setFontBold()
            ->setFontColor('0F5132')
            ->setBackgroundColor('E2F1E8')
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setFormat('#,##0')
            ->setBorder($totalBorder);

        $totalEmptyStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setBackgroundColor('E2F1E8')
            ->setBorder($totalBorder);

        $totalCurrencyStyle = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setFontBold()
            ->setFontColor('0F5132')
            ->setBackgroundColor('E2F1E8')
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setFormat('"Rp "#,##0')
            ->setBorder($totalBorder);

        $totalCells = [
            Cell::fromValue('GRAND TOTAL (Tidak termasuk status Cancelled)', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue('', $totalLabelStyle),
            Cell::fromValue($totalQty, $totalQtyStyle),
            Cell::fromValue('', $totalEmptyStyle),
            Cell::fromValue('', $totalEmptyStyle),
            Cell::fromValue('', $totalEmptyStyle),
            Cell::fromValue('', $totalEmptyStyle),
            Cell::fromValue($grandTotal, $totalCurrencyStyle),
        ];

        $writer->addRow((new Row($totalCells))->setHeight(25));

        // Apply Excel AutoFilter on table header through last data row (A5:O{lastDataRow})
        $sheet->setAutoFilter(new AutoFilter(0, 5, 14, max(5, $lastDataRow)));

        $writer->close();

        return $filePath;
    }

    /**
     * @return array<string, array<string, Style>>
     */
    private function buildDataStyles(Border $border): array
    {
        $variants = [
            'odd' => ['bg' => 'FFFFFF', 'color' => '0F172A', 'italic' => false],
            'even' => ['bg' => 'F8FAFC', 'color' => '0F172A', 'italic' => false],
            'cancelled' => ['bg' => 'FEF2F2', 'color' => '94A3B8', 'italic' => true],
        ];

        $built = [];

        foreach ($variants as $key => $cfg) {
            $makeBase = function () use ($cfg, $border): Style {
                $style = (new Style)
                    ->setFontName('Calibri')
                    ->setFontSize(10)
                    ->setFontColor($cfg['color'])
                    ->setBackgroundColor($cfg['bg'])
                    ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
                    ->setBorder($border);

                if ($cfg['italic']) {
                    $style->setFontItalic();
                }

                return $style;
            };

            $built[$key] = [
                'text_left' => $makeBase()->setCellAlignment(CellAlignment::LEFT),
                'text_bold' => $makeBase()->setFontBold()->setCellAlignment(CellAlignment::LEFT),
                'text_center' => $makeBase()->setCellAlignment(CellAlignment::CENTER),
                'int_center' => $makeBase()->setCellAlignment(CellAlignment::CENTER)->setFormat('#,##0'),
                'currency' => $makeBase()->setCellAlignment(CellAlignment::RIGHT)->setFormat('"Rp "#,##0'),
                'currency_bold' => $makeBase()->setFontBold()->setCellAlignment(CellAlignment::RIGHT)->setFormat('"Rp "#,##0'),
            ];
        }

        return $built;
    }
}
