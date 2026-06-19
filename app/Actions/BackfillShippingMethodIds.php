<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class BackfillShippingMethodIds
{
    public function __invoke(): void
    {
        $mapping = [
            'yamato' => 'yamato_tqb',
            'sagawa' => 'sagawa_thb',
            'japan_post' => 'japan_post_yupack',
            'other' => 'other',
        ];

        foreach ($mapping as $legacyCarrier => $methodCode) {
            $methodId = DB::table('shipping_methods')->where('code', $methodCode)->value('id');

            if (! $methodId) {
                continue;
            }

            DB::table('sales_orders')
                ->whereNull('shipping_method_id')
                ->where('shipping_method', $legacyCarrier)
                ->update(['shipping_method_id' => $methodId]);
        }
    }
}
