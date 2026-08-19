<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Répertoire de sortie des types TypeScript générés
    |--------------------------------------------------------------------------
    |
    | `php artisan typescript:transform` écrit `generated.d.ts` dans ce dossier,
    | qui vit dans le dépôt frontend cloné à côté de celui-ci. Le chemin par
    | défaut suppose donc l'arborescence suivante :
    |
    |   GRANDPUBLIC/
    |     cpi-grand-public-backend/    <- ici
    |     cpi-grand-public-frontend/   <- destination
    |
    | Si le frontend est cloné ailleurs, surcharger TYPESCRIPT_OUTPUT_DIR dans
    | le .env local. La génération est une tâche de développement : elle n'est
    | jamais exécutée dans l'image de production.
    |
    */

    'output_directory' => env(
        'TYPESCRIPT_OUTPUT_DIR',
        base_path('../cpi-grand-public-frontend/src/app/api/types'),
    ),

];
