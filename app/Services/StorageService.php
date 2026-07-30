<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Toutes les interactions avec Cloudflare R2 passent par ce service.
 *
 * Le bucket est PRIVÉ : les fichiers sont toujours servis via des URLs
 * signées de courte durée (temporaryUrl), jamais via des liens publics.
 */
class StorageService
{
    /**
     * Documents requis d'un client : docs/{clientId}/{docId}_v{version}.{ext}
     */
    public function uploadDoc(string $clientId, string $docId, UploadedFile $file, int $version): string
    {
        return $this->put("docs/{$clientId}/{$docId}_v{$version}.".$file->getClientOriginalExtension(), $file);
    }

    /**
     * Documents CPI : cpi-docs/{clientId}/{docId}.{ext}
     */
    public function uploadCpiDoc(string $clientId, string $docId, UploadedFile $file): string
    {
        return $this->put("cpi-docs/{$clientId}/{$docId}.".$file->getClientOriginalExtension(), $file);
    }

    /**
     * Photos/vidéos de chantier : chantier/{clientId}/{mediaId}.{ext}
     */
    public function uploadChantierMedia(string $clientId, string $mediaId, UploadedFile $file): string
    {
        return $this->put("chantier/{$clientId}/{$mediaId}.".$file->getClientOriginalExtension(), $file);
    }

    /**
     * URL signée de courte durée — la SEULE façon de servir les fichiers.
     */
    public function temporaryUrl(string $path, int $minutes = 15): string
    {
        return Storage::disk('r2')->temporaryUrl($path, now()->addMinutes($minutes));
    }

    public function delete(string $path): void
    {
        Storage::disk('r2')->delete($path);
    }

    /**
     * Écriture sur R2. Une panne de stockage (identifiants absents, bucket
     * injoignable) ne doit jamais remonter telle quelle : le message brut de
     * Flysystem expose des chemins internes et n'est pas lisible par un client.
     */
    private function put(string $path, UploadedFile $file): string
    {
        try {
            Storage::disk('r2')->putFileAs('', $file, $path);
        } catch (Throwable $e) {
            report($e);

            throw new ServiceUnavailableHttpException(
                null,
                "Le stockage des documents est momentanément indisponible. Merci de réessayer dans quelques instants.",
                $e,
            );
        }

        return $path;
    }
}
