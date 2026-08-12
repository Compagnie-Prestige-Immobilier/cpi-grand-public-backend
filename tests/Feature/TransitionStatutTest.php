<?php

namespace Tests\Feature;

use App\Enums\BankAssignmentStatut;
use App\Enums\ChantierStatut;
use App\Enums\CpiDocStatut;
use App\Enums\RequisDocStatut;
use PHPUnit\Framework\TestCase;

/**
 * `Rule::in` validait les VALEURS de statut mais jamais les TRANSITIONS : un
 * chantier livré pouvait redevenir « non démarré », un refus bancaire repasser
 * en accord, un brouillon jamais publié être marqué signé. Ces tests figent les
 * chemins légaux.
 */
class TransitionStatutTest extends TestCase
{
    public function test_a_delivered_chantier_is_terminal(): void
    {
        $this->assertSame([], ChantierStatut::Livre->suivants());
        $this->assertFalse(ChantierStatut::Livre->peutAllerVers(ChantierStatut::EnCours));
    }

    public function test_a_chantier_cannot_skip_from_not_started_to_delivered(): void
    {
        $this->assertFalse(ChantierStatut::NonDemarre->peutAllerVers(ChantierStatut::Livre));
        $this->assertTrue(ChantierStatut::NonDemarre->peutAllerVers(ChantierStatut::EnCours));
    }

    public function test_a_bank_decision_is_final(): void
    {
        // Un accord ou un refus est une décision de l'établissement : il ne se
        // révise pas silencieusement côté CPI.
        $this->assertSame([], BankAssignmentStatut::Accord->suivants());
        $this->assertSame([], BankAssignmentStatut::Refus->suivants());
        $this->assertFalse(BankAssignmentStatut::Refus->peutAllerVers(BankAssignmentStatut::Accord));
        $this->assertTrue(BankAssignmentStatut::EnAttente->peutAllerVers(BankAssignmentStatut::Refus));
    }

    public function test_a_draft_document_cannot_be_signed(): void
    {
        // Il n'a jamais été transmis au client : il n'y a rien à signer.
        $this->assertFalse(CpiDocStatut::Brouillon->peutAllerVers(CpiDocStatut::Signe));
        $this->assertTrue(CpiDocStatut::ASigner->peutAllerVers(CpiDocStatut::Signe));
    }

    public function test_a_never_deposited_piece_cannot_be_examined(): void
    {
        $this->assertFalse(RequisDocStatut::EnAttente->peutAllerVers(RequisDocStatut::Verification));
        $this->assertFalse(RequisDocStatut::EnAttente->peutAllerVers(RequisDocStatut::Accepte));
        $this->assertTrue(RequisDocStatut::EnAttente->peutAllerVers(RequisDocStatut::Depose));
    }

    public function test_a_refused_piece_cannot_be_accepted_without_a_new_examination(): void
    {
        $this->assertFalse(RequisDocStatut::Refuse->peutAllerVers(RequisDocStatut::Accepte));
        // L'agent peut en revanche revenir sur son propre refus.
        $this->assertTrue(RequisDocStatut::Refuse->peutAllerVers(RequisDocStatut::Verification));
    }

    public function test_every_status_value_is_unchanged_from_what_the_database_holds(): void
    {
        // Ces enums ne changent AUCUN contrat : les valeurs sont exactement
        // celles déjà en base et déjà lues par le frontend.
        $this->assertSame(
            ['en-attente', 'depose', 'verification', 'accepte', 'refuse', 'a-remplacer'],
            RequisDocStatut::valeurs(),
        );
        $this->assertSame(
            ['brouillon', 'disponible', 'a-signer', 'signe', 'archive'],
            CpiDocStatut::valeurs(),
        );
        $this->assertSame(['en-attente', 'accord', 'refus'], BankAssignmentStatut::valeurs());
        $this->assertSame(
            ['non-demarre', 'en-cours', 'suspendu', 'en-retard', 'termine', 'livre'],
            ChantierStatut::valeurs(),
        );
    }
}
