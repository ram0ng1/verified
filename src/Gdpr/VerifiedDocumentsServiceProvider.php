<?php

namespace Ramon\Verified\Gdpr;

use Flarum\Foundation\AbstractServiceProvider;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Crypto\DocumentCipher;

/**
 * Liga o `VerifiedDocuments` às dependências que o pipeline GDPR não
 * consegue injetar: o `Exporter` instancia data types via `new $type(...)`
 * com 6 args fixos (vendor/flarum/gdpr/src/Exporter.php:56). Em vez de
 * gravar o container inteiro, passamos um resolver lazy do `DocumentCipher`
 * e o logger — autoridade restrita ao que o data type realmente usa.
 */
class VerifiedDocumentsServiceProvider extends AbstractServiceProvider
{
    public function boot(): void
    {
        $container = $this->container;

        VerifiedDocuments::setCipherResolver(fn () => $container->make(DocumentCipher::class));
        VerifiedDocuments::setLogger($container->make(LoggerInterface::class));
    }
}
