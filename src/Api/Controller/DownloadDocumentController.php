<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Models\VerificationRequest;
use RuntimeException;

class DownloadDocumentController implements RequestHandlerInterface
{
    public function __construct(
        protected Factory $filesystem,
        protected DocumentCipher $cipher,
        protected TranslatorInterface $translator,
        protected DocumentPathResolver $resolver
    ) {
    }

    /**
     * Documentos (RG / CPF / passaporte) são dados de identidade — apenas
     * administradores conseguem baixar. O remetente tem o arquivo original
     * em sua própria máquina; não precisa receber de volta. Qualquer entrada
     * inválida ou inexistente cai em 404 para não diferenciar "não encontrado"
     * de "acesso negado".
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $rawId = $request->getAttribute('id') ?? ($request->getQueryParams()['id'] ?? 0);
        $id    = (int) $rawId;
        if ($id <= 0) {
            throw new RouteNotFoundException();
        }

        /** @var VerificationRequest|null $req */
        $req = VerificationRequest::query()->find($id);
        if (! $req || ! $req->document_path) {
            throw new RouteNotFoundException();
        }

        $relative = $this->resolver->resolveRelative((string) $req->document_path, (int) $req->user_id);
        if ($relative === null) {
            throw new RouteNotFoundException();
        }

        $disk = $this->filesystem->disk(DocumentPathResolver::DISK);
        if (! $disk->exists($relative)) {
            throw new RouteNotFoundException();
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
            default => 'application/octet-stream',
        };

        $fileStream = $disk->readStream($relative);
        if (! is_resource($fileStream)) {
            throw new RouteNotFoundException();
        }

        $head = fread($fileStream, strlen(DocumentCipher::MAGIC));
        $isEncrypted = $head === DocumentCipher::MAGIC;

        if ($isEncrypted && ! $this->cipher->canDecrypt()) {
            fclose($fileStream);

            return $this->encryptionError(
                'private_key_missing',
                503,
                (string) $this->translator->trans('ramon-verified.api.encryption.private_key_missing')
            );
        }

        try {
            [$body, $contentLength] = $isEncrypted
                ? $this->buildEncryptedBody($fileStream, $disk, $relative)
                : $this->buildPlainBody($fileStream, $disk, $relative);
        } catch (RuntimeException $e) {
            return $this->encryptionError(
                'decryption_failed',
                500,
                (string) $this->translator->trans('ramon-verified.api.encryption.decryption_failed')
            );
        }

        $disposition = $request->getQueryParams()['download'] ?? null
            ? 'attachment'
            : 'inline';

        $response = (new Response())
            ->withBody($body)
            ->withHeader('Content-Type', $mime) /* $mime sai de um match fechado por extensão validada, nunca do cliente; nosemgrep: flarum-v2-client-controlled-mime */
            ->withHeader('Content-Length', (string) $contentLength)
            ->withHeader('Content-Disposition', $disposition.'; filename="document.'.$extension.'"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0');

        if ($mime === 'application/pdf') {
            $response = $response->withHeader('Content-Security-Policy', 'sandbox');
        }

        return $response;
    }

    /**
     * Resposta de erro estruturada (envelope JSON:API) para falhas de
     * criptografia. Uma `RuntimeException` crua viraria um 500 genérico cujo
     * corpo o handler de erros da API esvazia — a mensagem traduzida, que diz
     * ao admin exatamente o que corrigir em `config.php`, nunca chegaria.
     */
    private function encryptionError(string $code, int $status, string $detail): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $status,
                'code'   => $code,
                'detail' => $detail,
            ]],
        ], $status);
    }

    /**
     * Cifrado: libsodium sealed-box exige buffer inteiro para abrir.
     * Fechamos a stream do disco e relemos via `get()` — desperdiça ~6
     * bytes já lidos, mas mantém o caminho cripto linear.
     *
     * @param resource $fileStream
     * @return array{0: Stream, 1: int}
     */
    private function buildEncryptedBody($fileStream, Filesystem $disk, string $relative): array
    {
        fclose($fileStream);

        $blob = $disk->get($relative);
        if (! is_string($blob)) {
            throw new RouteNotFoundException();
        }

        $payload = $this->cipher->decryptIfEncrypted($blob);
        $length = strlen($payload);

        $body = new Stream('php://temp', 'r+');
        $body->write($payload);
        $body->rewind();

        sodium_memzero($payload);

        return [$body, $length];
    }

    /**
     * Plaintext: stream direto do disco, memória constante. `Content-Length`
     * via `$disk->size()` evita ler o arquivo só para contar bytes
     * para evitar reler o arquivo só pra contar bytes.
     *
     * @param resource $fileStream
     * @return array{0: Stream, 1: int}
     */
    private function buildPlainBody($fileStream, Filesystem $disk, string $relative): array
    {
        rewind($fileStream);
        $body = new Stream($fileStream);
        $length = (int) $disk->size($relative);

        return [$body, $length];
    }
}
