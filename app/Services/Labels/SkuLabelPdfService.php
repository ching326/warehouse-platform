<?php

namespace App\Services\Labels;

use App\Support\Labels\LabelLayout;
use TCPDF;

class SkuLabelPdfService
{
    /**
     * @param  array<int, array{value: string, code_text: string, name: string|null}>  $labels
     * @param  array<int, int>  $skipCells
     */
    public function render(string $layoutKey, array $labels, array $skipCells = []): string
    {
        $layout = LabelLayout::fromConfig($layoutKey);
        $cells = $this->cells($layout, $labels, $skipCells);

        $pdf = new TCPDF($layout->orientation(), 'mm', $layout->paper(), true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);

        foreach ($cells as $index => $cell) {
            if ($index % $layout->cellsPerPage() === 0) {
                $pdf->AddPage($layout->orientation(), $layout->paper());
            }

            if ($cell === null) {
                continue;
            }

            $cellIndex = $index % $layout->cellsPerPage();
            $this->drawLabel($pdf, $layout, $cellIndex, $cell);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  array<int, array{value: string, code_text: string, name: string|null}>  $labels
     * @param  array<int, int>  $skipCells
     * @return array<int, array{value: string, code_text: string, name: string|null}|null>
     */
    private function cells(LabelLayout $layout, array $labels, array $skipCells): array
    {
        if (! $layout->supportsSkip()) {
            return $labels;
        }

        $skipCells = collect($skipCells)
            ->map(fn ($cell): int => (int) $cell)
            ->filter(fn (int $cell): bool => $cell >= 0 && $cell < $layout->cellsPerPage())
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($skipCells === []) {
            return $labels;
        }

        $cells = [];
        $labelIndex = 0;
        $totalCells = count($labels) + count($skipCells);

        for ($index = 0; $index < $totalCells; $index++) {
            if (in_array($index, $skipCells, true)) {
                $cells[] = null;

                continue;
            }

            $cells[] = $labels[$labelIndex] ?? null;
            $labelIndex++;
        }

        return $cells;
    }

    /**
     * @param  array{value: string, code_text: string, name: string|null}  $label
     */
    private function drawLabel(TCPDF $pdf, LabelLayout $layout, int $cellIndex, array $label): void
    {
        [$x, $y] = $layout->cellOrigin($cellIndex);
        $padding = $layout->cellPadding();
        $innerX = $x + $padding;
        $innerY = $y + $padding;
        $innerWidth = max(1, $layout->cellWidth() - ($padding * 2));
        $barcodeHeight = $layout->barcodeHeight();

        $pdf->write1DBarcode($label['value'], 'C128', $innerX, $innerY, $innerWidth, $barcodeHeight, 0.4, [
            'position' => 'C',
            'align' => 'C',
            'stretch' => false,
            'fitwidth' => true,
            'cellfitalign' => 'C',
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
            'text' => false,
        ], 'N');

        $textY = $innerY + $barcodeHeight + 1;
        $pdf->SetFont('helvetica', '', $layout->codeFontPt());
        $pdf->SetXY($innerX, $textY);
        $pdf->Cell($innerWidth, 4, $label['code_text'], 0, 2, 'C', false, '', 1);

        if (filled($label['name'])) {
            $pdf->SetFont('helvetica', '', $layout->nameFontPt());
            $pdf->SetXY($innerX, $textY + 4);
            $pdf->Cell($innerWidth, 4, (string) $label['name'], 0, 2, 'C', false, '', 1);
        }
    }
}
