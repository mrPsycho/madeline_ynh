#!/bin/bash
# Sourced by the tests: builds (once) the container image they share and sets
# $runtime, $image and $cache. The tag is a hash of the definition below, so
# editing it triggers a rebuild.

runtime="$(command -v podman || command -v docker)"
cache="${XDG_CACHE_HOME:-$HOME/.cache}/madeline-test-composer"
mkdir -p "$cache"

containerfile='FROM docker.io/library/php:8.2-cli
RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends \
      unzip git libgmp-dev nginx libnginx-mod-http-headers-more-filter >/dev/null \
 && docker-php-ext-install -j"$(nproc)" gmp bcmath pcntl >/dev/null \
 && curl -sSLo /usr/local/bin/composer https://getcomposer.org/download/2.10.3/composer.phar \
 && chmod +x /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1'

image="localhost/madeline-test:$(printf '%s' "$containerfile" | sha256sum | cut -c1-12)"

if ! "$runtime" image inspect "$image" >/dev/null 2>&1; then
    echo "Building test image $image..." >&2
    printf '%s\n' "$containerfile" | "$runtime" build -q -t "$image" -f - . >/dev/null
fi
