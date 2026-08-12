<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxys de confiance
    |--------------------------------------------------------------------------
    |
    | Le conteneur applicatif n'est jamais exposé directement : le proxy TLS de
    | l'hôte termine HTTPS et transmet en clair sur la boucle locale. Sans
    | déclaration, Laravel prend l'adresse du proxy pour celle du client — tous
    | les limiteurs de débit partageraient alors le même seau — et considère la
    | requête comme non chiffrée.
    |
    | `*` fait confiance à tout proxy : acceptable uniquement parce que le
    | conteneur n'écoute que sur la boucle locale de l'hôte. Sur une topologie
    | où il serait joignable autrement, lister les adresses explicitement.
    |
    | Lu ici plutôt que par `env()` dans bootstrap/app.php : `php artisan
    | optimize` met la configuration en cache et `env()` ne répond alors plus.
    |
    */

    'trusted' => env('TRUSTED_PROXIES', ''),

];
