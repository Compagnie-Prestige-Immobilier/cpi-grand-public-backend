<?php

use App\Enums\StatutCompte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La vérification d'e-mail devient temporairement non bloquante
 * (`AuthController::register`) : quiconque était bloqué en attente d'un clic
 * sur son courriel doit passer directement en file d'attente administrative,
 * comme s'il venait de s'inscrire aujourd'hui — sans quoi seuls les NOUVEAUX
 * comptes profiteraient de l'assouplissement, et les précédents resteraient
 * enfermés dehors par une règle qui ne s'applique plus à personne d'autre.
 *
 * `email_verified_at` n'est pas touché : si la personne avait déjà vérifié,
 * ça le reste ; si non, le courriel reste cliquable, il ne fait simplement
 * plus barrage.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('statut_compte', StatutCompte::EmailAVerifier->value)
            ->update(['statut_compte' => StatutCompte::EnAttenteValidation->value]);
    }

    /**
     * Pas de retour en arrière possible sur QUI a été basculé : reconstituer
     * l'ensemble d'origine demanderait de savoir qui avait déjà vérifié son
     * adresse avant CETTE migration, une information que la mise à jour
     * ci-dessus n'a pas effacée mais que rien ne permet de recouper. Rejouer
     * `down()` laisserait tout le monde en `EnAttenteValidation`, ce qui reste
     * cohérent avec la règle assouplie — jamais un compte enfermé dehors par
     * erreur.
     */
    public function down(): void {}
};
