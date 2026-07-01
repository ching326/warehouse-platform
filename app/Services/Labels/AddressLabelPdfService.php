<?php

namespace App\Services\Labels;

use App\Support\Labels\LabelLayout;
use TCPDF;

class AddressLabelPdfService
{
    /**
     * @param  array<int, array<string, mixed>>  $labels
     * @param  array<int, int>  $skipCells
     */
    public function render(array $labels, array $skipCells = []): string
    {
        $layout = LabelLayout::fromConfig('address_label_10_a4');
        $cells = $this->cells($labels, $skipCells);

        $pdf = new TCPDF($layout->orientation(), 'mm', $layout->paper(), true, 'UTF-8', false);
        $pdf->SetCompression(false);
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

            $this->drawLabel($pdf, $layout, $index % $layout->cellsPerPage(), $cell);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  array<int, array<string, mixed>>  $labels
     * @param  array<int, int>  $skipCells
     * @return array<int, array<string, mixed>|null>
     */
    private function cells(array $labels, array $skipCells): array
    {
        $skipCells = collect($skipCells)
            ->map(fn ($cell): int => (int) $cell)
            ->filter(fn (int $cell): bool => $cell >= 1 && $cell <= 30)
            ->unique()
            ->sort()
            ->map(fn (int $cell): int => $cell - 1)
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
     * @param  array<string, mixed>  $label
     */
    private function drawLabel(TCPDF $pdf, LabelLayout $layout, int $cellIndex, array $label): void
    {
        [$x, $y] = $layout->cellOrigin($cellIndex);
        $padding = $layout->cellPadding();
        $innerX = $x + $padding;
        $innerY = $y + $padding;
        $innerWidth = max(1, $layout->cellWidth() - ($padding * 2));
        $lineHeight = 4.2;

        $pdf->SetDrawColor(210, 210, 210);
        $pdf->Rect($x, $y, $layout->cellWidth(), $layout->cellHeight());

        $this->text($pdf, $innerX, $innerY, $innerWidth, $lineHeight, (string) $label['postal_code'], 9, 'B');
        $this->text($pdf, $innerX, $innerY + 5, $innerWidth, $lineHeight, (string) $label['address_line1'], 8.5);
        $this->text($pdf, $innerX, $innerY + 10, $innerWidth, $lineHeight, (string) $label['address_line2'], 8.5);
        $this->text($pdf, $innerX, $innerY + 15, $innerWidth, 5, (string) $label['recipient_name'], 11, 'B');

        $phone = (bool) $label['show_phone'] ? (string) $label['recipient_phone'] : '';
        $this->text($pdf, $innerX, $innerY + 21, $innerWidth, $lineHeight, $phone, 8);
        $this->text($pdf, $innerX, $innerY + 27, $innerWidth, $lineHeight, (string) $label['items_line'], 7.5);
        $this->text($pdf, $innerX, $innerY + 32, $innerWidth, $lineHeight, (string) $label['description_line'], 7.5);

        $weight = $label['total_weight'] === null ? '' : (string) $label['total_weight'].' g';
        $this->text($pdf, $innerX, $innerY + 38, $innerWidth, $lineHeight, $weight, 7.5);
        $this->text($pdf, $innerX, $innerY + 43, $innerWidth, $lineHeight, (string) $label['shipper_name'], 7);
        $this->text($pdf, $innerX, $innerY + 47, $innerWidth, $lineHeight, (string) $label['shipper_address'], 6.5);
    }

    private function text(TCPDF $pdf, float $x, float $y, float $width, float $height, string $text, float $fontSize, string $style = ''): void
    {
        $pdf->SetFont('helvetica', $style, $fontSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $height, $text, 0, 0, 'L', false, '', 1);
    }
}
