<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'application est intégralement française — interface, courriels, messages
 * métier — mais `APP_LOCALE` valait `en` : toutes les erreurs de validation
 * remontaient au client en anglais.
 */
class LocalisationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_the_default_locale_is_french(): void
    {
        $this->assertSame('fr', config('app.locale'));
        $this->assertSame('fr', config('app.fallback_locale'));
    }

    public function test_validation_errors_are_returned_in_french(): void
    {
        $reponse = $this->postJson('/api/auth/register', [])
            ->assertStatus(422);

        $messages = implode(' ', array_merge(...array_values((array) $reponse->json('errors'))));

        // Le message exact dépend du paquet de traductions ; ce qui compte est
        // qu'il ne soit plus en anglais.
        $this->assertStringNotContainsString('field is required', $messages);
        $this->assertStringNotContainsString('The name field', $messages);
        $this->assertNotEmpty($messages);
    }
}
