<?php

namespace App\Support\SkuImport;

class SkuImportFields
{
    /** @return SkuImportField[] */
    public static function all(): array
    {
        return [
            // SKU fields
            new SkuImportField('sku', 'sku_import.field_sku', 'sku', true,
                ['required', 'string', 'max:255'], 'string',
                ['sku code', 'skucode', 'item code', 'article', 'seller sku', '商品コード', 'コード', '品番', 'SKUコード']),

            new SkuImportField('name', 'sku_import.field_name', 'sku', true,
                ['required', 'string', 'max:255'], 'string',
                ['product name', 'item name', 'title', 'product title', '商品名', '名前', '名称', '商品名称']),

            new SkuImportField('name_ja', 'sku_import.field_name_ja', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['name ja', 'name_ja', 'japanese name', '日本語名', 'SKU日本語名']),

            new SkuImportField('name_zh_tw', 'sku_import.field_name_zh_tw', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['name zh tw', 'name_zhtw', 'traditional chinese name', '繁體中文名', '中文名稱', 'SKU繁體名']),

            new SkuImportField('name_zh_cn', 'sku_import.field_name_zh_cn', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['name zh cn', 'name_zhcn', 'simplified chinese name', '简体中文名', '中文名称', 'SKU简体名']),

            new SkuImportField('platform_sku', 'sku_import.field_platform_sku', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['platform sku', 'asin', 'amazon sku', 'shopify sku', 'external sku', 'プラットフォームSKU']),

            new SkuImportField('platform_product_id', 'sku_import.field_platform_product_id', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['platform product id', 'product id', 'parent asin', 'プラットフォーム商品ID']),

            new SkuImportField('platform_variant_id', 'sku_import.field_platform_variant_id', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['platform variant id', 'variant id', 'バリエーションID']),

            new SkuImportField('platform_variant_name', 'sku_import.field_platform_variant_name', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['platform variant name', 'variant name', 'バリエーション名']),

            new SkuImportField('platform_label_code', 'sku_import.field_platform_label_code', 'sku', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['platform label code', 'fnsku', 'label code', 'ラベルコード']),

            new SkuImportField('status', 'sku_import.field_sku_status', 'sku', false,
                ['nullable', 'string'], 'enum',
                ['sku status', 'status', 'state', 'ステータス', 'SKUステータス']),

            new SkuImportField('note', 'sku_import.field_sku_note', 'sku', false,
                ['nullable', 'string'], 'string',
                ['sku note', 'note', 'memo', 'remarks', 'メモ', '備考', 'SKUメモ']),

            // Stock item fields
            new SkuImportField('stock_item_code', 'sku_import.field_stock_item_code', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['stock item code', 'stock code', 'si code', '在庫コード', '在庫品目コード']),

            new SkuImportField('si_name_ja', 'sku_import.field_si_name_ja', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['stock name ja', 'si name ja', 'stock item name ja', '在庫品目日本語名']),

            new SkuImportField('si_name_zh_tw', 'sku_import.field_si_name_zh_tw', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['stock name zh tw', 'si name zh tw', 'stock item name zhtw', '在庫品目繁體名']),

            new SkuImportField('si_name_zh_cn', 'sku_import.field_si_name_zh_cn', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['stock name zh cn', 'si name zh cn', 'stock item name zhcn', '在庫品目简体名']),

            new SkuImportField('short_name', 'sku_import.field_short_name', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['short name', 'short', '略称', '短縮名']),

            new SkuImportField('brand', 'sku_import.field_brand', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['brand', 'manufacturer', 'maker', 'ブランド', 'メーカー']),

            new SkuImportField('model_number', 'sku_import.field_model_number', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['model number', 'model', 'model no', '型番', 'モデル番号']),

            new SkuImportField('variation_code', 'sku_import.field_variation_code', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['variation code', 'variant code', 'option code', 'バリエーションコード']),

            new SkuImportField('color', 'sku_import.field_color', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['color', 'colour', 'カラー', '色']),

            new SkuImportField('size', 'sku_import.field_size', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['size', 'サイズ', '寸法サイズ']),

            new SkuImportField('barcode', 'sku_import.field_barcode', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['barcode', 'jan', 'ean', 'upc', 'gtin', 'ean13', 'jan13', 'バーコード', 'JANコード']),

            new SkuImportField('barcode_type', 'sku_import.field_barcode_type', 'stock_item', false,
                ['nullable', 'string'], 'enum',
                ['barcode type', 'バーコード種別']),

            new SkuImportField('product_type', 'sku_import.field_product_type', 'stock_item', false,
                ['nullable', 'string', 'max:255'], 'string',
                ['product type', 'category', 'type', '商品種別', '商品カテゴリ']),

            new SkuImportField('is_dangerous_goods', 'sku_import.field_is_dangerous_goods', 'stock_item', false,
                ['nullable'], 'bool',
                ['dangerous goods', 'is dangerous', 'hazmat', 'dangerous', '危険物', '危険物フラグ']),

            new SkuImportField('requires_expiry_tracking', 'sku_import.field_requires_expiry_tracking', 'stock_item', false,
                ['nullable'], 'bool',
                ['expiry tracking', 'requires expiry', 'expiry', 'expiry date', '賞味期限管理', '期限管理']),

            new SkuImportField('requires_lot_tracking', 'sku_import.field_requires_lot_tracking', 'stock_item', false,
                ['nullable'], 'bool',
                ['lot tracking', 'requires lot', 'lot', 'ロット管理', 'ロット']),

            new SkuImportField('weight_value', 'sku_import.field_weight_value', 'stock_item', false,
                ['nullable', 'numeric', 'min:0'], 'decimal',
                ['weight', 'weight value', '重量', '重さ']),

            new SkuImportField('weight_unit', 'sku_import.field_weight_unit', 'stock_item', false,
                ['nullable', 'string', 'max:20'], 'string',
                ['weight unit', '重量単位']),

            new SkuImportField('length_value', 'sku_import.field_length_value', 'stock_item', false,
                ['nullable', 'numeric', 'min:0'], 'decimal',
                ['length', 'length value', '長さ', '全長']),

            new SkuImportField('width_value', 'sku_import.field_width_value', 'stock_item', false,
                ['nullable', 'numeric', 'min:0'], 'decimal',
                ['width', 'width value', '幅', '横幅']),

            new SkuImportField('height_value', 'sku_import.field_height_value', 'stock_item', false,
                ['nullable', 'numeric', 'min:0'], 'decimal',
                ['height', 'height value', '高さ']),

            new SkuImportField('dimension_unit', 'sku_import.field_dimension_unit', 'stock_item', false,
                ['nullable', 'string', 'max:20'], 'string',
                ['dimension unit', 'dim unit', '寸法単位']),

            new SkuImportField('description', 'sku_import.field_description', 'stock_item', false,
                ['nullable', 'string'], 'string',
                ['description', 'details', 'detail', '説明', '商品説明']),

            new SkuImportField('si_note', 'sku_import.field_si_note', 'stock_item', false,
                ['nullable', 'string'], 'string',
                ['stock item note', 'si note', 'stock note', '在庫品目メモ']),

            new SkuImportField('handling_note', 'sku_import.field_handling_note', 'stock_item', false,
                ['nullable', 'string'], 'string',
                ['handling note', 'handling', 'handling instructions', '取扱メモ', '取り扱い注意']),

            new SkuImportField('si_status', 'sku_import.field_si_status', 'stock_item', false,
                ['nullable', 'string'], 'enum',
                ['stock item status', 'si status', 'stock status', '在庫品目ステータス']),
        ];
    }

    /** @return array<string, string> field_key => matched_header or '' */
    public static function autoGuess(array $fileHeaders): array
    {
        $fields = self::all();
        $mapping = [];

        foreach ($fields as $field) {
            $mapping[$field->key] = '';
        }

        $usedHeaders = [];

        foreach ($fields as $field) {
            foreach ($fileHeaders as $header) {
                $normalized = self::normalize($header);

                if ($normalized === '') {
                    continue;
                }

                if ($normalized === self::normalize($field->key)) {
                    if (! in_array($header, $usedHeaders, true)) {
                        $mapping[$field->key] = $header;
                        $usedHeaders[] = $header;
                    }
                    break;
                }

                $matched = false;
                foreach (['en', 'ja', 'zh_TW', 'zh_CN'] as $locale) {
                    $label = trans($field->labelKey, [], $locale);
                    if (is_string($label) && $normalized === self::normalize($label)) {
                        if (! in_array($header, $usedHeaders, true)) {
                            $mapping[$field->key] = $header;
                            $usedHeaders[] = $header;
                        }
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
                    break;
                }

                foreach ($field->aliases as $alias) {
                    if ($normalized === self::normalize($alias)) {
                        if (! in_array($header, $usedHeaders, true)) {
                            $mapping[$field->key] = $header;
                            $usedHeaders[] = $header;
                        }
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
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
