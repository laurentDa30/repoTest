<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Collaborateurs = employés des clients (entité pivot / centre de coût)
        // C'est le CLIENT qui paye pour tout ce que le collaborateur détient
        Schema::create('collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('lastname');
            $table->string('firstname');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('designation')->nullable();    // Poste / fonction
            $table->string('service')->nullable();         // Service / département

            // Lien infogérance (GLPI)
            $table->boolean('is_register_to_glpi')->default(false);
            $table->string('id_glpi')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborators');
    }
};
