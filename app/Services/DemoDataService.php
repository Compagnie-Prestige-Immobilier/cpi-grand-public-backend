<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\BankAssignment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Jeu de données de DÉMONSTRATION (STEP 14) — chargé et purgé à la demande
 * depuis l'écran « Système » de l'administrateur.
 *
 * Règle unique, et elle est structurante : TOUT ce que ce service crée porte le
 * préfixe « demo- » sur la colonne qui identifie la ligne — `clients.ref`,
 * `users.email`, `banks.name`. Le reste (demande, pièces requises, documents
 * CPI, orientations bancaires, décaissement, chantier et ses tranches /
 * publications / événements, notifications) pend à un client de démo par une
 * clé étrangère en cascade : supprimer les 30 dossiers suffit à tout emporter,
 * sans jamais qu'une requête ait à deviner ce qui est fictif.
 *
 * Ce qui NE tombe pas en cascade est traité explicitement par clear() :
 *  - les comptes de connexion (`clients.user_id` est nullOnDelete) ;
 *  - le journal d'activité (table Spatie, sans clé étrangère).
 *
 * Aucun fichier n'est déposé : le jeu de démo ne crée AUCUN objet R2 (pas de
 * pièce téléversée, pas de média de chantier). Rien à nettoyer côté stockage.
 */
class DemoDataService
{
    /** Préfixe porté par toute ligne de démonstration identifiable. */
    public const PREFIX = 'demo-';

    /** Nombre de dossiers fictifs créés par un chargement. */
    public const COUNT = 30;

    /** Domaine réservé aux adresses des comptes de démonstration. */
    public const EMAIL_DOMAIN = 'cpi-demo.sn';

    /**
     * Mot de passe des comptes de démonstration — volontairement trivial et
     * documenté : ces comptes n'existent que pour visiter la plateforme. Il est
     * renvoyé par l'API à l'administrateur qui déclenche le chargement ; aucun
     * mot de passe de vrai compte n'est jamais lisible nulle part.
     */
    public const PASSWORD = 'demo1234';

    /** @var list<string> */
    private const PRENOMS = [
        'Awa', 'Moussa', 'Fatou', 'Ibrahima', 'Aminata', 'Cheikh', 'Mariama', 'Ousmane',
        'Khady', 'Modou', 'Aissatou', 'Babacar', 'Ndeye', 'Alioune', 'Rokhaya', 'Serigne',
        'Sokhna', 'Pape', 'Adama', 'Bineta', 'Mamadou', 'Coumba', 'Idrissa', 'Yacine',
        'Abdoulaye', 'Astou', 'Lamine', 'Dieynaba', 'Malick', 'Seynabou',
    ];

    /** @var list<string> */
    private const NOMS = [
        'Diop', 'Ndiaye', 'Fall', 'Sarr', 'Sow', 'Ba', 'Gueye', 'Diallo', 'Sy', 'Faye',
        'Cisse', 'Mbaye', 'Kane', 'Thiam', 'Niang', 'Sene', 'Diagne', 'Camara', 'Dieng', 'Toure',
    ];

    /** @var list<string> */
    private const COMMUNES = [
        'Rufisque', 'Guédiawaye', 'Pikine', 'Parcelles Assainies', 'Yoff',
        'Ngor', 'Thiès', 'Mbour', 'Saint-Louis', 'Touba',
    ];

    /** @var list<string> */
    private const PROJETS = [
        'Villa R+1', 'Appartement F4', 'Terrain viabilisé', 'Duplex', 'Maison basse', 'Villa R+2',
    ];

    /**
     * Banques partenaires fictives. Le préfixe est visible dans l'interface —
     * c'est voulu : un opérateur doit reconnaître une donnée de démo d'un coup
     * d'œil, et clear() s'appuie sur ce même préfixe.
     *
     * @var list<array{name: string, rate: string, color: string, products: list<string>}>
     */
    private const BANQUES = [
        ['name' => self::PREFIX.'CBAO', 'rate' => '6,5 %', 'color' => '#1E4D8C',
            'products' => ['Crédit acquisition', 'Crédit construction']],
        ['name' => self::PREFIX.'BOA Sénégal', 'rate' => '6,9 %', 'color' => '#1A6B44',
            'products' => ['Crédit acquisition']],
        ['name' => self::PREFIX.'Ecobank', 'rate' => '7,2 %', 'color' => '#C8921A',
            'products' => ['Crédit construction', 'Crédit terrain']],
    ];

    /**
     * Répartition des 30 dossiers sur le parcours, dans l'ordre de création.
     * Chaque entrée vaut pour `count` dossiers consécutifs. Les libellés de
     * `statut` sont ceux qu'affiche l'interface (texte libre côté API).
     *
     * `etape` alimente `clients.dossier_etape` ; l'étape RÉELLE du parcours
     * reste calculée par ClientController::computeJourneyStep — d'où des pièces
     * cohérentes avec l'étape annoncée.
     *
     * @var list<array{profil: string, count: int, statut: string, progression: int, etape: int, docs: string}>
     */
    private const REPARTITION = [
        ['profil' => 'inscription', 'count' => 5, 'statut' => 'Dossier en préparation',
            'progression' => 5, 'etape' => 0, 'docs' => 'en-attente'],
        ['profil' => 'documents', 'count' => 9, 'statut' => 'Pièces à vérifier',
            'progression' => 25, 'etape' => 1, 'docs' => 'depose'],
        ['profil' => 'analyse', 'count' => 8, 'statut' => 'En analyse banque',
            'progression' => 45, 'etape' => 3, 'docs' => 'accepte'],
        ['profil' => 'signature', 'count' => 4, 'statut' => 'Signature du contrat',
            'progression' => 65, 'etape' => 5, 'docs' => 'accepte'],
        ['profil' => 'construction', 'count' => 3, 'statut' => 'En construction',
            'progression' => 85, 'etape' => 5, 'docs' => 'accepte'],
        ['profil' => 'livre', 'count' => 1, 'statut' => 'Logement livré',
            'progression' => 100, 'etape' => 5, 'docs' => 'accepte'],
    ];

    // ─── Lecture ──────────────────────────────────────────────

    /** Nombre de dossiers de démonstration actuellement en base. */
    public function count(): int
    {
        return $this->demoClients()->count();
    }

    // ─── Chargement ───────────────────────────────────────────

    /**
     * Crée les 30 dossiers de démonstration et tout ce qui y pend.
     *
     * Appelée à travers une transaction par le contrôleur, qui refuse déjà de
     * rejouer un chargement tant que des données de démo subsistent : ce service
     * n'a donc jamais à composer avec un état partiel.
     *
     * @return int nombre de dossiers créés
     */
    public function seed(): int
    {
        $banques = $this->creerBanques();
        $index = 0;

        foreach (self::REPARTITION as $groupe) {
            for ($n = 0; $n < $groupe['count']; $n++, $index++) {
                $this->creerDossier($index, $groupe, $banques);
            }
        }

        return $index;
    }

    // ─── Purge ────────────────────────────────────────────────

    /**
     * Supprime toute la démonstration — et rien d'autre.
     *
     * L'ordre compte : on relève d'abord les identifiants (dossiers, comptes)
     * car les lignes disparaissent ensuite en cascade. Le journal d'activité est
     * purgé sur ces deux mêmes ensembles : une entrée dont le SUJET est un
     * dossier de démo, ou dont l'AUTEUR est un compte de démo.
     *
     * @return array{clients: int, users: int, banks: int, activites: int}
     */
    public function clear(): array
    {
        /** @var list<string> $clientIds */
        $clientIds = $this->demoClients()->pluck('id')->all();

        /** @var list<string> $userIds */
        $userIds = $this->demoClients()->whereNotNull('user_id')->pluck('user_id')->all();

        // Comptes de démo orphelins — leur dossier a été supprimé à la main, le
        // chaînage par `clients.user_id` ne les atteint donc plus. On exige les
        // DEUX marqueurs (préfixe « demo- » ET domaine réservé de la démo) :
        // avec le seul préfixe, un vrai client qui aurait choisi une adresse
        // commençant par « demo- » serait emporté par la purge.
        /** @var list<string> $orphelins */
        $orphelins = User::query()
            ->where('email', 'like', self::PREFIX.'%')
            ->where('email', 'like', '%@'.self::EMAIL_DOMAIN)
            ->pluck('id')->all();
        $userIds = array_values(array_unique([...$userIds, ...$orphelins]));

        $activites = 0;
        if ($clientIds !== []) {
            $activites += Activity::query()
                ->where('subject_type', Client::class)
                ->whereIn('subject_id', $clientIds)
                ->delete();
        }
        if ($userIds !== []) {
            $activites += Activity::query()
                ->where('causer_type', User::class)
                ->whereIn('causer_id', $userIds)
                ->delete();
        }

        // Cascade : demande, pièces requises, documents CPI, orientations
        // bancaires, décaissement, chantier (+ tranches, publications, médias,
        // événements) et notifications partent avec le dossier.
        $clients = $clientIds === [] ? 0 : Client::query()->whereIn('id', $clientIds)->delete();

        $users = 0;
        if ($userIds !== []) {
            /** @var Collection<int, User> $comptes */
            $comptes = User::query()->whereIn('id', $userIds)->get();
            foreach ($comptes as $compte) {
                $compte->tokens()->delete();
                $compte->delete();
                $users++;
            }
        }

        // Les banques fictives ne pendent à aucun dossier : elles se reconnaissent
        // à leur nom préfixé. Leurs orientations tombent en cascade.
        $banks = Bank::query()->where('name', 'like', self::PREFIX.'%')->delete();

        return [
            'clients' => $clients,
            'users' => $users,
            'banks' => $banks,
            'activites' => $activites,
        ];
    }

    // ─── Fabrique ─────────────────────────────────────────────

    /**
     * @return Builder<Client>
     */
    private function demoClients(): Builder
    {
        return Client::query()->where('ref', 'like', self::PREFIX.'%');
    }

    /**
     * @return list<Bank>
     */
    private function creerBanques(): array
    {
        $banques = [];
        foreach (self::BANQUES as $banque) {
            $banques[] = Bank::create([
                'name' => $banque['name'],
                'convention_date' => now()->subMonths(8)->format('d/m/Y'),
                'validity' => now()->addYears(2)->format('d/m/Y'),
                'products' => $banque['products'],
                'rate' => $banque['rate'],
                'contact' => 'partenariats@'.Str::slug($banque['name']).'.sn',
                'color' => $banque['color'],
            ])->refresh();
        }

        return $banques;
    }

    /**
     * Un dossier complet : compte de connexion, dossier, demande, pièces,
     * document CPI, orientation bancaire, décaissement, chantier, notification.
     *
     * @param  array{profil: string, count: int, statut: string, progression: int, etape: int, docs: string}  $groupe
     * @param  list<Bank>  $banques
     */
    private function creerDossier(int $i, array $groupe, array $banques): void
    {
        $prenom = self::PRENOMS[$i % count(self::PRENOMS)];
        $nom = self::NOMS[($i * 7) % count(self::NOMS)];
        $commune = self::COMMUNES[$i % count(self::COMMUNES)];
        // `clients.project_nom` ne porte QUE la nature du bien : l'espace client
        // affiche « {projet} — {adresse} » (MonDossierPage), et répéter la
        // commune donnerait « Duplex — Mbour — Mbour ». Le chantier, lui, est
        // désigné par son intitulé complet.
        $projet = self::PROJETS[$i % count(self::PROJETS)];
        $projetChantier = $projet.' — '.$commune;
        $numero = sprintf('%02d', $i + 1);
        $email = self::PREFIX.Str::slug($prenom.' '.$nom, '.').$numero.'@'.self::EMAIL_DOMAIN;
        $montant = (10 + ($i % 20)) * 1_000_000;
        $inscription = now()->subDays(180 - ($i * 5));

        $user = User::create([
            'name' => $prenom.' '.$nom,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'phone' => sprintf('77 %03d %02d %02d', 100 + $i, 20 + ($i % 70), 30 + ($i % 60)),
            'employer' => $i % 3 === 0 ? 'Fonction publique' : 'Secteur privé',
            'profile_type' => $i % 3 === 0 ? 'fonctionnaire' : 'prive',
        ]);
        $user->assignRole('client');

        // Client::booted() provisionne les 3 pièces requises, le décaissement et
        // le chantier : on ne recrée rien, on part de ce qui existe déjà.
        $client = Client::create([
            'user_id' => $user->id,
            'name' => $prenom.' '.$nom,
            'ref' => self::PREFIX.'CPI-'.$inscription->year.'-'.sprintf('%04d', $i + 1),
            'statut' => $groupe['statut'],
            'progression' => $groupe['progression'],
            'project_nom' => $projet,
            'adresse' => $commune,
            'email' => $email,
            'phone' => $user->phone,
            'employer' => $user->employer,
            'fonction' => $i % 3 === 0 ? 'Agent administratif' : 'Chargé de clientèle',
            'conseiller' => 'Agent CPI',
            'dossier_etape' => $groupe['etape'],
            'date_inscription' => $inscription,
        ])->refresh();

        $this->creerDemande($client, $groupe, $commune, $montant, $i, $inscription);
        $this->preparerPieces($client, $groupe, $i, $inscription);
        $this->creerDocumentsCpi($client, $groupe['profil'], $numero, $inscription);
        $this->orienterVersBanque($client, $groupe['profil'], $banques, $i);
        $this->preparerDecaissement($client, $groupe['profil'], $montant);
        $this->preparerChantier($client, $groupe['profil'], $projetChantier, $commune, $numero, $i);
        $this->creerNotifications($client, $user, $groupe['profil'], $inscription);
    }

    /**
     * @param  array{profil: string, count: int, statut: string, progression: int, etape: int, docs: string}  $groupe
     */
    private function creerDemande(
        Client $client,
        array $groupe,
        string $commune,
        int $montant,
        int $i,
        Carbon $inscription,
    ): void {
        $soumise = $groupe['profil'] !== 'inscription';

        $client->demande()->create([
            'submitted' => $soumise,
            'submitted_at' => $soumise ? $inscription->copy()->addDays(2) : null,
            'type_projet' => 'financement',
            'nature_projet' => $i % 2 === 0 ? 'acquisition' : 'construction',
            'montant' => $montant,
            'duree' => (string) (10 + (($i % 3) * 5)),
            'apport' => (int) round($montant * 0.1),
            'region' => 'Dakar',
            'commune' => $commune,
            'adresse_projet' => 'Lot '.($i + 1).', '.$commune,
            'description' => 'Dossier de démonstration n°'.($i + 1).' — données fictives.',
        ]);
    }

    /**
     * Les trois pièces existent déjà (Client::booted) : on les fait seulement
     * avancer. Un dossier « à vérifier » sur trois voit sa pièce bancaire
     * refusée — l'écran de traitement doit aussi montrer ce cas.
     *
     * @param  array{profil: string, count: int, statut: string, progression: int, etape: int, docs: string}  $groupe
     */
    private function preparerPieces(
        Client $client,
        array $groupe,
        int $i,
        Carbon $inscription,
    ): void {
        if ($groupe['docs'] === 'en-attente') {
            return;
        }

        $depot = $inscription->copy()->addDays(3);
        $accepte = $groupe['docs'] === 'accepte';

        $client->requisDocs()->update([
            'status' => $groupe['docs'],
            'version' => 1,
            'submitted_label' => 'piece-justificative.pdf',
            'date' => $depot->format('d/m/Y'),
            'taille' => '480 Ko',
            'agent_name' => $accepte ? 'Agent CPI' : null,
            'date_validation' => $accepte ? $depot->copy()->addDay() : null,
        ]);

        if ($groupe['profil'] === 'documents' && $i % 3 === 0) {
            $client->requisDocs()->where('doc_id', 'bancaires')->update([
                'status' => 'refuse',
                'commentaire' => 'Relevés illisibles — merci de redéposer les trois derniers mois.',
                'agent_name' => 'Agent CPI',
                'date_validation' => $depot->copy()->addDay(),
            ]);
        }
    }

    /**
     * Documents CPI : un contrat par dossier engagé, à l'état correspondant à
     * l'avancement (à signer, puis signé).
     */
    private function creerDocumentsCpi(
        Client $client,
        string $profil,
        string $numero,
        Carbon $inscription,
    ): void {
        $etats = [
            'analyse' => ['status' => 'disponible', 'signature' => false],
            'signature' => ['status' => 'a-signer', 'signature' => true],
            'construction' => ['status' => 'signe', 'signature' => false],
            'livre' => ['status' => 'signe', 'signature' => false],
        ];

        if (! array_key_exists($profil, $etats)) {
            return;
        }

        $client->cpiDocs()->create([
            'categorie' => 'contrats',
            'nom' => 'Contrat de réservation',
            'reference' => self::PREFIX.'CR-'.$numero,
            'date_creation' => $inscription->copy()->addDays(10),
            'date_publication' => $inscription->copy()->addDays(11),
            'version' => '1.0',
            'status' => $etats[$profil]['status'],
            'auteur' => 'Agent CPI',
            'visible_client' => true,
            'signature_requise' => $etats[$profil]['signature'],
            'taille' => '240 Ko',
            'format' => 'PDF',
        ]);

        if ($profil === 'livre') {
            $client->cpiDocs()->create([
                'categorie' => 'pv',
                'nom' => 'Procès-verbal de réception',
                'reference' => self::PREFIX.'PV-'.$numero,
                'date_creation' => now()->subDays(5),
                'date_publication' => now()->subDays(4),
                'version' => '1.0',
                'status' => 'signe',
                'auteur' => 'Agent CPI',
                'visible_client' => true,
                'signature_requise' => false,
                'taille' => '180 Ko',
                'format' => 'PDF',
            ]);
        }
    }

    /**
     * @param  list<Bank>  $banques
     */
    private function orienterVersBanque(Client $client, string $profil, array $banques, int $i): void
    {
        $statuts = [
            'analyse' => 'en-attente',
            'signature' => 'accord',
            'construction' => 'accord',
            'livre' => 'accord',
        ];

        if ($banques === [] || ! array_key_exists($profil, $statuts)) {
            return;
        }

        $banque = $banques[$i % count($banques)];

        BankAssignment::create([
            'client_id' => $client->id,
            'bank_id' => $banque->id,
            'status' => $statuts[$profil],
        ]);

        $client->update(['banque' => $banque->name]);
    }

    /**
     * Le décaissement existe déjà (Client::booted) : on le fait avancer au
     * rythme du profil — terrain, parcours foncier, puis tranches de
     * construction.
     */
    private function preparerDecaissement(Client $client, string $profil, int $montant): void
    {
        $decaissement = $client->ensureDecaissement();
        $terrain = (int) round($montant * 0.4);
        $construction = $montant - $terrain;

        $etats = [
            'analyse' => [
                'foncier' => [true, true, false, false, false],
                'terrain' => false, 'construction' => false, 'tranches' => 0,
            ],
            'signature' => [
                'foncier' => [true, true, true, true, false],
                'terrain' => true, 'construction' => false, 'tranches' => 0,
            ],
            'construction' => [
                'foncier' => [true, true, true, true, true],
                'terrain' => true, 'construction' => true, 'tranches' => 2,
            ],
            'livre' => [
                'foncier' => [true, true, true, true, true],
                'terrain' => true, 'construction' => true, 'tranches' => 4,
            ],
        ];

        if (! array_key_exists($profil, $etats)) {
            return;
        }

        $etat = $etats[$profil];
        $tranches = [];
        foreach (range(0, 3) as $num) {
            $tranches[] = $num < $etat['tranches']
                ? ['validated' => true, 'date' => now()->subDays(60 - ($num * 15))->toDateString()]
                : ['validated' => false];
        }

        $decaissement->update([
            'terrain_montant' => $terrain,
            'terrain_decaisse' => $etat['terrain'],
            'terrain_date' => $etat['terrain'] ? now()->subDays(90) : null,
            'foncier' => $etat['foncier'],
            'construction_active' => $etat['construction'],
            'construction_montant' => $etat['construction'] ? $construction : 0,
            'tranches' => $tranches,
        ]);
    }

    /**
     * Le chantier et ses quatre tranches existent déjà (Client::booted) : seuls
     * les dossiers en construction ou livrés le font vivre — avec un fil de
     * publications et un calendrier, pour que l'espace client ait quelque chose
     * à montrer. Aucun média : cela supposerait un fichier sur R2.
     */
    private function preparerChantier(
        Client $client,
        string $profil,
        string $projet,
        string $commune,
        string $numero,
        int $i,
    ): void {
        if ($profil !== 'construction' && $profil !== 'livre') {
            return;
        }

        $chantier = $client->ensureChantier();
        $livre = $profil === 'livre';
        $debut = now()->subMonths($livre ? 14 : 6);

        $chantier->update([
            'projet' => $projet,
            'reference' => self::PREFIX.'CH-'.$numero,
            'localisation' => $commune,
            'chef_chantier' => 'Ousmane Ndour',
            'entreprise' => self::PREFIX.'Entreprise Sénégal Bâtiment',
            'date_debut' => $debut->toDateString(),
            'date_livraison' => $debut->copy()->addMonths(12)->toDateString(),
            'progression' => $livre ? 100 : 40 + (($i % 3) * 10),
            'etape_actuelle' => $livre ? 'Logement livré' : 'Élévation des murs',
            'statut' => $livre ? 'livre' : 'en-cours',
            'derniere_maj' => now()->format('d/m/Y'),
        ]);

        $terminees = $livre ? 4 : 2;
        foreach ($chantier->tranches()->get() as $tranche) {
            $etat = $tranche->num <= $terminees
                ? 'terminee'
                : ($tranche->num === $terminees + 1 ? 'en-cours' : 'en-attente');

            $tranche->update([
                'etat' => $etat,
                'date' => $etat === 'terminee'
                    ? $debut->copy()->addMonths($tranche->num * 2)->toDateString()
                    : null,
                'comment' => $etat === 'terminee' ? 'Tranche réceptionnée et décaissée.' : null,
            ]);
        }

        $chantier->publications()->create([
            'phase' => 2,
            'titre' => $livre ? 'Remise des clés' : 'Coulage de la dalle terminé',
            'description' => $livre
                ? 'Le logement a été remis à son propriétaire après réception définitive.'
                : "La dalle du rez-de-chaussée a été coulée. Séchage en cours avant l'élévation des murs.",
            'date' => now()->subDays(12),
            'heure' => '10:30',
            'auteur' => 'Agent CPI',
            'type' => $livre ? 'etape-validee' : 'actualite',
            'visible_client' => true,
        ]);

        $chantier->events()->create([
            'titre' => $livre ? 'Réception définitive' : 'Visite de chantier mensuelle',
            'type' => $livre ? 'reception' : 'visite',
            'date' => ($livre ? now()->subDays(6) : now()->addDays(10))->toDateString(),
            'heure' => '09:00',
            'description' => $livre
                ? 'Réception définitive du logement en présence du client.'
                : "Point d'avancement avec le client et le chef de chantier.",
            'statut' => $livre ? 'realise' : 'prevu',
            'visible_client' => true,
        ]);
    }

    /**
     * Une notification par dossier engagé, adressée au dossier ET à son compte
     * (c'est l'union que lit /client/notifications).
     */
    private function creerNotifications(
        Client $client,
        User $user,
        string $profil,
        Carbon $inscription,
    ): void {
        $messages = [
            'documents' => ['Pièces reçues', 'Vos pièces justificatives sont en cours de vérification par votre conseiller.', 'info', 'mon-dossier'],
            'analyse' => ['Dossier transmis à la banque', 'Votre dossier a été orienté vers une banque partenaire pour analyse.', 'validation', 'mon-dossier'],
            'signature' => ['Contrat à signer', 'Votre contrat de réservation est disponible : merci de le signer en ligne.', 'action', 'mon-dossier'],
            'construction' => ['Chantier en cours', 'Une nouvelle publication est disponible sur le fil de votre chantier.', 'info', 'mon-chantier'],
            'livre' => ['Logement livré', 'Votre logement a été réceptionné. Félicitations !', 'validation', 'mon-chantier'],
        ];

        if (! array_key_exists($profil, $messages)) {
            return;
        }

        [$titre, $message, $type, $page] = $messages[$profil];

        $client->notifications()->create([
            'user_id' => $user->id,
            'titre' => $titre,
            'message' => $message,
            'date' => $inscription->copy()->addDays(15),
            'heure' => '09:15',
            'lu' => $profil === 'livre',
            'type' => $type,
            'target_page' => $page,
        ]);
    }
}
