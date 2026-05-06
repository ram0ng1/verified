<?php

namespace Ramon\Verified\Listener;

use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Models\VerificationRequest;

/**
 * Unlink a verification request's document file when the row itself is
 * being deleted. Without this hook, a user (with `verified.request`
 * permission) could:
 *
 *   1. Upload an 8 MB document (POST /verified/documents)
 *   2. Submit a pending request that points at it
 *   3. DELETE their own pending request (allowed by VerificationRequestPolicy)
 *   4. Repeat — each loop leaves the file orphaned in storage.
 *
 * Listening on the model's `eloquent.deleting: VerificationRequest` event
 * guarantees cleanup regardless of who triggered the delete (user dropping
 * their pending request, admin hard-deleting via tinker, GDPR fallback).
 */
class PurgeDocumentOnRequestDelete
{
    public function __construct(
        protected DocumentRetention $retention
    ) {
    }

    public function handle(VerificationRequest $model): void
    {
        $this->retention->purgeFileForRequest($model);
    }
}
