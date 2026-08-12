<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Limites de débit de l'API
    |--------------------------------------------------------------------------
    |
    | Les limiteurs correspondants sont enregistrés dans AppServiceProvider.
    | Valeurs en requêtes par minute, pilotables par variable d'environnement
    | pour pouvoir être desserrées en production sans redéploiement de code.
    |
    | `api` s'applique à toutes les routes du groupe api (bootstrap/app.php).
    | Les autres sont posés explicitement sur les routes sensibles, en plus.
    |
    */

    // Limite générale : par utilisateur authentifié, sinon par IP.
    'api' => (int) env('RATE_LIMIT_API', 120),

    // Bourrage d'identifiants : par couple IP + email, pour qu'un attaquant ne
    // puisse pas balayer les comptes depuis une seule adresse, et qu'un client
    // derrière une IP partagée ne soit pas bloqué par le voisin.
    'login' => (int) env('RATE_LIMIT_LOGIN', 5),

    // Création de comptes en masse : par IP.
    'register' => (int) env('RATE_LIMIT_REGISTER', 5),

    // Le formulaire de support envoie de vrais courriels depuis le domaine CPI :
    // un compte compromis pourrait inonder la boîte et faire blacklister le
    // domaine. Limite par utilisateur.
    'support' => (int) env('RATE_LIMIT_SUPPORT', 3),

];
