<?php

namespace Ramon\Verified\Gdpr;

use Flarum\Gdpr\Data\Type;
use Flarum\Gdpr\Models\ErasureRequest;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
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
 * GDPR instancia tipos via `new $type(...)` com exatamente 6 args fixos
 * (vendor/flarum/gdpr/src/Exporter.php:56), então não há janela de DI direta
 * para `VerifiedStatus`, `DocumentPathResolver` e `DocumentCipher`. O acesso
 * estático ao container é confinado ao construtor (com `bound()` checks per
 * §44.3) e os serviços resolvidos viram propriedades — os métodos de
 * domínio nunca tocam `Container::getInstance()`. O 7º arg opcional
 * `?DocumentCipher` continua existindo para os testes.
 */
class VerifiedDocuments extends Type
{
    protected DocumentPathResolver $pathResolver;

    protected ?VerifiedStatus $verifiedStatus = null;

    protected ?DocumentCipher $cipher;

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

        $container = Container::getInstance();

        $this->pathResolver = $container->bound(DocumentPathResolver::class)
            ? $container->make(DocumentPathResolver::class)
            : new DocumentPathResolver();

        if ($container->bound(VerifiedStatus::class)) {
            try {
                $this->verifiedStatus = $container->make(VerifiedStatus::class);
            } catch (Throwable $e) {
                $this->verifiedStatus = null;
            }
        }

        if ($cipher !== null) {
            $this->cipher = $cipher;
        } elseif ($container->bound(DocumentCipher::class)) {
            try {
                $this->cipher = $container->make(DocumentCipher::class);
            } catch (Throwable $e) {
                $this->cipher = null;
            }
        } else {
            $this->cipher = null;
        }
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

        $status = $this->verifiedStatus;

        $exportData[] = ['verified/status.json' => $this->encodeForExport([
            'is_verified' => $status ? $status->isVerified($this->user) : false,
            'verified_at' => $status ? optional($status->verifiedAt($this->user))->toRfc3339String() : null,
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
     * Mutações DB envoltas em transaction (§61.3.14): se qualquer dos
     * UPDATEs falhar, o usuário não fica num estado intermediário com
     * algumas linhas zeradas e outras com PII residual. `deleteDocumentFiles`
     * fica FORA do bloco — operação de disco não tem rollback útil.
     */
    public function anonymize(): void
    {
        VerificationRequest::query()->getConnection()->transaction(function () {
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
        VerificationRequest::query()->getConnection()->transaction(function () {
            VerificationRequest::query()
                ->where('user_id', $this->user->id)
                ->delete();

            VerificationRequest::query()
                ->where('handled_by', $this->user->id)
                ->update(['handled_by' => null]);

            $this->verifiedStatus?->clear($this->user);
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

        $cipher = $this->cipher;

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

    private function deleteDocumentFiles(): void
    {
        $disk    = $this->disk();
        $userDir = $this->pathResolver->userDirectory((int) $this->user->id);

        if (! $disk->directoryExists($userDir)) {
            return;
        }

        try {
            $disk->deleteDirectory($userDir);
        } catch (Throwable $e) {
        }
    }

    private function disk(): Filesystem
    {
        return $this->getDisk(DocumentPathResolver::DISK);
    }
}
