<p align="center">
  <img src="icon.svg" width="80" alt="Verified">
  <h1 align="center">Verified</h1>
</p>

<p align="center">
  <a href="https://github.com/ram0ng1/verified/actions/workflows/ci.yml">
    <img alt="CI" src="https://github.com/ram0ng1/verified/actions/workflows/ci.yml/badge.svg?branch=main">
  </a>
  <img alt="License" src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square">
  <a href="https://packagist.org/packages/ramon/verified">
    <img alt="Latest Stable Version" src="https://img.shields.io/packagist/v/ramon/verified.svg?style=flat-square">
  </a>
  <a href="https://packagist.org/packages/ramon/verified">
    <img alt="Total Downloads" src="https://img.shields.io/packagist/dt/ramon/verified.svg?style=flat-square">
  </a>
  <a href="https://github.com/ram0ng1/verified/releases/latest">
    <img alt="GitHub Release" src="https://img.shields.io/github/v/release/ram0ng1/verified?style=flat-square&label=release&color=success">
  </a>
  <a href="https://donate.stripe.com/fZe5o66nebkf39S28a">
    <img alt="Donate" src="https://img.shields.io/badge/donate-stripe-%236772E5?style=flat-square">
  </a>
</p>

<p align="center">
  An X (Twitter)-style verified badge for <a href="https://flarum.org">Flarum</a> users, with admin-managed verification requests, optional identity-document upload, encrypted storage, retention policies and avatar locking.
</p>

---

## Features

- **Verified badge** — Blue verified mark next to the username everywhere it is rendered (post header, profile, user card, mentions).
- **Custom badge** — Default X/Twitter-style SVG, theme-color aware. Admins can replace the SVG and tweak its size/color.
- **Verification requests** — Users can request verification from their account settings page; admins approve or reject from a dedicated admin page.
- **Identity documents** — Optionally require an identity document (RG / CPF / passport / driver's license / custom types) on request.
- **Encrypted storage** — Opt-in public-key encryption for uploaded documents; private key kept out of the database.
- **Retention policies** — Keep documents forever, wipe on decision, or auto-purge N days after handling (nightly schedule).
- **Avatar lock** — Once verified, the user cannot change their avatar without re-applying for verification.
- **Tooltip** — Hover tooltip explains who verified the account and when.
- **Notifications** — Users are notified by alert and email when their request is approved.
- **GDPR-aware** — Integrates with `flarum/gdpr` for data export, anonymization and erasure when installed.

## Requirements

- Flarum `^2.0.0`

## Installation

```sh
composer require ramon/verified
php flarum migrate
php flarum cache:clear
```

Then enable **Verified** under the *Extensions* page in the admin panel.

## Updating

```sh
composer update ramon/verified --with-dependencies
php flarum migrate
php flarum cache:clear
```

## Configuration

All settings are available in the admin panel under the Verified extension:

| Setting | Description | Default |
|---|---|---|
| Requests open | Allow users to submit new verification requests | `true` |
| Require document | Users must upload an identity document with their request | `false` |
| Document types | JSON list of identity-document types offered to users | RG / CPF / Passport / Driver's license / Other |
| Document retention | `keep`, `delete_immediate`, or `delete_after_days` | `keep` |
| Retention days | When mode is `delete_after_days`, how long after handling to keep files | `30` |
| Encryption public key | Base64 public key — empty disables encryption | — |
| Lock avatar | Verified users cannot change avatar; doing so revokes verification | `false` |
| Custom color enabled | Use a custom badge color instead of the theme primary | `false` |
| Badge color | Color used by the default SVG when custom color is enabled | theme primary |
| Badge SVG | Upload an SVG to replace the default Twitter-style badge | — |
| Badge size | Badge size multiplier (in `em`) | `1.2` |
| Show tooltip | Show "Verified by … on …" on hover | `true` |

## Permissions

- **Verified — request verification**: who can submit a verification request (defaults to members).
- **Verified — verify users**: who can approve/reject requests and toggle verification (admins by default).

## API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/verified/documents` | Upload an identity document for the current user's pending request |
| `GET` | `/api/verified/documents/{id}` | Download a document (admin only) |
| `POST` | `/api/verified/badge-svg` | Upload a custom badge SVG |
| `DELETE` | `/api/verified/badge-svg` | Remove the custom badge SVG |
| `POST` | `/api/verified/users/{id}/verify` | Mark a user as verified |
| `DELETE` | `/api/verified/users/{id}/verify` | Revoke a user's verification |
| `GET` | `/api/verified/approved-users` | List currently verified users |
| `GET` | `/api/verified/encryption/status` | Inspect document encryption status |
| `POST` | `/api/verified/encryption/generate-keypair` | Generate a new encryption keypair |

## Console

- `php flarum verified:purge-documents` — wipe documents whose retention window has elapsed. Runs nightly at 03:30 automatically; the command is a no-op unless retention mode is `delete_after_days`.

## Links

- [GitHub](https://github.com/ram0ng1/verified)
- [Issues](https://github.com/ram0ng1/verified/issues)
- [Donate](https://donate.stripe.com/fZe5o66nebkf39S28a)

## Authors

- [Ramon Guilherme](https://ramonguilherme.com.br)

## License

[MIT](LICENSE)
