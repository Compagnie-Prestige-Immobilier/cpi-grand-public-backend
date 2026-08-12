<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use Illuminate\Http\Request;

/**
 * Dossier du client connecté.
 *
 * Cette méthode était recopiée à l'identique dans HUIT contrôleurs. Chaque
 * copie décidait seule du code d'erreur et de son libellé : il suffisait qu'une
 * seule diverge — un 403 au lieu d'un 404, un message différent — pour que
 * l'espace client réponde de deux façons à la même situation selon l'écran.
 */
trait ResoudLeDossierDuClient
{
    /**
     * Le dossier du compte connecté, ou 404.
     *
     * 404 et non 403 : le compte a le droit d'être là, c'est le dossier qui
     * n'existe pas. Le cas ne devrait pas se produire — l'inscription crée le
     * dossier dans la même transaction que le compte — mais un compte du
     * personnel qui atteindrait une route client tomberait ici.
     */
    protected function currentClient(Request $request): Client
    {
        $client = $request->user()?->client;
        abort_if($client === null, 404, 'Aucun dossier client associé à ce compte.');

        return $client;
    }
}
