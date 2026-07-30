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
        Schema::create('chantiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('projet')->nullable();
            $table->string('reference')->nullable();
            $table->string('localisation')->nullable();
            $table->string('chef_chantier')->nullable();
            $table->string('entreprise')->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_livraison')->nullable();
            $table->integer('progression')->default(0);
            $table->string('etape_actuelle')->default('Non démarré');
            $table->string('statut')->default('non-demarre');
            $table->string('derniere_maj')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chantiers');
    }
};
