<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 280);
            $table->text('description')->nullable();
            $table->decimal('base_price', 10, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('sku', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('deleted_at')->nullable();

            $table->unique('sku', 'uq_products_sku');
            $table->unique('slug', 'uq_products_slug');
            $table->index('category_id', 'idx_products_category_id');
            $table->index('deleted_at', 'idx_products_deleted_at');

            $table->foreign('category_id', 'fk_products_category')
                ->references('id')->on('categories')
                ->onDelete('set null');
        });

        // NOTE: product_images/products model simple products only. If size/color
        // variants are needed later, add a product_variants table and point
        // inventory + order_items at variant_id instead of product_id.
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_price CHECK (base_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
