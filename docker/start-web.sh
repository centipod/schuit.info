#!/bin/sh
# Both daemons run as uid 33. If either fails, terminate the other and let
# Compose restart the container. tini reaps descendants and forwards signals.
set -u
php-fpm -F &
fpm_pid=$!
httpd -DFOREGROUND &
http_pid=$!
stop() {
    kill -TERM "$fpm_pid" "$http_pid" 2>/dev/null || true
    wait "$fpm_pid" "$http_pid" 2>/dev/null || true
}
trap 'stop; exit 0' TERM INT
while kill -0 "$fpm_pid" 2>/dev/null && kill -0 "$http_pid" 2>/dev/null; do
    sleep 1
done
stop
exit 1
