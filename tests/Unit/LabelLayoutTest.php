<?php

namespace Tests\Unit;

use App\Support\Labels\LabelLayout;
use PHPUnit\Framework\TestCase;

class LabelLayoutTest extends TestCase
{
    public function test_cells_per_page_is_columns_times_rows(): void
    {
        $layout = LabelLayout::fromDefinition('test', [
            'name' => 'Test',
            'paper' => 'A4',
            'orientation' => 'P',
            'cols' => 4,
            'rows' => 10,
            'margin_left' => 2,
            'margin_top' => 3,
            'gutter_x' => 1,
            'gutter_y' => 2,
            'cell_width' => 10,
            'cell_height' => 20,
            'cell_padding' => 1,
            'barcode_height' => 8,
            'code_font_pt' => 8,
            'name_font_pt' => 7,
            'fill' => 'row',
            'supports_skip' => true,
        ]);

        $this->assertSame(40, $layout->cellsPerPage());
    }

    public function test_cell_origin_respects_row_fill_order(): void
    {
        $layout = LabelLayout::fromDefinition('test', [
            'name' => 'Test',
            'paper' => 'A4',
            'orientation' => 'P',
            'cols' => 4,
            'rows' => 10,
            'margin_left' => 2,
            'margin_top' => 3,
            'gutter_x' => 1,
            'gutter_y' => 2,
            'cell_width' => 10,
            'cell_height' => 20,
            'cell_padding' => 1,
            'barcode_height' => 8,
            'code_font_pt' => 8,
            'name_font_pt' => 7,
            'fill' => 'row',
            'supports_skip' => true,
        ]);

        $this->assertSame([2.0, 3.0], $layout->cellOrigin(0));
        $this->assertSame([35.0, 3.0], $layout->cellOrigin(3));
        $this->assertSame([2.0, 25.0], $layout->cellOrigin(4));
    }

    public function test_cell_origin_respects_column_fill_order(): void
    {
        $layout = LabelLayout::fromDefinition('test', [
            'name' => 'Test',
            'paper' => 'A4',
            'orientation' => 'P',
            'cols' => 4,
            'rows' => 10,
            'margin_left' => 2,
            'margin_top' => 3,
            'gutter_x' => 1,
            'gutter_y' => 2,
            'cell_width' => 10,
            'cell_height' => 20,
            'cell_padding' => 1,
            'barcode_height' => 8,
            'code_font_pt' => 8,
            'name_font_pt' => 7,
            'fill' => 'column',
            'supports_skip' => true,
        ]);

        $this->assertSame([2.0, 25.0], $layout->cellOrigin(1));
        $this->assertSame([13.0, 3.0], $layout->cellOrigin(10));
    }
}
