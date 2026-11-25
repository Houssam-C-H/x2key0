<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\ResolveLocale::direction(app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0b0e1f">
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/brand/x2key-mark.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
