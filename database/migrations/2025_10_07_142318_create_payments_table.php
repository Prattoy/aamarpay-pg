<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('service_from');
            $table->string('reference_id');
            $table->float('amount');
            $table->string('currency');
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('return_url');
            $table->string('webhook_url');
            $table->string('pg_txn_id')->nullable();
            $table->string('bank_txn_id')->nullable();
            $table->string('initiated_yn')->default('N');
            $table->timestamps('initiated_at');
            $table->string('succeed_yn')->default('N');
            $table->timestamps('succeed_at');
            $table->string('verified_yn')->default('N');
            $table->timestamps('verified_at');
            $table->string('service_provided_yn')->default('N');
            $table->timestamps('service_provided_at');
            $table->timestamps('webhook_sent_at');
            $table->integer('webhook_attempts')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
