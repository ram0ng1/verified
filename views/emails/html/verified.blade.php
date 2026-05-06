<x-mail::html.information>
    <x-slot:body>
        {{-- The locale string contains literal <strong>/<br> tags. Output the
             translated string raw (skip $formatter->convert, which would
             escape them). Dynamic substitutions are HTML-escaped via e() to
             prevent injection through display_name / forum_title. --}}
        {!! $translator->trans('ramon-verified.email.verified.html.body', [
            '{username}'   => e($blueprint->user->display_name),
            '{forumTitle}' => e($settings->get('forum_title')),
        ]) !!}
    </x-slot:body>

    <x-slot:preview>
        {!! $translator->trans('ramon-verified.email.verified.html.preview', [
            '{forumTitle}' => e($settings->get('forum_title')),
        ]) !!}
    </x-slot:preview>
</x-mail::html.information>
