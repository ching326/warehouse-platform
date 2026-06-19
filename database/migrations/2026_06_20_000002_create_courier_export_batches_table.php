<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_export_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('carrier');
            $table->string('file_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->unsignedInteger('order_count')->default(0);
            $table->foreignId('exported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at');
            $table->timestamps();

            $table->index(['carrier', 'exported_at']);
            $table->index(['tenant_id', 'exported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_export_batches');
    }
};
