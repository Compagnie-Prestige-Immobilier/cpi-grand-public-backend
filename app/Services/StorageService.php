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
        return $this->put("docs/{$clientId}/{$docId}_v{$version}.".self::extension($file), $file);
    }

    /**
     * Documents CPI : cpi-docs/{clientId}/{docId}.{ext}
     */
    public function uploadCpiDoc(string $clientId, string $docId, UploadedFile $file): string
    {
        return $this->put("cpi-docs/{$clientId}/{$docId}.".self::extension($file), $file);
    }

    /**
     * Photos/vidéos de chantier : chantier/{clientId}/{mediaId}.{ext}
     */
    public function uploadChantierMedia(string $clientId, string $mediaId, UploadedFile $file): string
    {
        return $this->put("chantier/{$clientId}/{$mediaId}.".self::extension($file), $file);
    }

    /**
     * Photo de profil : avatars/{userId}.{ext}
     */
    public function uploadAvatar(string $userId, UploadedFile $file): string
    {
        return $this->put("avatars/{$userId}.".self::extension($file), $file);
    }

    /**
     * Extension du fichier stocké, déduite du CONTENU et non du nom fourni.
     *
     * `getClientOriginalExtension()` renvoie l'extension du nom envoyé par le
     * navigateur : entièrement contrôlée par l'appelant. Un fichier déposé sous
     * le nom « contrat.pdf » mais contenant du HTML était stocké en `.pdf`, et
     * l'extension du chemin R2 ne disait donc rien de ce qu'il contenait
     * réellement. `extension()` s'appuie sur le type MIME détecté côté serveur.
     *
     * La validation des routes (`mimes:pdf,jpg,...`) reste le vrai filtre : ce
     * correctif garantit seulement que le chemin stocké décrit le contenu.
     */
    private static function extension(UploadedFile $file): string
    {
        return $file->extension() ?: 'bin';
    }

    /**
     * URL signée de courte durée — la SEULE façon de servir les fichiers.
     *
     * `ResponseContentDisposition` force le téléchargement plutôt qu'un rendu
     * dans l'onglet : un fichier accepté par la validation mais interprétable
     * par le navigateur (SVG, HTML déguisé) ne s'exécute pas sur le domaine du
     * lien signé. `$inline` permet l'affichage direct là où c'est voulu
     * (aperçu d'image, visionneuse PDF).
     */
    public function temporaryUrl(string $path, int $minutes = 15, bool $inline = false): string
    {
        $nom = basename($path);

        return Storage::disk('r2')->temporaryUrl($path, now()->addMinutes($minutes), [
            'ResponseContentDisposition' => $inline
                ? 'inline; filename="'.$nom.'"'
                : 'attachment; filename="'.$nom.'"',
        ]);
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
                'Le stockage des documents est momentanément indisponible. Merci de réessayer dans quelques instants.',
                $e,
            );
        }

        return $path;
    }
}
