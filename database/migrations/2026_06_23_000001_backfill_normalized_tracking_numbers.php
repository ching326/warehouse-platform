<?php

use App\Actions\BackfillNormalizedTrackingNumbers;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BackfillNormalizedTrackingNumbers::class)->handle();
    }

    public function down(): void
    {
        //
    }
};
