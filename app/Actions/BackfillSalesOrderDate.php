<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class BackfillSalesOrderDate
{
    public function __invoke(): void
    {
        DB::table('sales_orders')
            ->whereNull('order_date')
            ->update([
                'order_date' => DB::raw('COALESCE(platform_ordered_at, created_at)'),
            ]);
    }
}
