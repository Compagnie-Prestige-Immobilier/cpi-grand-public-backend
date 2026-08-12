<?php

namespace App\Enums;

/**
 * Contrat commun aux statuts métier de la plateforme.
 *
 * Permet à `App\Support\TransitionStatut` de faire respecter les transitions
 * sans connaître l'enum concret.
 */
interface Statut
{
    /** Libellé affichable. */
    public function libelle(): string;

    /**
     * États atteignables depuis celui-ci.
     *
     * @return list<static>
     */
    public function suivants(): array;

    public function peutAllerVers(self $cible): bool;
}
