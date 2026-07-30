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
        Schema::create('chantier_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chantier_id')->constrained('chantiers')->cascadeOnDelete();
            $table->integer('phase');
            $table->string('titre');
            $table->text('description');
            $table->timestamp('date')->useCurrent();
            $table->string('heure');
            $table->string('auteur');
            $table->string('type');
            $table->boolean('visible_client')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chantier_publications');
    }
};
