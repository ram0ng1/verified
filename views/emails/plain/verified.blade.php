<x-mail::plain.information>
<x-slot:body>
{!! $translator->trans('ramon-verified.email.verified.plain.body', [
'{username}'   => $blueprint->user->display_name,
'{forumTitle}' => $settings->get('forum_title'),
]) !!}
</x-slot:body>
</x-mail::plain.information>
