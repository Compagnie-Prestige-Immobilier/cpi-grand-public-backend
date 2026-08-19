<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Création d'un compte du personnel CPI hors du code source.
 *
 * Remplace les comptes intégrés au seeder (admin@cpi.sn / admin1234), qui
 * étaient recréés à chaque démarrage du conteneur de production avec des
 * identifiants publiés dans le dépôt. Le mot de passe est tiré au hasard et
 * affiché une seule fois : il n'est écrit nulle part ailleurs.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'cpi:create-admin
        {email : Adresse électronique du compte}
        {--name= : Nom affiché (par défaut, déduit de l\'adresse)}
        {--role=super-admin : Rôle à attribuer (super-admin ou agent-cpi)}
        {--reset : Régénérer le mot de passe si le compte existe déjà}';

    protected $description = 'Crée un compte du personnel CPI avec un mot de passe fort généré aléatoirement';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Adresse électronique invalide : {$email}");

            return self::FAILURE;
        }

        if (! in_array($role, ['super-admin', 'agent-cpi'], true)) {
            $this->error("Rôle inconnu : {$role}. Attendu : super-admin ou agent-cpi.");

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null && ! $this->option('reset')) {
            $this->error("Le compte {$email} existe déjà. Utiliser --reset pour régénérer son mot de passe.");

            return self::FAILURE;
        }

        // 24 octets aléatoires : bien au-delà de ce qu'une politique de
        // complexité imposerait, et jamais choisi par un humain.
        $password = Str::password(24, symbols: false);

        $user = $existing ?? new User;
        $user->email = $email;
        $user->name = (string) ($this->option('name') ?: $existing?->name ?: Str::headline(Str::before($email, '@')));
        $user->password = Hash::make($password);
        $user->save();

        if (! $user->hasRole($role)) {
            $user->syncRoles([$role]);
        }

        $this->newLine();
        $this->info($existing !== null
            ? "Mot de passe régénéré pour {$email}."
            : "Compte {$role} créé : {$email}.");
        $this->newLine();
        $this->line('  Mot de passe : '.$password);
        $this->newLine();
        $this->warn('Ce mot de passe ne sera plus jamais affiché. Le transmettre par un canal sûr et le faire changer.');

        return self::SUCCESS;
    }
}
