<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // Raison sociale
            $table->string('siret', 14)->nullable();
            $table->string('siren', 9)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->enum('status', ['prospect', 'active', 'inactive', 'churned'])->default('prospect');

            // Adresse principale
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('zipcode', 10)->nullable();
            $table->string('country', 2)->default('FR');

            // Facturation
            $table->enum('billing_mode', ['monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->string('payment_method')->nullable(); // prelevement, virement, CB

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('siret');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
