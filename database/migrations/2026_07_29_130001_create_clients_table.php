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
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('ref')->unique();
            $table->string('statut')->default('Dossier en préparation');
            $table->integer('progression')->default(0);
            $table->string('project_nom')->nullable();
            $table->string('adresse')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('employer')->nullable();
            $table->string('fonction')->nullable();
            $table->string('conseiller')->nullable();
            $table->foreignUuid('conseiller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('banque')->nullable();
            $table->integer('dossier_etape')->default(0);
            $table->date('date_inscription')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
