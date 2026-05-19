# Harness E2E — ramon/verified

Bateria de testes que exercita cada endpoint da extensão contra um fórum
Flarum 2 vivo. Não usa PHPUnit nem boot do Flarum — apenas chama a HTTP
API e valida shape + latência da resposta.

## Pré-requisitos

- PowerShell (Windows) ou Bash + curl (Linux/macOS).
- Um **token de admin** do Flarum (formato `Authorization: Token <chave>`).
  Crie via UI: Administração → Permissões → "API tokens" no perfil do
  admin. NUNCA crie um `ApiKey` global sem `user_id` (CLAUDE.md §17).

## Como rodar

PowerShell:

```powershell
$env:RV_BASE_URL = "https://alegatest.alega.com.br"
$env:RV_TOKEN    = "<seu-token-admin>"
.\run.ps1
```

Bash:

```bash
export RV_BASE_URL="https://alegatest.alega.com.br"
export RV_TOKEN="<seu-token-admin>"
./run.sh
```

## O que é testado

Para cada endpoint da extensão (rotas custom + JSON:API resource), o harness
emite ao menos uma chamada happy-path e uma failure-path:

| Endpoint | Happy | Failure |
|---|---|---|
| `GET /api/verified/approved-users` | 200 com lista | 403 como guest |
| `GET /api/verified/encryption/status` | 200 com status | 403 como guest |
| `POST /api/verified/encryption/generate-keypair` | sem `acknowledgeLoss=true` → 422 | sem token → 401 |
| `POST /api/verified/users/{id}/verify` | id inválido → 422 | sem token → 401 |
| `DELETE /api/verified/users/{id}/verify` | id inválido → 422 | sem token → 401 |
| `GET /api/verification-requests` | 200 (admin vê todas) | 401 como guest |
| `POST /api/verification-requests` | sem permissão → 403 | sem token → 401 |
| `POST /api/verification-requests/{id}/approve` | id inexistente → 422 | sem token → 401 |
| `POST /api/verified/badge-svg` | sem arquivo → 422 | 403 como user |
| `DELETE /api/verified/badge-svg` | 204 | 403 como user |
| `POST /api/verified/documents` | sem arquivo → 422 | requests fechados → 422 |
| `GET /api/verified/documents/{id}` | id inexistente → 404 | sem token → 401 |

Saída: cada teste imprime `OK` ou `FAIL` com latency_ms, e
`results.jsonl` é gerado com 1 linha por endpoint testado.

## Forum attributes assertions

O harness também valida que o payload do forum (`GET /api`) traz os
atributos certos:

- `canVerifyUsers` presente como boolean.
- `ramonVerifiedTiers` presente como array (mesmo vazio).
- `ramonVerifiedRequireDocument` **ausente** para guest (R3-8 fix).
- `ramonVerifiedRequestsOpen` **ausente** para guest (R3-8 fix).
- Mesmas chaves presentes para admin (`?bearer_token=admin`).

## Compatibilidade

Roda contra o branch `BC` pós-R4-1..R4-4. Se você rodar contra outro
branch, alguns testes failure-path podem mudar (ex: endpoints removidos
voltariam a 404 em vez de 422).
