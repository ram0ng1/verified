<?php

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
 * DataType do flarum/gdpr. Exporta/anonimiza/deleta as linhas de
 * `verification_requests` e o estado em `user_verification` deste usuário.
 *
 * O GDPR instancia tipos via `new $type(...)` com 6 args fixos
 * (Exporter.php:56, ErasureJob.php:102), então não há janela de DI por
 * construtor para colaboradores extras. `DocumentPathResolver` e
 * `VerifiedStatus` são stateless sem dependências — instanciados direto.
 * O `DocumentCipher` precisa de `Flarum\Foundation\Config` (a chave privada
 * mora em `config.php`, não na tabela `settings`), inalcançável pelos 6
 * args; é resolvido de forma lazy, só na exportação, no mesmo padrão da
 * classe-base `Type::staticTranslator()`. Testes sem boot da app injetam o
 * cipher pelo 7º argumento opcional do construtor.
 */
class VerifiedDocuments extends Type
{
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

    /**
     * Resolve o `DocumentCipher` apenas no momento em que arquivos cifrados
     * são encontrados. O cipher injetado no construtor tem prioridade
     * (cenário de teste sem boot completo); fora dele, resolve do container.
     */
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
     * Campos tratados como PII quando o GDPR serializa eventos para sinks
     * externos. Mascarados mesmo quando o restante do payload é encaminhado.
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

        foreach ($this->collectDocumentFiles() as $name => $contents) {
            $exportData[] = ["verified/documents/{$name}" => $contents];
        }

        return $exportData;
    }

    /**
     * Remove a PII das linhas, preservando o trilho de auditoria (status,
     * datas). O `is_verified` permanece intencionalmente — o histórico
     * espera o flag verdadeiro mesmo após a anonimização.
     *
     * Mutações DB envoltas em transação: se qualquer dos UPDATEs falhar,
     * o usuário não fica num estado intermediário com algumas linhas
     * zeradas e outras com PII residual. `deleteDocumentFiles` fica FORA
     * do bloco — operação de disco não tem rollback útil.
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
     * Lê cada arquivo do diretório do usuário no disco privado e devolve
     * `[filename => bytes]`. Arquivos cifrados sem chave privada disponível
     * são pulados e contabilizados em `_encrypted_skipped.txt` — o sujeito
     * tem direito ao próprio dado em claro, então ciphertext nunca sai no
     * export.
     */
    private function collectDocumentFiles(): array
    {
        $disk    = $this->disk();
        $userDir = $this->pathResolver->userDirectory((int) $this->user->id);

        if (! $disk->directoryExists($userDir)) {
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
     * Apaga o diretório de documentos do usuário no disco privado. Uma falha
     * NÃO é silenciada: propaga e faz o `ErasureJob` (job de fila) falhar em
     * vez de marcar a erasure como concluída deixando documentos de identidade
     * em disco — exatamente a violação de GDPR que o fluxo existe para evitar.
     * O job reexecuta; as mutações de DB anteriores são idempotentes.
     */
    private function deleteDocumentFiles(): void
    {
        $disk    = $this->disk();
        $userDir = $this->pathResolver->userDirectory((int) $this->user->id);

        if (! $disk->directoryExists($userDir)) {
            return;
        }

        $disk->deleteDirectory($userDir);
    }

    private function disk(): Filesystem
    {
        return $this->getDisk(DocumentPathResolver::DISK);
    }
}
