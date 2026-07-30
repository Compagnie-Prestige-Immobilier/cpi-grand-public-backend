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
        Schema::create('cpi_docs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('categorie');
            $table->string('nom');
            $table->string('reference')->nullable();
            $table->timestamp('date_creation')->useCurrent();
            $table->timestamp('date_publication')->nullable();
            $table->string('version')->default('1.0');
            $table->string('status')->default('brouillon');
            $table->string('auteur');
            $table->string('fichier')->nullable();
            $table->text('commentaire')->nullable();
            $table->boolean('visible_client')->default(false);
            $table->boolean('signature_requise')->default(false);
            $table->string('taille')->nullable();
            $table->string('format')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpi_docs');
    }
};
