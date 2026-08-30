<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity_available')->default(0);
            $table->unsignedInteger('quantity_reserved')->default(0);
            $table->unsignedInteger('version')->default(0)->comment('optimistic locking to prevent overselling');
            $table->dateTime('last_restocked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('product_id', 'uq_inventory_product_id');

            $table->foreign('product_id', 'fk_inventory_product')
                ->references('id')->on('products')
                ->onDelete('cascade');
        });

        DB::statement('ALTER TABLE inventory ADD CONSTRAINT chk_inventory_available CHECK (quantity_available >= 0)');
        DB::statement('ALTER TABLE inventory ADD CONSTRAINT chk_inventory_reserved CHECK (quantity_reserved >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
