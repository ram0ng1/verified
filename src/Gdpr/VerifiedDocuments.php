<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Gdpr;

use Flarum\Gdpr\Data\Type;
use Flarum\Gdpr\Models\ErasureRequest;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Models\UserVerification;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\VerifiedStatus;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * O flarum/gdpr instancia os tipos com `new $type(...)` e 6 argumentos fixos,
 * sem DI por construtor. O `DocumentCipher` depende do `Config` e por isso é
 * resolvido de forma lazy, só no export; testes o injetam pelo 7º argumento.
 */
class VerifiedDocuments extends Type
{
    /**
     * Um admin veterano pode ter tratado dezenas de milhares de solicitações; o
     * export as percorre em chunks deste tamanho.
     */
    private const EXPORT_CHUNK = 200;

    protected DocumentPathResolver $pathResolver;

    protected VerifiedStatus $verifiedStatus;

    protected ?DocumentCipher $cipherOverride;

    public function __construct(
        User $user,
        ?ErasureRequest $erasureRequest,
        Factory $factory,
        SettingsRepositoryInterface $settings,
        UrlGenerator $url,
        TranslatorInterface $translator,
        ?DocumentCipher $cipher = null
    ) {
        parent::__construct($user, $erasureRequest, $factory, $settings, $url, $translator);

        $this->pathResolver   = new DocumentPathResolver();
        $this->verifiedStatus = new VerifiedStatus();
        $this->cipherOverride = $cipher;
    }

    private function cipher(): ?DocumentCipher
    {
        if ($this->cipherOverride !== null) {
            return $this->cipherOverride;
        }

        try {
            $cipher = resolve(DocumentCipher::class);
        } catch (Throwable $e) {
            return null;
        }

        return $cipher instanceof DocumentCipher ? $cipher : null;
    }

    public static function dataType(): string
    {
        return 'VerifiedDocuments';
    }

    /**
     * Mascarados mesmo quando o resto do payload vai para sinks externos.
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

        $exportData[] = ['verified/status.json' => $this->encodeForExport([
            'is_verified' => $this->verifiedStatus->isVerified($this->user),
            'verified_at' => optional($this->verifiedStatus->verifiedAt($this->user))->toRfc3339String(),
        ])];

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

        VerificationRequest::query()
            ->where('handled_by', $this->user->id)
            ->select(['id', 'status', 'admin_note', 'handled_at'])
            ->orderBy('id')
            ->chunkById(self::EXPORT_CHUNK, function ($handled) use (&$exportData) {
                foreach ($handled as $req) {
                    $exportData[] = ["verified/handled/request-{$req->id}.json" => $this->encodeForExport([
                        'request_id'   => (int) $req->id,
                        'final_status' => $req->status,
                        'admin_note'   => $req->admin_note,
                        'handled_at'   => optional($req->handled_at)->toRfc3339String(),
                    ])];
                }
            });

        foreach ($this->collectDocumentFiles() as $name => $contents) {
            $exportData[] = ["verified/documents/{$name}" => $contents];
        }

        return $exportData;
    }

    /**
     * `is_verified` permanece de propósito: o histórico precisa dele após a
     * anonimização. Os UPDATEs ficam numa transação para não sobrar PII parcial;
     * os arquivos são apagados fora dela, pois disco não tem rollback.
     */
    public function anonymize(): void
    {
        VerificationRequest::runInTransaction(function () {
            VerificationRequest::query()
                ->where('user_id', $this->user->id)
                ->update([
                    'reason'        => null,
                    'admin_note'    => null,
                    'document_path' => null,
                ]);

            VerificationRequest::query()
                ->where('handled_by', $this->user->id)
                ->update(['handled_by' => null]);

            $row = UserVerification::query()->where('user_id', $this->user->id)->first();
            if ($row instanceof UserVerification) {
                $row->verified_by = null;
                $row->save();
            }
        });

        $this->deleteDocumentFiles();
    }

    public function delete(): void
    {
        VerificationRequest::runInTransaction(function () {
            VerificationRequest::query()
                ->where('user_id', $this->user->id)
                ->delete();

            VerificationRequest::query()
                ->where('handled_by', $this->user->id)
                ->update(['handled_by' => null]);

            $this->verifiedStatus->clear($this->user);
        });

        $this->deleteDocumentFiles();
    }

    /**
     * Devolve `[filename => bytes]`. Ciphertext nunca sai no export: arquivos
     * cifrados sem privada disponível são listados em `_encrypted_skipped.txt`.
     */
    private function collectDocumentFiles(): array
    {
        $disk    = $this->disk();
        $userDir = $this->pathResolver->userDirectory((int) $this->user->id);

        if (! $disk->exists($userDir)) {
            return [];
        }

        $cipher = $this->cipher();

        $out = [];
        $skippedEncrypted = 0;

        foreach ($disk->files($userDir) as $relative) {
            $name = basename($relative);
            if ($name === '.' || $name === '..') continue;

            try {
                $contents = $disk->get($relative);
            } catch (Throwable $e) {
                continue;
            }
            if (! is_string($contents)) continue;

            if (DocumentCipher::isEncryptedBlob($contents)) {
                if ($cipher === null || ! $cipher->canDecrypt()) {
                    $skippedEncrypted++;
                    continue;
                }
                try {
                    $contents = $cipher->decryptIfEncrypted($contents);
                } catch (Throwable $e) {
                    $skippedEncrypted++;
                    continue;
                }
            }

            $out[$name] = $contents;
        }

        if ($skippedEncrypted > 0) {
            $out['_encrypted_skipped.txt'] =
                "{$skippedEncrypted} encrypted document file(s) could not be exported because\n"
                ."the verification system's private key is not configured on this server.\n";
        }

        return $out;
    }

    /**
     * A falha propaga de propósito: o `ErasureJob` falha e reexecuta em vez de dar
     * a erasure como concluída com documentos de identidade no disco.
     */
    private function deleteDocumentFiles(): void
    {
        $disk    = $this->disk();
        $userDir = $this->pathResolver->userDirectory((int) $this->user->id);

        if (! $disk->exists($userDir)) {
            return;
        }

        $disk->deleteDirectory($userDir);
    }

    private function disk(): Filesystem
    {
        return $this->getDisk(DocumentPathResolver::DISK);
    }
}
