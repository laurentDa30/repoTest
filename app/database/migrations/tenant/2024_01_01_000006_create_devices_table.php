<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Appareils (stock + affecté aux collaborateurs)
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('brand')->nullable();               // Marque (Apple, Samsung...)
            $table->string('model')->nullable();               // Modèle
            $table->string('serial_number')->nullable();
            $table->string('imei', 20)->nullable();
            $table->enum('type', ['phone', 'tablet', 'laptop', 'desktop', 'accessory', 'other'])->default('phone');
            $table->enum('status', ['stock', 'assigned', 'maintenance', 'retired'])->default('stock');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('serial_number');
        });

        // Pivot : appareil ↔ collaborateur (le client paye pour cet appareil)
        Schema::create('device_collaborator', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collaborator_id')->constrained()->cascadeOnDelete();
            $table->date('assigned_at');
            $table->date('returned_at')->nullable();
            $table->timestamps();

            $table->index('collaborator_id');
        });

        // Pivot : appareil ↔ client (vue parc client)
        Schema::create('device_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_client');
        Schema::dropIfExists('device_collaborator');
        Schema::dropIfExists('devices');
    }
};
