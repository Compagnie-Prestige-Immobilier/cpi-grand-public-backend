<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace du membre du personnel qui a préparé le décaissement (saisie des
 * montants, des tranches, des commentaires).
 *
 * Sert le contrôle à quatre yeux : la personne qui valide un versement d'argent
 * réel ne peut pas être celle qui l'a préparé. Sans cette colonne, la règle est
 * inapplicable — rien ne dit qui a saisi quoi.
 *
 * Nullable et sans valeur par défaut : les décaissements existants n'ont pas de
 * préparateur connu, et rien ne permet de le reconstituer a posteriori (le
 * journal d'activité ne remonte qu'aux actions effectivement journalisées).
 * Ils restent donc validables par n'importe quel habilité, ce qui est le
 * comportement actuel — la règle ne s'applique qu'aux préparations futures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decaissements', function (Blueprint $table): void {
            $table->foreignUuid('prepared_by')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('decaissements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prepared_by');
        });
    }
};
