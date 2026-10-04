#!/usr/bin/env bash
#
# bump-version.sh - bump the patch version of the V4V MusicLink plugins,
# update both version strings in the PHP file, refresh the archive copy,
# and commit the plugin file(s) that changed.
#
# Usage:
#   ./bump-version.sh            # bump both plugins
#   ./bump-version.sh standard   # bump only v4v-musiclink
#   ./bump-version.sh pro        # bump only v4v-musiclink-pro
#
set -euo pipefail
cd "$(dirname "$0")"

# Versions recorded for the commit message (empty = plugin not bumped).
new_standard=""
new_pro=""

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

	# Remember what was bumped for the commit below.
	case "$stem" in
		v4v-musiclink) new_standard="$new" ;;
		v4v-musiclink-pro) new_pro="$new" ;;
	esac
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

# --- Commit just the plugin file(s) that were bumped -----------------------
# Only these paths are committed: anything else sitting in the index (or an
# unrelated edit on disk) is left alone, and the archive copies this script
# just wrote are gitignored, so they never ride along.

msg=""
paths=()

if [ -n "$new_standard" ]; then
	msg="Resolver ${new_standard}"
	paths+=("v4v-musiclink/v4v-musiclink.php")
fi
if [ -n "$new_pro" ]; then
	msg="${msg:+${msg} / }Pro ${new_pro}"
	paths+=("v4v-musiclink-pro/v4v-musiclink-pro.php")
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "Not a git work tree - 'Bump ${msg}' left uncommitted." >&2
elif git diff --quiet HEAD -- "${paths[@]}"; then
	echo "Nothing to commit for: ${msg}"
else
	git commit -m "Bump ${msg}" -- "${paths[@]}"
fi

echo "Done. Now add CHANGELOG.md entries (+ OUTSTANDING.md statuses), commit those, and tag."
