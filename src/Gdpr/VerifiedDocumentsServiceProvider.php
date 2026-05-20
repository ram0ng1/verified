<?php

namespace Ramon\Verified\Gdpr;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * Injeta o container no `VerifiedDocuments` no boot da app. O `Exporter` do
 * `flarum/gdpr` instancia data types via `new $type(...)` com 6 args fixos
 * (vendor/flarum/gdpr/src/Exporter.php:56), o que fecha a janela usual de
 * DI por construtor. Em vez de cair em `resolve()` inline, gravamos o
 * container uma única vez aqui e o data type usa `make()` quando
 * efetivamente precisa do `DocumentCipher`.
 */
class VerifiedDocumentsServiceProvider extends AbstractServiceProvider
{
    public function boot(): void
    {
        VerifiedDocuments::setContainer($this->container);
    }
}
