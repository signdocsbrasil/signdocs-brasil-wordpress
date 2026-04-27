#!/usr/bin/env bash
# Build a WordPress.org-shaped distribution zip for the SignDocs Brasil
# plugin. Same shape used by every release on the GitHub Releases page
# and (post-approval) the WP.org SVN repo.
#
# Usage:
#     bin/build-zip.sh                       # builds the working copy
#     bin/build-zip.sh --version 1.3.3       # override version label
#     bin/build-zip.sh --ref v1.3.3          # build the source at <ref>
#
# Output: signdocs-brasil-<version>.zip in the repo root.
#
# The `--ref` flag is what lets the GitHub Action backfill zips for old
# tags using the *current* version of this script (the script is
# generic — fixes to it shouldn't require retagging old releases).
#
# Environment:
#   COMPOSER_BIN  override the composer binary (defaults to `composer`)

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=""
REF=""
while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="$2"; shift 2 ;;
        --ref) REF="$2"; shift 2 ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

COMPOSER_BIN="${COMPOSER_BIN:-composer}"

STAGE="$(mktemp -d)"
trap "rm -rf '$STAGE'" EXIT

DEST="$STAGE/signdocs-brasil"
mkdir -p "$DEST"

if [ -n "$REF" ]; then
    # Materialize the tagged tree directly — bypasses the working copy
    # entirely so the build is reproducible regardless of local state.
    git archive --format=tar "$REF" | tar -xf - -C "$DEST"
else
    # Stage tracked files only. `git ls-files` skips anything in
    # .gitignore and any untracked working-copy noise.
    git ls-files | tar -cf - --files-from=- | tar -xf - -C "$DEST"
fi

if [ -z "$VERSION" ]; then
    # Pull the version directly from the staged plugin file so a --ref
    # build labels the zip with the *tagged* version, not whatever the
    # working copy currently declares.
    VERSION=$(grep -E "^\s*\*\s*Version:" "$DEST/signdocs-brasil.php" | awk -F: '{print $2}' | tr -d ' ')
fi
if [ -z "$VERSION" ]; then
    echo "could not determine plugin version" >&2
    exit 1
fi

# Files that ship in source control but have no business in the runtime
# plugin. Keeping them out shrinks the zip and avoids reviewer flags.
EXCLUDE=(
    .github
    .gitignore
    .phpunit.cache
    .wordpress-org
    DEPLOY.md
    CLAUDE.md
    composer.lock
    phpcs.xml.dist
    phpstan.neon.dist
    phpunit.xml.dist
    tests
    bin
)
for path in "${EXCLUDE[@]}"; do
    rm -rf "$DEST/$path"
done

# Production-only autoload — no phpunit/phpstan/wpcs in the zip.
( cd "$DEST" && "$COMPOSER_BIN" install --no-dev --classmap-authoritative \
    --optimize-autoloader --no-interaction --quiet )

# composer regenerates the lock during install. Drop it from the
# distributed zip — composer doesn't run on end-user installs and the
# extra ~130 KB is dead weight.
rm -f "$DEST/composer.lock"

# WP.org plugin zips have the slug as the single top-level dir.
ZIP="$PWD/signdocs-brasil-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" signdocs-brasil )

echo "$ZIP"
