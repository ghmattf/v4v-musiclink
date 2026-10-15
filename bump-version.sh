#!/usr/bin/env bash
#
# bump-version.sh - bump the patch version of the V4V MusicLinker (standard)
# plugin, update both version strings in the PHP file, refresh the archive
# copy, and commit the plugin file.
#
set -euo pipefail
cd "$(dirname "$0")"

file="wp_plugin/v4v_musiclinker.php"

[ -f "$file" ] || { echo "ERROR: $file not found" >&2; exit 1; }

# Read the current version from the plugin header (first match).
cur="$(grep -oE ' \* Version: [0-9]+\.[0-9]+\.[0-9]+' "$file" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
[ -n "$cur" ] || { echo "ERROR: no version found in $file" >&2; exit 1; }

# Increment patch by 1.
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

# Refresh the archive copy (gitignored backup history).
mkdir -p wp_plugin/archive
cp "$file" "wp_plugin/archive/v4v_musiclinker-v${new}.php"
echo "Bumped $file: $cur -> $new (wp_plugin/archive/v4v_musiclinker-v${new}.php)"

# --- Commit just the plugin file that was bumped ---------------------------
# Only this path is committed: anything else sitting in the index (or an
# unrelated edit on disk) is left alone, and the archive copy this script
# just wrote is gitignored, so it never rides along.

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "Not a git work tree - 'Bump MusicLinker ${new}' left uncommitted." >&2
elif git diff --quiet HEAD -- "$file"; then
	echo "Nothing to commit for: MusicLinker ${new}"
else
	git commit -m "Bump MusicLinker ${new}" -- "$file"
fi

echo "Now add a CHANGELOG.md entry (+ OUTSTANDING.md statuses), commit those, and tag."
