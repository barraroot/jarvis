<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#050a12">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="JARVIS">

        <link rel="manifest" href="{{ route('pwa.manifest') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

        <title>{{ config('app.name', 'JARVIS') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="h-full bg-bg text-text antialiased">
        <div id="app" class="h-full"></div>
    </body>
</html>
