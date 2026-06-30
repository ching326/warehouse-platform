<?php

namespace Tests\Unit;

use App\Services\Labels\SkuLabelPdfService;
use Tests\TestCase;

class SkuLabelPdfServiceTest extends TestCase
{
    public function test_renders_thermal_layout_as_62_by_29_mm(): void
    {
        $pdf = app(SkuLabelPdfService::class)->render('62x29_thermal', [
            ['value' => 'SKU-001', 'code_text' => 'SKU-001', 'name' => 'Test SKU'],
        ], [0]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('/\\/MediaBox\\s*\\[0\\.000000\\s+0\\.000000\\s+175\\.748031\\s+82\\.204724\\]/', $pdf);
    }

    public function test_sheet_layout_accepts_skip_cells(): void
    {
        $pdf = app(SkuLabelPdfService::class)->render('40up_a4', [
            ['value' => 'SKU-001', 'code_text' => 'SKU-001', 'name' => null],
            ['value' => 'SKU-002', 'code_text' => 'SKU-002', 'name' => null],
        ], [0, 1]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertNotSame('', $pdf);
    }
}
