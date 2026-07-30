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
        Schema::create('demandes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->boolean('submitted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->string('type_projet')->default('financement');
            $table->string('nature_projet')->default('acquisition');
            $table->decimal('montant', 15, 2)->nullable();
            $table->string('duree')->default('15');
            $table->decimal('apport', 15, 2)->default(0);
            $table->string('region')->default('Dakar');
            $table->string('commune')->nullable();
            $table->string('adresse_projet')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demandes');
    }
};
