<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Shot Creative Agent Demo') }}</title>

    @fonts

    <!-- Reverb Client Configuration -->
    <script>
      window.__CONFIG__ = {
        reverbAppKey: "{{ config('broadcasting.connections.reverb.key', 'shot-creative-key') }}",
        reverbHost: window.location.hostname,
        reverbPort: {{ (int) config('broadcasting.connections.reverb.options.port', 8080) }},
        reverbScheme: "{{ config('broadcasting.connections.reverb.options.scheme', 'http') }}",
      };
    </script>

    <!-- Styles / Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
  </head>
  <body class="bg-zinc-950 text-zinc-100 antialiased min-h-screen">
    <div id="app"></div>
  </body>
</html>
