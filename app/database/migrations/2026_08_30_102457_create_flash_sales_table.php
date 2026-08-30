<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['pending', 'active', 'ended', 'cancelled'])->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('status', 'idx_flash_sales_status');
            $table->index(['starts_at', 'ends_at'], 'idx_flash_sales_starts_ends');
        });

        DB::statement('ALTER TABLE flash_sales ADD CONSTRAINT chk_flash_sales_dates CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sales');
    }
};
