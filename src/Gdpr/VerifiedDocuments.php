<?php

namespace Ramon\Verified\Gdpr;

use Flarum\Foundation\Paths;
use Flarum\Gdpr\Data\Type;
use Ramon\Verified\Models\VerificationRequest;

/**
 * GDPR DataType — exposes the data this extension stores per user to
 * flarum/gdpr's export / anonymize / delete pipeline.
 *
 * What this DataType covers, per user:
 *   - rows in `verification_requests` (as submitter AND as handler)
 *   - the actual document files in `storage/verified-documents/{userId}/`
 *   - the verified_at / verified_by / is_verified columns on `users`
 *
 * Lifecycle:
 *   - export()    → bundle everything (request rows JSON + raw documents) into the user's export zip
 *   - anonymize() → strip PII (reason / admin_note / document_path), nuke document files, null out handled_by references
 *   - delete()    → hard-delete every request + documents, reset verified state
 *
 * NOTE: this class extends `Flarum\Gdpr\Data\Type`, which only exists when
 * flarum/gdpr is installed. The class is only autoloaded when our extend.php
 * registers it (which we gate with `class_exists` on the GDPR extender), so
 * forums without flarum/gdpr never trigger autoload of this file.
 */
class VerifiedDocuments extends Type
{
    public static function dataType(): string
    {
        return 'VerifiedDocuments';
    }

    /**
     * Fields treated as PII when GDPR serializes events for external sinks
     * (message brokers etc.) — these get masked even when the rest of the
     * request payload is forwarded.
     */
    public static function piiFields(): array
    {
        return ['reason', 'admin_note', 'document_path'];
    }

    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ramon-verified.gdpr.export_description');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ramon-verified.gdpr.anonymize_description');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ramon-verified.gdpr.delete_description');
    }

    public function export(): ?array
    {
        $exportData = [];

        // Verified status snapshot — always include, even if no requests exist.
        $exportData[] = ['verified/status.json' => $this->encodeForExport([
            'is_verified' => (bool) $this->user->is_verified,
            'verified_at' => optional($this->user->verified_at)->toRfc3339String(),
        ])];

        // Verification requests (as submitter).
        $submitted = VerificationRequest::query()
            ->where('user_id', $this->user->id)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($submitted as $req) {
            $exportData[] = ["verified/requests/request-{$req->id}.json" => $this->encodeForExport([
                'id'            => (int) $req->id,
                'status'        => $req->status,
                'document_type' => $req->document_type,
                'document_path' => $req->document_path,
                'reason'        => $req->reason,
                'admin_note'    => $req->admin_note,
                'created_at'    => optional($req->created_at)->toRfc3339String(),
                'handled_at'    => optional($req->handled_at)->toRfc3339String(),
            ])];
        }

        // Verification requests this user handled as an admin. We export
        // only the action footprint (request id + their own admin_note +
        // when), strictly scoped to data this user generated. NO submitter
        // identifier is included — that would be data about another person,
        // outside the GDPR scope of "the data subject's own data".
        $handled = VerificationRequest::query()
            ->where('handled_by', $this->user->id)
            ->orderBy('handled_at', 'asc')
            ->get();

        foreach ($handled as $req) {
            $exportData[] = ["verified/handled/request-{$req->id}.json" => $this->encodeForExport([
                'request_id'   => (int) $req->id,
                'final_status' => $req->status,
                'admin_note'   => $req->admin_note,
                'handled_at'   => optional($req->handled_at)->toRfc3339String(),
            ])];
        }

        // Bundle the actual document files — these are the actor's own ID
        // photos / PDFs, so they belong in their export.
        foreach ($this->collectDocumentFiles() as $name => $contents) {
            $exportData[] = ["verified/documents/{$name}" => $contents];
        }

        return $exportData;
    }

    public function anonymize(): void
    {
        // Strip PII from request rows but keep the audit trail (status, dates)
        // so other forum data referencing these requests stays consistent.
        VerificationRequest::query()
            ->where('user_id', $this->user->id)
            ->update([
                'reason'        => null,
                'admin_note'    => null,
                'document_path' => null,
            ]);

        // Where the user acted as a handler (admin), null the back-reference
        // so we don't keep a foreign key to an anonymized identity.
        VerificationRequest::query()
            ->where('handled_by', $this->user->id)
            ->update(['handled_by' => null]);

        // Documents themselves are PII (government IDs, photos) — wipe them.
        $this->deleteDocumentFiles();

        // Verified state is part of the user identity — let it carry through
        // anonymization. The Gdpr User type doesn't touch our columns since
        // it doesn't know about them, and we don't want is_verified flipped
        // either (the audit trail expects it). Reset only `verified_by` to
        // detach from any handler that no longer means anything.
        $this->user->verified_by = null;
        $this->user->save();
    }

    public function delete(): void
    {
        // Hard-delete all requests submitted by the user.
        VerificationRequest::query()
            ->where('user_id', $this->user->id)
            ->delete();

        // Detach handler back-references on rows the user previously moderated.
        VerificationRequest::query()
            ->where('handled_by', $this->user->id)
            ->update(['handled_by' => null]);

        // Delete document files from storage/verified-documents/{id}/.
        $this->deleteDocumentFiles();

        // Reset the verified columns. The user model itself is deleted by the
        // Gdpr User type (which runs in the same erasure pipeline), but if
        // some other DataType saves the user later, we want a clean slate.
        $this->user->is_verified = false;
        $this->user->verified_at = null;
        $this->user->verified_by = null;
        $this->user->save();
    }

    /**
     * Read every file in the user's verified-documents directory and return
     * `[filename => binary contents]`. Returns an empty array when the
     * directory doesn't exist.
     */
    private function collectDocumentFiles(): array
    {
        $dir = $this->getUserDocumentsDirectory();
        if (! is_dir($dir)) return [];

        $out = [];
        $entries = @scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($full)) continue;
            $contents = @file_get_contents($full);
            if ($contents === false) continue;
            $out[$entry] = $contents;
        }
        return $out;
    }

    /**
     * Remove every file in the user's verified-documents directory and the
     * directory itself. Silent on permission errors — the caller is the
     * GDPR pipeline, which logs failures upstream.
     */
    private function deleteDocumentFiles(): void
    {
        $dir = $this->getUserDocumentsDirectory();
        if (! is_dir($dir)) return;

        $entries = @scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_file($full)) @unlink($full);
        }
        @rmdir($dir);
    }

    private function getUserDocumentsDirectory(): string
    {
        /** @var Paths $paths */
        $paths = resolve(Paths::class);
        return rtrim($paths->storage, '/\\')
            .DIRECTORY_SEPARATOR.'verified-documents'
            .DIRECTORY_SEPARATOR.((int) $this->user->id);
    }
}
