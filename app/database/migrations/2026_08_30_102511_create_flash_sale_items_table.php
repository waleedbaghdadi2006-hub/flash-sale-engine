<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flash_sale_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('sale_price', 10, 2);
            $table->unsignedInteger('quantity_limit');
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->unsignedInteger('version')->default(0)->comment('optimistic locking to prevent overselling');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['flash_sale_id', 'product_id'], 'uq_flash_sale_items_sale_product');
            $table->index('product_id', 'idx_flash_sale_items_product_id');

            $table->foreign('flash_sale_id', 'fk_flash_sale_items_sale')
                ->references('id')->on('flash_sales')
                ->onDelete('cascade');

            $table->foreign('product_id', 'fk_flash_sale_items_product')
                ->references('id')->on('products')
                ->onDelete('cascade');
        });

        DB::statement('ALTER TABLE flash_sale_items ADD CONSTRAINT chk_flash_sale_items_price CHECK (sale_price >= 0)');
        DB::statement('ALTER TABLE flash_sale_items ADD CONSTRAINT chk_flash_sale_items_sold CHECK (quantity_sold <= quantity_limit)');
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_items');
    }
};
