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
        Schema::create('decaissements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->decimal('terrain_montant', 15, 2)->default(0);
            $table->boolean('terrain_decaisse')->default(false);
            $table->timestamp('terrain_date')->nullable();
            $table->json('foncier')->default('[true,false,false,false,false]');
            $table->boolean('construction_active')->default(false);
            $table->decimal('construction_montant', 15, 2)->default(0);
            $table->json('tranches')->default('[]');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decaissements');
    }
};
