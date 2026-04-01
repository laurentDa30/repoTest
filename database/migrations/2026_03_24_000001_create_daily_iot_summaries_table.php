<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_iot_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('line_id')->constrained('lines');
            $table->date('date');
            $table->foreignId('telecom_type_id')->nullable()->constrained('telecom_types');

            // Consos — majoritairement data, mais SMS/appels possibles (surtaxés)
            $table->unsignedInteger('sms')->default(0);
            $table->unsignedInteger('mms')->default(0);
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('calls_duration')->default(0);
            $table->unsignedBigInteger('data')->default(0);

            // Facturation
            $table->decimal('out_of_plan', 15, 2)->default(0);
            $table->decimal('total_charge', 15, 4)->default(0);
            $table->decimal('total_price', 15, 4)->default(0);

            // Empreinte carbone
            $table->decimal('carbon_sms', 15, 8)->default(0);
            $table->decimal('carbon_mms', 15, 8)->default(0);
            $table->decimal('carbon_calls', 15, 8)->default(0);
            $table->double('carbon_datas_mobile')->default(0);
            $table->double('carbon_devices')->default(0);
            $table->double('carbon_total')->default(0);

            $table->timestamps();

            $table->unique(['line_id', 'date', 'telecom_type_id'], 'daily_iot_unique');
            $table->index(['client_id', 'date'], 'idx_iot_client_date');
            $table->index(['line_id', 'date'], 'idx_iot_line_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_iot_summaries');
    }
};
