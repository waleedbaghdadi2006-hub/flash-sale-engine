<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name_snapshot', 255)
                ->comment('captured at purchase time, survives product edits/deletion');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id', 'idx_order_items_order_id');
            $table->index('product_id', 'idx_order_items_product_id');

            $table->foreign('order_id', 'fk_order_items_order')
                ->references('id')->on('orders')
                ->onDelete('cascade');

            $table->foreign('product_id', 'fk_order_items_product')
                ->references('id')->on('products')
                ->onDelete('restrict');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_qty CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
