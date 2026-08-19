<?php

namespace Database\Factories;

use App\Enums\StatutCompte;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'needs_onboarding' => false,
            // Compte utilisable par défaut : la validation administrative est
            // un scénario à tester explicitement, pas une condition à
            // satisfaire dans chacun des tests des autres fonctionnalités.
            'statut_compte' => StatutCompte::Valide,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Compte fraîchement inscrit : adresse non vérifiée, aucun accès. */
    public function emailAVerifier(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => null,
            'statut_compte' => StatutCompte::EmailAVerifier,
        ]);
    }

    /** Adresse vérifiée, en attente du feu vert d'un administrateur. */
    public function enAttenteValidation(): static
    {
        return $this->state(fn (): array => [
            'statut_compte' => StatutCompte::EnAttenteValidation,
        ]);
    }

    /** Compte refusé, avec le motif communiqué à la personne. */
    public function rejete(string $motif = 'Informations incomplètes.'): static
    {
        return $this->state(fn (): array => [
            'statut_compte' => StatutCompte::Rejete,
            'motif_rejet' => $motif,
        ]);
    }
}
