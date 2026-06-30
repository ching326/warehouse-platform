<?php

namespace App\Support\Labels;

use InvalidArgumentException;

class LabelLayout
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        public readonly string $key,
        private readonly array $definition,
    ) {
        if ($this->cols() <= 0 || $this->rows() <= 0) {
            throw new InvalidArgumentException('Label layout must have rows and columns.');
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromDefinition(string $key, array $definition): self
    {
        return new self($key, $definition);
    }

    public static function fromConfig(string $key): self
    {
        $definition = config('label_layouts.'.$key);

        if (! is_array($definition)) {
            throw new InvalidArgumentException('Unknown label layout.');
        }

        return self::fromDefinition($key, $definition);
    }

    public function cellsPerPage(): int
    {
        return $this->cols() * $this->rows();
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function cellOrigin(int $cellIndex): array
    {
        if ($cellIndex < 0 || $cellIndex >= $this->cellsPerPage()) {
            throw new InvalidArgumentException('Cell index is outside the page.');
        }

        if ($this->fill() === 'column') {
            $col = intdiv($cellIndex, $this->rows());
            $row = $cellIndex % $this->rows();
        } else {
            $row = intdiv($cellIndex, $this->cols());
            $col = $cellIndex % $this->cols();
        }

        return [
            $this->float('margin_left') + ($col * ($this->float('cell_width') + $this->float('gutter_x'))),
            $this->float('margin_top') + ($row * ($this->float('cell_height') + $this->float('gutter_y'))),
        ];
    }

    public function supportsSkip(): bool
    {
        return (bool) ($this->definition['supports_skip'] ?? false);
    }

    public function cols(): int
    {
        return (int) $this->definition['cols'];
    }

    public function rows(): int
    {
        return (int) $this->definition['rows'];
    }

    public function name(): string
    {
        return (string) $this->definition['name'];
    }

    public function paper(): mixed
    {
        return $this->definition['paper'];
    }

    public function orientation(): string
    {
        return (string) $this->definition['orientation'];
    }

    public function pageWidth(): float
    {
        return $this->float('page_width');
    }

    public function pageHeight(): float
    {
        return $this->float('page_height');
    }

    public function cellWidth(): float
    {
        return $this->float('cell_width');
    }

    public function cellHeight(): float
    {
        return $this->float('cell_height');
    }

    public function cellPadding(): float
    {
        return $this->float('cell_padding');
    }

    public function barcodeHeight(): float
    {
        return $this->float('barcode_height');
    }

    public function codeFontPt(): float
    {
        return $this->float('code_font_pt');
    }

    public function nameFontPt(): float
    {
        return $this->float('name_font_pt');
    }

    private function fill(): string
    {
        return (string) ($this->definition['fill'] ?? 'row');
    }

    private function float(string $key): float
    {
        return (float) $this->definition[$key];
    }
}
