<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>{{ $title }}</title>
    @php
        $apiBasePath = config('config.docker_project_path') ?: config('config.virtual_path') ?: parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/';
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @foreach(($meta ?? []) as $name => $content)
        <meta name="{{ $name }}" content="{{ $content }}" />
    @endforeach
    @vite($vite)
</head>
<body>
    @if(isset($configScriptId))
        <script id="{{ $configScriptId }}" type="application/json">@json($config ?? [])</script>
    @endif
    <div
        @foreach(($rootAttributes ?? []) as $name => $value)
            {{ $name }}@if($value !== true)="{{ $value }}"@endif
        @endforeach
    ></div>
</body>
</html>
