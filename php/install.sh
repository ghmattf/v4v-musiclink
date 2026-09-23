#!/usr/bin/env bash
#
# install.sh - provide the portable PHP CLI used to lint/test this project.
#
# This project has no system PHP dependency and no Composer/vendor tree:
# the plugins are linted with a self-contained static PHP CLI kept in this
# directory. Nothing else needs installing.
#
# Usage:
#   ./php/install.sh          # install/repair the PHP CLI (idempotent)
#   ./php/php -l <file>       # use it afterwards
#
# The binary and its tarball are gitignored (they are downloaded artifacts);
# this script is the tracked, reproducible way to (re)create them - run it
# whenever `./php/php` is missing or fails, e.g. after a fresh clone or a
# clean.
#
set -euo pipefail
cd "$(dirname "$0")"

PHP_VERSION="8.5.8"
BASE_URL="https://dl.static-php.dev/static-php-cli/common"

# Already present and runnable for this platform? Nothing to do.
if [ -x ./php ] && ./php -v >/dev/null 2>&1; then
	echo "PHP CLI already installed: $(./php -v | head -1)"
	exit 0
fi

# Map uname to the naming static-php-cli uses for its prebuilt archives.
os="$(uname -s)"
arch="$(uname -m)"
case "$os" in
	Linux)  os="linux" ;;
	Darwin) os="macos" ;;
	*) echo "ERROR: unsupported OS: $os" >&2; exit 1 ;;
esac
case "$arch" in
	x86_64 | amd64)      arch="x86_64" ;;
	aarch64 | arm64)     arch="aarch64" ;;
	*) echo "ERROR: unsupported architecture: $arch" >&2; exit 1 ;;
esac

tarball="php-${PHP_VERSION}-cli-${os}-${arch}.tar.gz"

# Prefer an archive already sitting next to this script (offline-friendly).
if [ ! -f "$tarball" ]; then
	echo "Downloading ${tarball} ..."
	curl -fsSL --retry 3 -o "$tarball" "${BASE_URL}/${tarball}"
fi

echo "Extracting ${tarball} ..."
rm -f ./php
tar xzf "$tarball"
chmod +x ./php

if [ ! -x ./php ] || ! ./php -v >/dev/null 2>&1; then
	echo "ERROR: PHP CLI failed to run after extraction" >&2
	echo "       (wrong build for ${os}/${arch}? try ${BASE_URL}/)" >&2
	exit 1
fi

echo "Installed: $(./php -v | head -1)"
echo "Lint with: ./php/php -l v4v-musiclink/v4v-musiclink.php"
