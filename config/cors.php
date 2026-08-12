<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Toujours `true` alors que l'API s'authentifie exclusivement par jeton
    // porteur : le frontend envoie `withCredentials: true` sur toutes ses
    // requêtes, et un navigateur BLOQUE la réponse si l'en-tête
    // Access-Control-Allow-Credentials est absent. À repasser à `false` en même
    // temps que le retrait de `withCredentials` côté frontend, pas avant.
    'supports_credentials' => true,

];
