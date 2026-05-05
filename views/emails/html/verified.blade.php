<x-mail::html.information>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ramon-verified.email.verified.html.body', [
            '{username}'   => $blueprint->user->display_name,
            '{forumTitle}' => $settings->get('forum_title'),
        ])) !!}
    </x-slot:body>

    <x-slot:preview>
        {!! $formatter->convert($translator->trans('ramon-verified.email.verified.html.preview', [
            '{forumTitle}' => $settings->get('forum_title'),
        ])) !!}
    </x-slot:preview>
</x-mail::html.information>
