<p align="center">
  <img src="icon.svg" width="80" height="80" alt="Verified">
</p>

<h1 align="center">Verified</h1>

<p align="center">
  <a href="https://github.com/ram0ng1/verified/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/ram0ng1/verified/ci.yml?branch=main&style=flat-square&label=ci"></a>
  <a href="https://packagist.org/packages/ramon/verified"><img alt="Packagist" src="https://img.shields.io/packagist/v/ramon/verified?style=flat-square&label=packagist"></a>
  <a href="https://packagist.org/packages/ramon/verified"><img alt="Downloads" src="https://img.shields.io/packagist/dt/ramon/verified?style=flat-square"></a>
  <img alt="Flarum" src="https://img.shields.io/badge/flarum-2.x-e7672e?style=flat-square">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square"></a>
  <a href="https://donate.stripe.com/fZe5o66nebkf39S28a"><img alt="Donate" src="https://img.shields.io/badge/donate-stripe-6772E5?style=flat-square"></a>
</p>

<p align="center">A verified badge for Flarum users, the way X does it.</p>

Verified adds a blue check next to usernames everywhere they show up: posts, profiles, user cards and mentions. Users apply from their own settings page, admins approve or reject from a dedicated panel, and the badge follows the user around the forum from then on.

It can be as light or as strict as your community needs. Run it as a simple request and approve flow, or require an identity document with each request, encrypt the uploads with a public key and let a retention policy purge the files later.

## What it does

- Blue verified badge wherever the username is rendered, with an optional hover tooltip showing who verified and when
- Verification requests from the user's settings page, reviewed in an admin panel
- Optional identity document upload, with public key encryption and configurable retention
- Avatar lock for verified users, so the face people trust does not change silently
- Custom badge SVG, size and color if the default does not match your theme
- Notifications by alert and email when a request is handled
- Plays nice with `flarum/gdpr` for export, anonymization and erasure

## Installation

```sh
composer require ramon/verified
php flarum migrate
php flarum cache:clear
```

Then enable Verified on the Extensions page. Settings, permissions and the request queue all live in the admin panel, each option explained in place.

## Good to know

- Documents are wiped on the schedule you choose. The nightly `verified:purge-documents` command takes care of it, no cron of your own needed.
- Encryption is your call: switch **Encrypt uploaded documents** on in the settings, generate the keypair there and paste the private key into `config.php` (it never goes in the database). While it is on, uploads are refused if they cannot be sealed, so nothing is ever stored unencrypted by accident. Switch it off and new uploads are stored as-is, while files already encrypted stay readable.
- Everything the frontend does goes through the `/api/verified/*` endpoints, so verification can also be automated from outside.

## Development

```sh
composer install
composer test:setup        # once: creates the SQLite test database
composer test              # unit + integration tests
composer analyse:phpstan
cd js && npm ci && npm run check-typings && npm run format-check && npm run build
```

## License

[MIT](LICENSE). Questions and ideas are welcome on the [issue tracker](https://github.com/ram0ng1/verified/issues).
