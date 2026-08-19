<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResoudLeDossierDuClient;
use App\Http\Controllers\Controller;
use App\Mail\SupportMessage;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Formulaire « Support » de l'espace client.
 *
 * Le message part par courriel vers la boîte du support (config
 * `mail.support_address`) plutôt que dans une table de tickets : l'équipe relève
 * déjà cette boîte, alors qu'une table sans écran d'administration pour la lire
 * n'aurait été qu'un trou noir de plus.
 *
 * Ce formulaire n'envoyait RIEN auparavant — il affichait « Ticket envoyé ! »
 * puis se fermait. Les demandes des clients étaient perdues en silence.
 */
class SupportController extends Controller
{
    use ResoudLeDossierDuClient;

    /**
     * POST /client/support — transmet la demande d'un client au support CPI.
     */
    public function send(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $user = $request->user();

        $validated = $request->validate([
            'sujet' => 'required|string|max:150',
            'message' => 'required|string|max:5000',
        ]);

        // Une panne d'envoi ne doit pas se traduire par une 500 muette : le
        // client saurait seulement que « ça n'a pas marché ». On journalise le
        // détail côté serveur et on lui indique les canaux directs.
        try {
            Mail::to(config('mail.support_address'))
                ->send(new SupportMessage($client, $user->email, $validated['sujet'], $validated['message']));
        } catch (\Throwable $e) {
            Log::error('Envoi de la demande de support impossible', [
                'client' => $client->ref,
                'sujet' => $validated['sujet'],
                'erreur' => $e->getMessage(),
            ]);

            abort(503, "L'envoi est momentanément indisponible. Écrivez-nous directement à ".config('mail.support_address').'.');
        }

        activity()
            ->causedBy($user)
            ->performedOn($client)
            ->withProperties(['sujet' => $validated['sujet']])
            ->event('support-demande')
            ->log("{$client->name} a contacté le support : {$validated['sujet']}");

        return response()->json(['data' => [
            'message' => 'Votre demande a bien été transmise au support CPI.',
        ]]);
    }
}
