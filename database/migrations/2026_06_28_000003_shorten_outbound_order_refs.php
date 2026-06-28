<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('outbound_orders')
            ->where('ref', 'like', 'OB-%')
            ->orderBy('id')
            ->select(['id', 'ref'])
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $ref = $this->shortRef((string) $order->ref);

                    if ($ref === $order->ref) {
                        continue;
                    }

                    DB::table('outbound_orders')
                        ->where('id', $order->id)
                        ->update(['ref' => $ref]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: long tenant-code tails are intentionally dropped.
    }

    private function shortRef(string $ref): string
    {
        if (! preg_match('/^OB-([A-Z0-9]+)-(\d{6})-(\d+)$/i', trim($ref), $matches)) {
            return $ref;
        }

        return 'OB-'.substr(strtoupper($matches[1]), 0, 5).'-'.$matches[2].'-'.str_pad($matches[3], 4, '0', STR_PAD_LEFT);
    }
};
