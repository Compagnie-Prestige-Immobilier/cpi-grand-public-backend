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
}
