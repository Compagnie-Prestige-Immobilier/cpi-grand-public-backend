<?php

use App\Enums\StatutCompte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validation des comptes par un administrateur.
 *
 * Jusqu'ici, s'inscrire suffisait pour accéder à tout l'espace client. Un
 * administrateur doit désormais valider chaque compte au vu des informations
 * déclarées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('statut_compte')
                ->default(StatutCompte::EmailAVerifier->value)
                ->index();   // la file d'attente de l'admin filtre là-dessus

            // Motif communiqué à la personne en cas de refus : sans lui, elle
            // ne saurait pas quoi corriger avant de resoumettre.
            $table->text('motif_rejet')->nullable();

            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
        });

        // Les comptes existants sont validés d'office : personne ne doit se
        // retrouver enfermé dehors par une règle qui n'existait pas lorsqu'il
        // s'est inscrit. Le personnel CPI l'est aussi — la validation ne
        // concerne que les comptes clients, mais un statut cohérent partout
        // évite d'avoir à traiter le cas « null » à la lecture.
        DB::table('users')->update([
            'statut_compte' => StatutCompte::Valide->value,
            'validated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['statut_compte', 'motif_rejet', 'validated_at']);
        });
    }
};
