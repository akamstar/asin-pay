<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-4">
            <span class="grid size-9 place-items-center rounded-lg bg-emerald-700 text-sm font-semibold text-white" aria-hidden="true">AE</span>
            <a href="{{ route('service-requests.create') }}" class="text-lg font-semibold text-slate-900">
                Demande d'actes en ligne
            </a>
        </div>
    </header>

    <main class="mx-auto max-w-2xl px-4 py-10">
        @yield('content')
    </main>
</body>
</html>
