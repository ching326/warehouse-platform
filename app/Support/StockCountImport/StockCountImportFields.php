<?php

namespace App\Support\StockCountImport;

class StockCountImportFields
{
    /** @return StockCountImportField[] */
    public static function all(): array
    {
        return [
            new StockCountImportField('identifier', 'stock_counts.field_identifier', true, [
                'identifier', 'stock item', 'stock item code', 'item code', 'tenant item code', 'sku', 'barcode',
            ]),
            new StockCountImportField('counted_qty', 'stock_counts.field_counted_qty', true, [
                'counted qty', 'counted quantity', 'actual qty', 'actual quantity', 'quantity', 'qty',
            ]),
            new StockCountImportField('line_note', 'stock_counts.field_line_note', false, [
                'line note', 'note', 'memo', 'remark', 'remarks',
            ]),
            new StockCountImportField('reference_no', 'stock_counts.field_reference_no', false, [
                'reference no', 'reference', 'ref no', 'ref', 'document no',
            ]),
        ];
    }

    /** @return array<string, string> */
    public static function autoGuess(array $fileHeaders): array
    {
        $mapping = [];

        foreach (self::all() as $field) {
            $mapping[$field->key] = '';
        }

        $usedHeaders = [];

        foreach (self::all() as $field) {
            foreach ($fileHeaders as $header) {
                if (in_array($header, $usedHeaders, true)) {
                    continue;
                }

                $normalized = self::normalize($header);
                $label = trans($field->labelKey, [], 'en');

                if (
                    $normalized === self::normalize($field->key)
                    || (is_string($label) && $normalized === self::normalize($label))
                    || collect($field->aliases)->contains(fn (string $alias): bool => $normalized === self::normalize($alias))
                ) {
                    $mapping[$field->key] = $header;
                    $usedHeaders[] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    private static function normalize(string $value): string
    {
        return strtolower(preg_replace('/[\s\-_\/\.]+/', '', trim($value)) ?? '');
    }
}
