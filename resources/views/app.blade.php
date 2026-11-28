<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{--
            The play loop posts answers with fetch(), not Inertia, so it reads
            the token from here. Without this tag every answer returns 419 and
            a run dies mid-question. See resources/js/composables/useStrictRun.ts.
        --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Quiz') }}</title>

        {{-- Sans for the interface, serif for quiz titles and question
             prompts — the words are the product. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link
            href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600|instrument-serif:400"
            rel="stylesheet"
        />

        {{-- The kit's @routes directive is gone with Ziggy; this app uses
             plain URLs and Wayfinder instead. --}}
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
