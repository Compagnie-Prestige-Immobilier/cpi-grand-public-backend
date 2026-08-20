<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Notification;

/**
 * Notifications adressées au client sur les événements de son dossier.
 *
 * Constat de l'audit : 49 événements sont journalisés, et TROIS d'entre eux
 * seulement produisaient une notification. Le client n'était pas prévenu quand
 * un contrat était mis à sa signature, quand la banque rendait sa réponse,
 * quand de l'argent était versé, ni quand son dossier changeait d'étape. Il
 * devait ouvrir l'application et deviner ce qui avait bougé.
 *
 * Le journal d'activité répond à « qui a fait quoi » ; la notification répond à
 * « qu'est-ce que le client doit savoir ». Les deux ne se remplacent pas.
 *
 * Un dossier peut ne pas avoir de compte associé (créé par le personnel avant
 * l'inscription) : l'envoi est alors sans objet et silencieusement ignoré.
 */
class NotifieLeClient
{
    public function envoyer(
        Client $client,
        string $titre,
        string $message,
        string $type = 'info',
        ?string $page = null,
    ): ?Notification {
        if ($client->user_id === null) {
            return null;
        }

        return Notification::create([
            'client_id' => $client->id,
            'user_id' => $client->user_id,
            'titre' => $titre,
            'message' => $message,
            'type' => $type,
            'target_page' => $page,
            'date' => now(),
            'heure' => now()->format('H:i'),
            'lu' => false,
        ]);
    }

    /** Un document contractuel attend la signature du client. */
    public function documentASigner(Client $client, string $nomDocument): ?Notification
    {
        return $this->envoyer(
            $client,
            'Document à signer',
            "« {$nomDocument} » attend votre signature électronique.",
            'action',
            'mon-dossier',
        );
    }

    /** Un document est mis à disposition, sans signature attendue. */
    public function documentDisponible(Client $client, string $nomDocument): ?Notification
    {
        return $this->envoyer(
            $client,
            'Nouveau document',
            "« {$nomDocument} » est disponible dans votre dossier.",
            'info',
            'mon-dossier',
        );
    }

    /** Réponse d'une banque à laquelle le dossier avait été orienté. */
    public function reponseBancaire(Client $client, string $banque, bool $accord): ?Notification
    {
        return $accord
            ? $this->envoyer(
                $client,
                'Accord bancaire',
                "{$banque} a donné son accord de financement pour votre dossier.",
                'validation',
                'mes-banques',
            )
            : $this->envoyer(
                $client,
                'Réponse de la banque',
                "{$banque} n'a pas retenu votre dossier. Votre conseiller CPI revient vers vous.",
                'alerte',
                'mes-banques',
            );
    }

    /** Un versement a été déclenché. */
    public function versement(Client $client, string $objet): ?Notification
    {
        return $this->envoyer(
            $client,
            'Versement effectué',
            "{$objet} : le versement a été déclenché par CPI.",
            'validation',
            'mon-chantier',
        );
    }

    /**
     * Un conseiller vient d'être attribué au dossier — à la validation du
     * compte (attribution automatique) ou à une réattribution manuelle.
     */
    public function conseillerAttribue(Client $client, string $nomConseiller): ?Notification
    {
        return $this->envoyer(
            $client,
            'Conseiller attribué',
            "Votre conseiller CPI est désormais {$nomConseiller}. Vous pouvez accéder à votre espace et déposer vos pièces.",
            'validation',
            'ma-demande',
        );
    }

    /** Le dossier a changé d'étape dans le parcours. */
    public function etapeDossier(Client $client, int $etape): ?Notification
    {
        $libelles = [
            0 => 'Inscription',
            1 => 'Demande de financement',
            2 => 'Pièces justificatives',
            3 => 'Analyse du dossier',
            4 => 'Accord bancaire',
            5 => 'Signature',
        ];

        return $this->envoyer(
            $client,
            'Votre dossier a avancé',
            'Votre dossier est passé à l\'étape « '.($libelles[$etape] ?? "Étape {$etape}").' ».',
            'info',
            'mon-dossier',
        );
    }
}
