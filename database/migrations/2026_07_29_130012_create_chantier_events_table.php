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
        Schema::create('chantier_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chantier_id')->constrained('chantiers')->cascadeOnDelete();
            $table->string('titre');
            $table->string('type');
            $table->date('date');
            $table->string('heure')->nullable();
            $table->text('description');
            $table->string('statut')->default('prevu');
            $table->boolean('visible_client')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chantier_events');
    }
};
