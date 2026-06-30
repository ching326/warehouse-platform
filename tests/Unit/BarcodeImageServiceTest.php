<?php

namespace Tests\Unit;

use App\Services\Barcode\BarcodeImageService;
use PHPUnit\Framework\TestCase;

class BarcodeImageServiceTest extends TestCase
{
    public function test_code128_svg_returns_svg_markup(): void
    {
        $svg = (new BarcodeImageService)->code128Svg('X00ABC123');

        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_code128_svg_returns_empty_string_for_blank_input(): void
    {
        $this->assertSame('', (new BarcodeImageService)->code128Svg('   '));
    }

    public function test_code128_svg_does_not_echo_raw_text_as_html(): void
    {
        $svg = (new BarcodeImageService)->code128Svg('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringNotContainsString('</script>', $svg);
    }
}
