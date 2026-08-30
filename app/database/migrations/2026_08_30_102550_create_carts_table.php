<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('guest_token', 64)->nullable()->comment('set when there is no logged-in user yet');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('user_id', 'idx_carts_user_id');
            $table->unique('guest_token', 'uq_carts_guest_token');

            $table->foreign('user_id', 'fk_carts_user')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });

        DB::statement('ALTER TABLE carts ADD CONSTRAINT chk_carts_owner CHECK (user_id IS NOT NULL OR guest_token IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
