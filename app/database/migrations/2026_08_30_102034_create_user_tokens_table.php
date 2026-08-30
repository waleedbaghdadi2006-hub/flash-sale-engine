<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['email_verification', 'password_reset']);
            $table->string('token_hash', 255);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('token_hash', 'uq_user_tokens_hash');
            $table->index('user_id', 'idx_user_tokens_user_id');
            $table->index('type', 'idx_user_tokens_type');

            $table->foreign('user_id', 'fk_user_tokens_user')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tokens');
    }
};
