<?php

namespace App\Providers;

use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider as BaseTypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;

class TypeScriptTransformerServiceProvider extends BaseTypeScriptTransformerServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(AttributedClassTransformer::class)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(app_path())
            // Types générés côté frontend - l'équivalent v3 de `output_file`.
            // Le chemin vit dans config/typescript.php : il pointait vers
            // `../frontend`, alors que le dépôt voisin s'appelle
            // `cpi-grand-public-frontend`. Toute régénération écrivait donc
            // dans un dossier fantôme.
            ->outputDirectory(config('typescript.output_directory'))
            ->writer(new GlobalNamespaceWriter('generated.d.ts'));
    }
}
