<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postback API — Swagger UI</title>
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
    <style>body { margin: 0; background: #fafafa; } .api-note { padding: 16px 24px; font: 15px/1.5 system-ui, sans-serif; background: #fff4d6; color: #422c00; } .api-note a { color: #234a85; }</style>
</head>
<body>
    <div class="api-note">
        <strong>Postback API</strong> · «Try it out» надсилає справжні запити й може передати подію партнеру.
        Повторіть ту саму подію, щоб перевірити дедуплікацію.
        Для захищеного <code>/events</code> введіть <code>INSPECTION_TOKEN</code> через «Authorize».
        <a href="{{ route('api.schema') }}">OpenAPI JSON</a>
    </div>
    <div id="swagger-ui" data-spec-url="{{ route('api.schema') }}"></div>
    <script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
    <script src="{{ asset('vendor/swagger-ui/swagger-initializer.js') }}"></script>
</body>
</html>
