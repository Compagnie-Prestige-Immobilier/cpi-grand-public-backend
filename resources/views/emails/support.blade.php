Demande envoyée depuis l'espace client MONESPACE.CPI.

Client    : {{ $client->name }}
Dossier   : {{ $client->ref }}
E-mail    : {{ $emailClient }}
Téléphone : {{ $client->phone ?: 'non renseigné' }}

Sujet : {{ $sujet }}

{{ $texte }}

--
Répondre à ce message écrit directement au client.
