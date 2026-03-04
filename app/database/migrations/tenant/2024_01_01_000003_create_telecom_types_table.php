<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telecom_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');       // mobile, fixe, internet, iot, ucaas
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Seed les types de base
        DB::table('telecom_types')->insert([
            ['name' => 'Mobile', 'slug' => 'mobile', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Fixe', 'slug' => 'fixe', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Internet', 'slug' => 'internet', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'IoT', 'slug' => 'iot', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'UCaaS', 'slug' => 'ucaas', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('telecom_types');
    }
};
