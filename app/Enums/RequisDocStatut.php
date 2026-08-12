<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cycle de vie d'une pièce justificative déposée par le client.
 *
 * Les valeurs sont EXACTEMENT celles déjà en base et déjà consommées par le
 * frontend : cet enum ne change aucun contrat, il donne un nom aux littéraux
 * qui étaient dispersés dans les contrôleurs, les modèles, les statistiques et
 * les tests, et il rend les transitions vérifiables.
 *
 * Avant, `Rule::in` validait les VALEURS mais jamais les TRANSITIONS : une
 * pièce refusée pouvait repasser « acceptée » sans nouveau dépôt, et une pièce
 * jamais déposée être acceptée directement.
 */
#[TypeScript]
enum RequisDocStatut: string implements Statut
{
    case EnAttente = 'en-attente';
    case Depose = 'depose';
    case Verification = 'verification';
    case Accepte = 'accepte';
    case Refuse = 'refuse';
    case ARemplacer = 'a-remplacer';

    /** Libellé affichable, aligné sur ce que montre déjà l'interface. */
    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'À envoyer',
            self::Depose => 'Déposée',
            self::Verification => 'En vérification',
            self::Accepte => 'Acceptée',
            self::Refuse => 'Refusée',
            self::ARemplacer => 'À remplacer',
        };
    }

    /**
     * États atteignables depuis celui-ci.
     *
     * @return list<static>
     */
    public function suivants(): array
    {
        return match ($this) {
            // Le client dépose ; le staff prend en charge, valide ou renvoie.
            self::EnAttente => [self::Depose],
            self::Depose => [self::Verification, self::Accepte, self::Refuse, self::ARemplacer],
            self::Verification => [self::Accepte, self::Refuse, self::ARemplacer],
            // Un refus ou une demande de remplacement se solde normalement par
            // un nouveau dépôt du client. Le retour en vérification reste
            // possible : c'est l'endpoint `verify`, qui existe précisément pour
            // qu'un agent puisse revenir sur son propre refus sans exiger du
            // client qu'il redépose une pièce correcte.
            self::Refuse, self::ARemplacer => [self::Depose, self::Verification],
            // Une pièce acceptée se rouvre par un nouveau dépôt, ou par une
            // remise en vérification si l'acceptation était une erreur. Ce qui
            // reste interdit : passer d'un refus à une acceptation sans
            // repasser par un examen.
            self::Accepte => [self::Depose, self::Verification],
        };
    }

    public function peutAllerVers(Statut $cible): bool
    {
        return in_array($cible, $this->suivants(), true);
    }

    /**
     * Valeurs acceptées par les règles de validation.
     *
     * @return list<string>
     */
    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }
}
