<?php

namespace Tests;

use DateTimeInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aucun test ne doit toucher le vrai bucket R2. Le driver local ne sait
        // pas signer d'URL : on lui en fait produire une factice pour que les
        // DTO puissent exposer `fileUrl` comme en production.
        Storage::fake('r2')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiration): string => "https://r2.test/{$path}?expires={$expiration->getTimestamp()}",
        );
    }

    /**
     * Le garde d'authentification mémorise l'utilisateur qu'il a résolu, et
     * l'application de test survit d'une requête à l'autre au sein d'un même
     * test. Sans cette purge, un second appel fait avec le jeton de quelqu'un
     * d'autre reste vu comme le PREMIER utilisateur : la requête part avec le
     * bon en-tête Authorization, mais les permissions évaluées sont celles du
     * précédent.
     *
     * Symptôme observé : un test qui fait préparer un décaissement par un agent
     * puis valider par un administrateur recevait 403, la policy voyant encore
     * l'agent. En production le problème n'existe pas — chaque requête HTTP
     * part d'une application neuve.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }
}
