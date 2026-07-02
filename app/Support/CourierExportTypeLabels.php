<?php

namespace App\Support;

use App\Services\Labels\AddressLabelExportService;

class CourierExportTypeLabels
{
    public static function label(string $type): string
    {
        return self::labels()[$type] ?? strtoupper($type);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            CourierCarrier::YAMATO => __('outbound.courier_label_export_type_yamato'),
            CourierCarrier::SAGAWA => __('outbound.courier_label_export_type_sagawa'),
            AddressLabelExportService::EXPORT_TYPE_LABEL10 => __('outbound.courier_label_export_type_label10'),
        ];
    }
}
