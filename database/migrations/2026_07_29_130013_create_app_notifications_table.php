<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nommée `app_notifications` (et non `notifications`) pour ne jamais entrer
     * en collision avec la table de notifications database de Laravel.
     */
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->string('titre');
            $table->text('message');
            $table->timestamp('date')->useCurrent();
            $table->string('heure');
            $table->boolean('lu')->default(false);
            $table->string('type');
            $table->string('target_page')->nullable();
            $table->string('target_sub')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
