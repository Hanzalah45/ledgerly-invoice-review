#!/bin/sh
set -e
cd /var/www/html

# Render (and similar hosts) pass the port in $PORT.
PORT="${PORT:-10000}"

if [ -z "$APP_KEY" ]; then
    export APP_KEY="$(php artisan key:generate --show --no-ansi)"
fi
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

# Fresh demo data on every start, plus the sample PDFs offered on the upload page.
php artisan docai:make-samples --no-interaction || true
php artisan docai:demo-reset --no-interaction

# Daily reset (03:00) needs the scheduler; run it in the background.
php artisan schedule:work --no-interaction >/dev/null 2>&1 &

exec php artisan serve --host=0.0.0.0 --port="$PORT" --no-reload
