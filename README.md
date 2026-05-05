# Verified — User verification for Flarum v2

A Twitter-style verified badge for Flarum users.

## Features

- Blue verified badge displayed next to the username everywhere it is rendered (post header, profile, user card, mentions).
- Default Twitter-style SVG, theme-color aware. Admins can replace the SVG with a custom one.
- Users can request verification from their account settings page.
- Admins can require an identity document (RG / CPF / passport / driver's license) when users request verification.
- Admins approve or reject pending requests from a dedicated admin page.
- Optional avatar lock: once verified, the user cannot change their avatar without re-applying for verification.
- Hover tooltip explains who verified the account and when.

## Install

```sh
composer require ramon/verified
php flarum migrate
php flarum cache:clear
```

Then enable **Verified** under the *Extensions* page in the admin panel.

## Settings

| Setting | Default | Description |
| --- | --- | --- |
| Require document | off | When on, users must upload an identity document with their request |
| Lock avatar after verification | off | When on, verified users cannot change avatar; doing so revokes verification |
| Custom badge SVG | empty | Paste an SVG to replace the default Twitter-style badge |
| Badge color | theme primary | Color used by the default SVG |

## Permissions

- **Verified — request verification**: who can submit a verification request (defaults to members).

## License

MIT — © Ramon Guilherme.
