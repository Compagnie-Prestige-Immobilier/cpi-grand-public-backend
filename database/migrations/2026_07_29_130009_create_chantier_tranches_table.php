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
        Schema::create('chantier_tranches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chantier_id')->constrained('chantiers')->cascadeOnDelete();
            $table->integer('num');
            $table->string('label');
            $table->text('description')->nullable();
            $table->integer('pct')->default(0);
            $table->string('etat')->default('en-attente');
            $table->date('date')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chantier_tranches');
    }
};
