<?php

namespace App\Support\StockAdjustmentImport;

class StockAdjustmentImportFields
{
    /** @return StockAdjustmentImportField[] */
    public static function all(): array
    {
        return [
            new StockAdjustmentImportField('identifier', 'stock_adjustment_import.field_identifier', true, [
                'identifier', 'stock item', 'stock item code', 'item code', 'tenant item code', 'sku', 'barcode',
            ]),
            new StockAdjustmentImportField('quantity', 'stock_adjustment_import.field_quantity', true, [
                'quantity', 'qty', 'adjust qty', 'adjustment qty',
            ]),
            new StockAdjustmentImportField('line_note', 'stock_adjustment_import.field_line_note', false, [
                'line note', 'note', 'memo', 'remark', 'remarks',
            ]),
            new StockAdjustmentImportField('reference_no', 'stock_adjustment_import.field_reference_no', false, [
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

                if ($normalized === self::normalize($field->key)) {
                    $mapping[$field->key] = $header;
                    $usedHeaders[] = $header;
                    break;
                }

                $label = trans($field->labelKey, [], 'en');

                if (is_string($label) && $normalized === self::normalize($label)) {
                    $mapping[$field->key] = $header;
                    $usedHeaders[] = $header;
                    break;
                }

                foreach ($field->aliases as $alias) {
                    if ($normalized === self::normalize($alias)) {
                        $mapping[$field->key] = $header;
                        $usedHeaders[] = $header;
                        break 2;
                    }
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
