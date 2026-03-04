<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collaborator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('telecom_type_id')->constrained('telecom_types');

            $table->string('number', 20)->nullable();       // Numéro de téléphone / identifiant
            $table->string('label')->nullable();             // Libellé de la ligne
            $table->enum('status', ['active', 'suspended', 'terminated', 'pending'])->default('pending');

            // Fournisseur
            $table->string('provider')->nullable();          // transatel, unyc, ielo, wazo...
            $table->string('provider_ref')->nullable();      // Référence chez le fournisseur

            $table->date('activation_date')->nullable();
            $table->date('termination_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
            $table->index('collaborator_id');
            $table->index('status');
            $table->index('number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lines');
    }
};
