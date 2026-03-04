<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('iccid', 20)->unique();
            $table->string('imsi', 15)->nullable();
            $table->string('pin', 10)->nullable();
            $table->string('puk', 10)->nullable();
            $table->enum('status', ['stock', 'active', 'suspended', 'terminated'])->default('stock');
            $table->enum('type', ['standard', 'micro', 'nano', 'esim', 'iot'])->default('nano');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sims');
    }
};
