<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('flash_sale_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedBigInteger('shipping_address_id')->nullable();
            $table->unsignedBigInteger('billing_address_id')->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('shipping_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2);
            $table->char('currency', 3)->default('USD');
            $table->enum('status', ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled', 'refunded'])
                ->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('order_number', 'uq_orders_order_number');
            $table->index('user_id', 'idx_orders_user_id');
            $table->index('status', 'idx_orders_status');
            $table->index('created_at', 'idx_orders_created_at');

            $table->foreign('user_id', 'fk_orders_user')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('flash_sale_id', 'fk_orders_flash_sale')
                ->references('id')->on('flash_sales')
                ->onDelete('set null');

            $table->foreign('coupon_id', 'fk_orders_coupon')
                ->references('id')->on('coupons')
                ->onDelete('set null');

            $table->foreign('shipping_address_id', 'fk_orders_shipping_address')
                ->references('id')->on('addresses')
                ->onDelete('set null');

            $table->foreign('billing_address_id', 'fk_orders_billing_address')
                ->references('id')->on('addresses')
                ->onDelete('set null');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_total CHECK (total_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
