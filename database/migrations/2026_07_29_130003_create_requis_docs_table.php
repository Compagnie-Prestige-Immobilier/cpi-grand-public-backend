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
        Schema::create('requis_docs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('doc_id');           // identite, revenus, bancaires
            $table->string('label');
            $table->string('status')->default('en-attente');
            $table->text('commentaire')->nullable();
            $table->timestamp('date_validation')->nullable();
            $table->string('agent_name')->nullable();
            $table->integer('version')->default(0);
            $table->string('submitted_label')->nullable();
            $table->string('date')->nullable();
            $table->string('taille')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'doc_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requis_docs');
    }
};
