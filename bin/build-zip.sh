#!/usr/bin/env bash
#
# Builds pomnex-update-check.zip for the wordpress.org directory.
#
# The zip contains a single pomnex-update-check/ folder with the files Git
# knows about (tracked, or untracked but not ignored), minus everything
# listed in .distignore. Uses `zip` when available, otherwise PHP's
# ZipArchive.
#
# Usage: bash bin/build-zip.sh

set -euo pipefail

SLUG="pomnex-update-check"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/$SLUG"
ZIP="$ROOT/$SLUG.zip"

cd "$ROOT"

# Read .distignore: skip comments and blank lines, drop the leading slash.
patterns=()
while IFS= read -r line || [ -n "$line" ]; do
	line="${line%$'\r'}"
	[[ -z "$line" || "$line" == \#* ]] && continue
	patterns+=("${line#/}")
done < .distignore

is_ignored() {
	local path="$1" pattern
	for pattern in "${patterns[@]}"; do
		# shellcheck disable=SC2053 # Patterns may contain globs.
		if [[ "$path" == $pattern || "$path" == $pattern/* ]]; then
			return 0
		fi
	done
	return 1
}

rm -rf "$BUILD" "$ZIP"
mkdir -p "$STAGE"

count=0
while IFS= read -r file; do
	[ -f "$file" ] || continue
	is_ignored "$file" && continue
	mkdir -p "$STAGE/$(dirname "$file")"
	cp "$file" "$STAGE/$file"
	count=$((count + 1))
done < <(git ls-files --cached --others --exclude-standard)

if command -v zip >/dev/null 2>&1; then
	(cd "$BUILD" && zip -qr "$ZIP" "$SLUG")
else
	php -r '
		[$script, $build, $slug, $zip] = $argv;
		$archive = new ZipArchive();
		if ( true !== $archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			fwrite( STDERR, "Cannot create $zip\n" );
			exit( 1 );
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$build/$slug", FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$relative = str_replace( "\\", "/", substr( $file->getPathname(), strlen( $build ) + 1 ) );
			$archive->addFile( $file->getPathname(), $relative );
		}
		$archive->close();
	' "$BUILD" "$SLUG" "$ZIP"
fi

rm -rf "$BUILD"

echo "Built $SLUG.zip with $count files."
