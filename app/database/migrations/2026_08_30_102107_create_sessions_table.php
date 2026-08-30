<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            // Primary key is a VARCHAR (e.g. a session identifier), not auto-incrementing.
            $table->string('id', 255)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 255);
            $table->string('device_info', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('token_hash', 'uq_sessions_token_hash');
            $table->index('user_id', 'idx_sessions_user_id');
            $table->index('expires_at', 'idx_sessions_expires_at');

            $table->foreign('user_id', 'fk_sessions_user')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
