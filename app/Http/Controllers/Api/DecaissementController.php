<?php

namespace App\Http\Controllers\Api;

use App\Dto\DecaissementData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Decaissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décaissements bancaires d'un dossier — module interne (routes /staff/*).
 *
 * Deux phases :
 *  1. acquisition foncière : décaissement unique du prix du terrain, doublé
 *     d'un parcours foncier en 5 étapes (colonne JSON `foncier`) ;
 *  2. construction : 4 tranches (colonne JSON `tranches`).
 *
 * Les paramètres de route {step} et {num} sont les INDEX du tableau JSON,
 * donc à base 0 — conformément au STEP 10.9 (« foncier[n] », « tranche[n] »).
 */
class DecaissementController extends Controller
{
    /**
     * GET /staff/decaissements/{client} — état de décaissement du dossier.
     * La ligne est créée à la volée si le dossier est antérieur au module.
     */
    public function show(Client $client): JsonResponse
    {
        $decaissement = $client->ensureDecaissement();
        $this->authorize('view', $decaissement);

        return response()->json(['data' => DecaissementData::from($decaissement)]);
    }

    /**
     * PUT /staff/decaissements/{client} — montants, activation de la phase
     * construction, commentaires de tranches.
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        $decaissement = $client->ensureDecaissement();
        $this->authorize('update', $decaissement);

        $validated = $request->validate([
            'terrain_montant' => 'sometimes|numeric|min:0',
            'terrain_decaisse' => 'sometimes|boolean',
            'terrain_date' => 'sometimes|nullable|date',
            'foncier' => 'sometimes|array',
            'foncier.*' => 'boolean',
            'construction_active' => 'sometimes|boolean',
            'construction_montant' => 'sometimes|numeric|min:0',
            'tranches' => 'sometimes|array',
            'tranches.*.validated' => 'required|boolean',
            'tranches.*.date' => 'sometimes|nullable|string|max:100',
            'tranches.*.comment' => 'sometimes|nullable|string|max:2000',
        ]);

        // Qui a préparé le dossier : moitié du contrôle à quatre yeux, la
        // personne qui valide le versement ne pourra pas être celle-ci.
        $decaissement->update($validated + ['prepared_by' => $request->user()?->getKey()]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['champs' => array_keys($validated)])
            ->event('decaissement-modifie')
            ->log("{$request->user()?->name} a mis à jour le décaissement de {$client->name}");

        return response()->json(['data' => DecaissementData::from($decaissement->refresh())]);
    }

    /**
     * Contrôle à quatre yeux : celui qui a préparé le décaissement ne peut pas
     * le valider lui-même.
     *
     * Un versement d'argent réel ne doit jamais dépendre d'une seule personne.
     * Les décaissements antérieurs à cette règle n'ont pas de préparateur connu
     * (`prepared_by` nul) et restent validables : rien ne permet de
     * reconstituer qui les a saisis.
     */
    private function refuserAutoValidation(Request $request, Decaissement $decaissement): void
    {
        abort_if(
            $decaissement->prepared_by !== null
                && $decaissement->prepared_by === $request->user()?->getKey(),
            409,
            'Vous avez préparé ce décaissement : sa validation revient à une autre personne habilitée.',
        );
    }

    /**
     * POST /staff/decaissements/{client}/validate-terrain — décaissement
     * unique du prix du terrain (crédit conso).
     *
     * Les deux premières étapes du parcours foncier (inscription, crédit conso
     * de la banque) sont acquises par construction dès que la banque décaisse :
     * on les marque ici pour que le parcours reste cohérent sans second appel.
     */
    public function validateTerrain(Request $request, Client $client): JsonResponse
    {
        $decaissement = $client->ensureDecaissement();
        $this->authorize('declencherVersement', $decaissement);
        $this->refuserAutoValidation($request, $decaissement);

        $foncier = $this->foncierOf($decaissement);
        $foncier[0] = true;
        if (array_key_exists(1, $foncier)) {
            $foncier[1] = true;
        }

        $decaissement->update([
            'terrain_decaisse' => true,
            'terrain_date' => now(),
            'foncier' => $foncier,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['montant' => (float) $decaissement->terrain_montant])
            ->event('terrain-decaisse')
            ->log("{$request->user()?->name} a décaissé le prix du terrain pour {$client->name}");

        return response()->json(['data' => DecaissementData::from($decaissement->refresh())]);
    }

    /**
     * POST /staff/decaissements/{client}/validate-foncier/{step} — valide une
     * étape du parcours foncier ({step} = index 0-4 du tableau JSON).
     */
    public function validateFoncierStep(Request $request, Client $client, string $step): JsonResponse
    {
        $decaissement = $client->ensureDecaissement();
        $this->authorize('declencherVersement', $decaissement);
        $this->refuserAutoValidation($request, $decaissement);

        $foncier = $this->foncierOf($decaissement);
        $index = $this->indexParam($step, count($foncier), 'Étape foncière inconnue.');

        $foncier[$index] = true;
        $decaissement->update(['foncier' => $foncier]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['etape' => $index])
            ->event('foncier-valide')
            ->log("{$request->user()?->name} a validé l'étape foncière ".($index + 1)." pour {$client->name}");

        return response()->json(['data' => DecaissementData::from($decaissement->refresh())]);
    }

    /**
     * POST /staff/decaissements/{client}/validate-tranche/{num} — décaisse une
     * tranche de construction ({num} = index 0-3 du tableau JSON).
     */
    public function validateTranche(Request $request, Client $client, string $num): JsonResponse
    {
        $decaissement = $client->ensureDecaissement();
        $this->authorize('declencherVersement', $decaissement);
        $this->refuserAutoValidation($request, $decaissement);

        $tranches = $this->tranchesOf($decaissement);
        $index = $this->indexParam($num, count($tranches), 'Tranche de construction inconnue.');

        $tranches[$index] = [
            ...$tranches[$index],
            'validated' => true,
            'date' => now()->toDateString(),
        ];
        $decaissement->update(['tranches' => $tranches]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['tranche' => $index])
            ->event('tranche-decaissee')
            ->log("{$request->user()?->name} a décaissé la tranche ".($index + 1)." pour {$client->name}");

        return response()->json(['data' => DecaissementData::from($decaissement->refresh())]);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Parcours foncier normalisé — une colonne JSON vide ne doit jamais faire
     * tomber une validation d'étape.
     *
     * @return array<int, bool>
     */
    private function foncierOf(Decaissement $decaissement): array
    {
        $foncier = array_values($decaissement->foncier);

        return $foncier === [] ? Decaissement::defaultFoncier() : $foncier;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tranchesOf(Decaissement $decaissement): array
    {
        $tranches = array_values($decaissement->tranches);

        return $tranches === [] ? Decaissement::defaultTranches() : $tranches;
    }

    /**
     * Paramètre de route numérique borné par la taille du tableau JSON.
     */
    private function indexParam(string $raw, int $size, string $message): int
    {
        abort_unless(ctype_digit($raw) && (int) $raw < $size, 404, $message);

        return (int) $raw;
    }
}
