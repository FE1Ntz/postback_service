#!/bin/sh
set -eu

# Keep a generated key across restarts without rewriting the project's .env.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s storage/.app-key ]; then
        umask 077
        php artisan key:generate --show --no-ansi --no-interaction > storage/.app-key
    fi
    APP_KEY="$(cat storage/.app-key)"
    export APP_KEY
fi

php artisan migrate --force --no-interaction
exec "$@"
