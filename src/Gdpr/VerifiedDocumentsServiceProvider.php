<?php

namespace Ramon\Verified\Gdpr;

use Flarum\Foundation\AbstractServiceProvider;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\VerifiedStatus;

/**
 * Liga o `VerifiedDocuments` às dependências que o pipeline GDPR não
 * consegue injetar: o `Exporter` instancia data types via `new $type(...)`
 * com 6 args fixos (vendor/flarum/gdpr/src/Exporter.php:56). Em vez de
 * gravar o container inteiro, resolvemos pontualmente cada colaborador —
 * `DocumentPathResolver` e `VerifiedStatus` como instâncias, `DocumentCipher`
 * por resolver lazy e o logger direto — autoridade restrita ao que o data
 * type realmente usa.
 */
class VerifiedDocumentsServiceProvider extends AbstractServiceProvider
{
    public function boot(): void
    {
        $container = $this->container;

        VerifiedDocuments::setCipherResolver(fn () => $container->make(DocumentCipher::class));
        VerifiedDocuments::setLogger($container->make(LoggerInterface::class));
        VerifiedDocuments::setPathResolver($container->make(DocumentPathResolver::class));
        VerifiedDocuments::setVerifiedStatus($container->make(VerifiedStatus::class));
    }
}
