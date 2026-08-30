<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('provider', 50)->comment('e.g. stripe, paypal');
            $table->string('provider_transaction_id', 255)->nullable();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('USD');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded', 'partially_refunded'])
                ->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('provider_transaction_id', 'uq_payments_provider_txn');
            $table->index('order_id', 'idx_payments_order_id');
            $table->index('status', 'idx_payments_status');

            $table->foreign('order_id', 'fk_payments_order')
                ->references('id')->on('orders')
                ->onDelete('restrict');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
