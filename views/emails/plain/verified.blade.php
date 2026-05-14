<x-mail::plain.information>
<x-slot:body>
{{-- Mail clients auto-link domain-like sequences in plain-text bodies
     (CVE-2026-30913 class). Strip "." from display_name so an attacker
     can't smuggle a clickable `john.evil.com` into the verification
     email. Other glyphs are kept — the email is plain text, no HTML
     parsing happens. --}}
{!! $translator->trans('ramon-verified.email.verified.plain.body', [
'{username}'   => preg_replace('/\\./u', '', (string) $blueprint->user->display_name),
'{forumTitle}' => $settings->get('forum_title'),
]) !!}
</x-slot:body>
</x-mail::plain.information>
