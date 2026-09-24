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

# --- Supply-chain: verify before we extract -------------------------------
# SHA-256 pins for every tarball the OS/arch mapping below can produce. The
# hashes live HERE, in tracked source, so neither a tampered local cache nor
# swapped bytes on the download host can ever reach `tar`: verification fails
# closed for any tarball without a pin (including after a PHP_VERSION bump,
# which changes the bytes - re-pin at the same time).
# Regenerate one with:
#   curl -fsSL "${BASE_URL}/<tarball>" | sha256sum
pinned_sha256() {
	case "$1" in
		php-8.5.8-cli-linux-x86_64.tar.gz)  echo "517a18e0f0874de35669fe134eb3c52508e128a6d1da5bf890760cb800ada410" ;;
		php-8.5.8-cli-linux-aarch64.tar.gz) echo "af995ef6b3187b39932a00714dfb97e44bfa40a9243c4c29f61f19d583950753" ;;
		php-8.5.8-cli-macos-x86_64.tar.gz)  echo "05751000c6643f82e8377ed19d55bc159f84bbed46ace1fcc6f62be9866c787c" ;;
		php-8.5.8-cli-macos-aarch64.tar.gz) echo "cba9bd8b38b51ef2c7cebf02af689e4481bbd07f65edc0b63d3a43b04e0c9db7" ;;
		*) return 1 ;;
	esac
}

# GNU coreutils first, macOS shasum as the fallback - both print lowercase hex.
sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | cut -d' ' -f1
	else
		echo "ERROR: need 'sha256sum' or 'shasum' to verify the PHP tarball" >&2
		exit 1
	fi
}

verify_tarball() {
	local tarball="$1" expected actual

	if ! expected="$(pinned_sha256 "$tarball")"; then
		echo "ERROR: no SHA-256 pin exists for ${tarball}" >&2
		echo "       Refusing to extract unverified bytes. Pin it in" >&2
		echo "       pinned_sha256():  curl -fsSL \"\${BASE_URL}/${tarball}\" | sha256sum" >&2
		exit 1
	fi

	actual="$(sha256_of "$tarball")"

	if [ "$actual" != "$expected" ]; then
		echo "ERROR: SHA-256 mismatch for ${tarball}" >&2
		echo "       expected ${expected}" >&2
		echo "       got      ${actual}" >&2
		echo "       The file is corrupt or tampered with - delete it and re-run." >&2
		exit 1
	fi

	echo "Verified ${tarball} (SHA-256 matches)"
}

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

# Never hand unverified bytes to tar.
verify_tarball "$tarball"

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
