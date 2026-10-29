<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postback_identities', function (Blueprint $table): void {
            $table->char('hash', 64)->primary();
            $table->timestamp('accepted_at', 6)->nullable();
        });
        Schema::create('postbacks', function (Blueprint $table): void {
            $table->id();
            $table->char('identity_hash', 64)->index();
            $table->string('click_id');
            $table->string('event');
            $table->string('original_amount', 128);
            $table->string('amount', 27);
            $table->timestamp('received_at', 6)->index();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postbacks');
        Schema::dropIfExists('postback_identities');
    }
};
