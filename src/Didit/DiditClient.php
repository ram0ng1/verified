<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Didit;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Cliente da API de sessões da Didit (v3). Os testes trocam o transporte
 * sobrescrevendo `send()`.
 */
class DiditClient
{
    public const BASE_URL = 'https://verification.didit.me';

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    protected ClientInterface $http;

    public function __construct(
        protected DiditConfig $config,
        ?ClientInterface $http = null
    ) {
        $this->http = $http ?? new Client(['base_uri' => self::BASE_URL, 'timeout' => 15, 'connect_timeout' => 5]);
    }

    /**
     * Cria a sessão de verificação. Para o mesmo par (workflow, vendor_data) a
     * Didit devolve a sessão ainda não concluída em vez de criar outra.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function createSession(string $vendorData, string $callback, array $metadata = [], ?string $language = null): array
    {
        $body = [
            'vendor_data' => $vendorData,
            'callback'    => $callback,
            'metadata'    => (object) $metadata,
        ];

        $body['workflow_id'] = $this->config->workflowId() ?? $this->defaultWorkflowId();

        if ($language !== null && preg_match('/\A[a-z]{2}\z/', $language)) {
            $body['language'] = $language;
        }

        $session = $this->send('POST', '/v3/session/', $body);

        if (! isset($session['session_id'], $session['url']) || ! self::isSessionId((string) $session['session_id'])) {
            throw new DiditException('Didit returned an unexpected session payload.');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecision(string $sessionId): array
    {
        return $this->send('GET', '/v3/session/'.$this->assertSessionId($sessionId).'/decision/');
    }

    public function deleteSession(string $sessionId): void
    {
        $this->send('DELETE', '/v3/session/'.$this->assertSessionId($sessionId).'/delete/');
    }

    /**
     * @return list<array{id: string, label: string, type: string, isDefault: bool}>
     */
    public function listWorkflows(): array
    {
        $data = $this->send('GET', '/v3/workflows/');
        $rows = isset($data['results']) && is_array($data['results']) ? $data['results'] : $data;

        $workflows = [];
        foreach ($rows as $row) {
            if (! is_array($row)) continue;

            $id = (string) ($row['uuid'] ?? $row['workflow_id'] ?? '');
            if (! self::isSessionId($id)) continue;

            $workflows[] = [
                'id'        => $id,
                'label'     => (string) ($row['workflow_label'] ?? $id),
                'type'      => (string) ($row['workflow_type'] ?? ''),
                'isDefault' => (bool) ($row['is_default'] ?? false),
            ];
        }

        return $workflows;
    }

    /**
     * Sem workflow escolhido no admin vale o padrão da conta. A API v3 exige o
     * `workflow_id` mesmo assim (responde 400 sem ele), então ele é buscado.
     */
    public function defaultWorkflowId(): string
    {
        $workflows = $this->listWorkflows();

        foreach ($workflows as $workflow) {
            if ($workflow['isDefault']) return $workflow['id'];
        }

        if ($workflows === []) {
            throw new DiditException('No Didit workflow is available for this API key.');
        }

        return $workflows[0]['id'];
    }

    public static function isSessionId(string $id): bool
    {
        return (bool) preg_match(self::UUID, $id);
    }

    private function assertSessionId(string $id): string
    {
        // Entra no caminho da URL: só um UUID passa.
        if (! self::isSessionId($id)) {
            throw new DiditException('Invalid Didit session id.');
        }

        return strtolower($id);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    protected function send(string $method, string $path, ?array $body = null): array
    {
        $key = $this->config->apiKey();
        if ($key === '') {
            throw new DiditException('Didit API key is not configured.');
        }

        $options = ['headers' => ['x-api-key' => $key, 'Accept' => 'application/json'], 'http_errors' => false];
        if ($body !== null) {
            $options['json'] = $body;
        }

        // Uma nova tentativa em falha de conexão: todas as chamadas daqui são
        // idempotentes na Didit (criar sessão devolve a que já está aberta).
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->request($method, $path, $options);
                break;
            } catch (ConnectException $e) {
                if ($attempt >= 2) {
                    throw new DiditException('Could not reach Didit: '.$e->getMessage(), 0, $e);
                }
            } catch (GuzzleException $e) {
                throw new DiditException('Could not reach Didit: '.$e->getMessage(), 0, $e);
            }
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $data = $raw === '' ? [] : json_decode($raw, true);

        if ($status >= 400) {
            $detail = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            throw new DiditException("Didit API {$method} {$path} failed with HTTP {$status}: ".substr((string) $detail, 0, 300), $status);
        }

        return is_array($data) ? $data : [];
    }
}
