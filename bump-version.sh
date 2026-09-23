#!/usr/bin/env bash
#
# bump-version.sh - bump the patch version of the V4V MusicLink plugins,
# update both version strings in the PHP file, and refresh the archive copy.
#
# Usage:
#   ./bump-version.sh            # bump both plugins
#   ./bump-version.sh standard   # bump only v4v-musiclink
#   ./bump-version.sh pro        # bump only v4v-musiclink-pro
#
set -euo pipefail
cd "$(dirname "$0")"

bump_file() {
	local file="$1" stem="$2"

	[ -f "$file" ] || { echo "ERROR: $file not found" >&2; exit 1; }

	# Read the current version from the plugin header (first match).
	local cur
	cur="$(grep -oE ' \* Version: [0-9]+\.[0-9]+\.[0-9]+' "$file" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
	[ -n "$cur" ] || { echo "ERROR: no version found in $file" >&2; exit 1; }

	# Increment patch by 1.
	local major minor patch new
	major="${cur%%.*}"
	minor="$(echo "$cur" | cut -d. -f2)"
	patch="${cur##*.}"
	new="${major}.${minor}.$((patch + 1))"

	# Update both places the version appears: plugin header + script cache-buster.
	sed -i -E "s/( \* Version: )${cur}/\1${new}/" "$file"
	sed -i -E "s/(wp_register_script\( '[^']+', false, array\(\), ')${cur}(')/\1${new}\2/" "$file"

	# Verify both got updated.
	if ! grep -q "Version: $new" "$file" || ! grep -q "array(), '$new'" "$file"; then
		echo "ERROR: version update failed in $file (expected $new)" >&2
		exit 1
	fi

	# Refresh the archive copy per build_instructions.txt.
	mkdir -p "$(dirname "$file")/archive"
	cp "$file" "$(dirname "$file")/archive/${stem}-v${new}.php"
	echo "Bumped $file: $cur -> $new (archive/${stem}-v${new}.php)"
}

target="${1:-both}"

case "$target" in
	standard|both)
		bump_file "v4v-musiclink/v4v-musiclink.php" "v4v-musiclink"
		;;
esac

case "$target" in
	pro|both)
		bump_file "v4v-musiclink-pro/v4v-musiclink-pro.php" "v4v-musiclink-pro"
		;;
	standard) ;;
	*)
		echo "Usage: $0 [standard|pro|both]" >&2
		exit 1
		;;
esac

echo "Done. Remember to update CHANGELOG.md, commit, and tag."
