<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price_snapshot', 10, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['cart_id', 'product_id'], 'uq_cart_items_cart_product');
            $table->index('product_id', 'idx_cart_items_product_id');

            $table->foreign('cart_id', 'fk_cart_items_cart')
                ->references('id')->on('carts')
                ->onDelete('cascade');

            $table->foreign('product_id', 'fk_cart_items_product')
                ->references('id')->on('products')
                ->onDelete('restrict');
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT chk_cart_items_qty CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
