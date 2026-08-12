<?php

namespace Tests\Unit;

use App\Services\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class StorageServiceTest extends TestCase
{
    public function test_upload_doc_builds_the_expected_r2_path(): void
    {
        Storage::fake('r2');
        $file = UploadedFile::fake()->create('piece.pdf', 10);

        $path = (new StorageService)->uploadDoc('client-1', 'identite', $file, 2);

        $this->assertSame('docs/client-1/identite_v2.pdf', $path);
        Storage::disk('r2')->assertExists($path);
    }

    public function test_storage_failure_becomes_a_clean_503_without_leaking_internals(): void
    {
        // Disque en panne (identifiants R2 absents, bucket injoignable…).
        Storage::shouldReceive('disk')->with('r2')->andThrow(
            new \RuntimeException('Unable to write file at location: docs/secret/path.pdf. Error retrieving credentials'),
        );

        try {
            (new StorageService)->uploadDoc('client-1', 'identite', UploadedFile::fake()->create('p.pdf', 10), 1);
            $this->fail('Une exception HTTP était attendue.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringContainsString('momentanément indisponible', $e->getMessage());
            $this->assertStringNotContainsString('docs/secret/path.pdf', $e->getMessage());
        }
    }

    public function test_the_stored_extension_comes_from_the_content_not_from_the_declared_name(): void
    {
        Storage::fake('r2');

        // Nom déclaré par le client : « contrat.pdf ». Type réel : PNG.
        // `getClientOriginalExtension()` aurait stocké un `.pdf` mensonger, le
        // chemin R2 ne disant alors rien de ce que le fichier contient.
        $file = UploadedFile::fake()->create('contrat.pdf', 10, 'image/png');

        $path = (new StorageService)->uploadCpiDoc('client-1', 'doc-1', $file);

        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('.pdf', $path);
    }

    public function test_delete_removes_the_file(): void
    {
        Storage::fake('r2');
        $file = UploadedFile::fake()->create('contrat.pdf', 10);

        $service = new StorageService;
        $path = $service->uploadCpiDoc('client-1', 'doc-9', $file);

        $this->assertSame('cpi-docs/client-1/doc-9.pdf', $path);

        $service->delete($path);

        Storage::disk('r2')->assertMissing($path);
    }
}
