#!/usr/bin/env bash
# Explicit maintenance operation: review diff, rebuild, test and scan before release.
set -euo pipefail
# Read the actual pinned base rather than maintaining a second digest.
base=$(awk '$1=="FROM" && $NF=="build" {print $2}' docker/release.Dockerfile)
test -n "$base"
for target in build runtime; do
  docker run --rm --platform linux/amd64 --entrypoint sh "$base" -c '
    set -e
    apk upgrade --no-cache >/dev/null
    if [ "$1" = build ]; then apk add --no-cache $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev icu-dev unzip git >/dev/null
    else apk add --no-cache apache2 apache2-proxy freetype libjpeg-turbo libpng libwebp libzip icu-libs icu-data-full tini >/dev/null
    fi
    apk info -v | sort
  ' sh "$target" | sed -E 's/^(.*)-([0-9][^-]*-r[0-9]+)$/\1=\2/' > "deployment/apk/$target.lock"
done
