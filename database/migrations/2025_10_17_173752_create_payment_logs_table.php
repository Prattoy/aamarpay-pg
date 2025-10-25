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
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service_from');
            $table->string('reference_id');
            $table->string('state');
            $table->string('pg_txn_id');
            $table->string('bank_txn_id');
            $table->text('request_payload')->nullable();
            $table->text('api_response')->nullable();
            $table->text('response_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
