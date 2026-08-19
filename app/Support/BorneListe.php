<?php

namespace App\Support;

/**
 * Plafond de lignes pour les listes non paginées.
 *
 * Plusieurs endpoints du personnel renvoient `->get()` sans aucune borne :
 * documents CPI, médias de chantier, publications, événements, notifications.
 * Sur un portefeuille réel, chacun devient un moyen simple de faire consommer
 * au serveur toute la table et de saturer la mémoire PHP — un déni de service
 * qui ne demande qu'un compte valide.
 *
 * Une pagination changerait la forme de la réponse et casserait le frontend.
 * Le plafond, lui, borne le coût sans toucher au contrat : la clé `data` reste
 * un tableau. La pagination réelle viendra avec la refonte des écrans
 * concernés.
 */
final class BorneListe
{
    /**
     * Plafond par défaut. Volontairement large : il protège contre l'abus, pas
     * contre l'usage. Aucun dossier réel n'approche ces volumes.
     */
    public const MAX = 500;

    /** Plafond des notifications, plus généreux (flux chronologique). */
    public const MAX_NOTIFICATIONS = 1000;
}
