#!/usr/bin/env bash
# Build a WordPress.org-shaped distribution zip for the SignDocs Brasil
# plugin. Same shape used by every release on the GitHub Releases page
# and (post-approval) the WP.org SVN repo.
#
# Usage:
#     bin/build-zip.sh                      # builds for the current version
#     bin/build-zip.sh --version 1.3.3      # override (used by CI)
#
# Output: signdocs-brasil-<version>.zip in the repo root.
#
# Environment:
#   COMPOSER_BIN  override the composer binary (defaults to `composer`)

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=""
while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="$2"; shift 2 ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^\s*\*\s*Version:" signdocs-brasil.php | awk -F: '{print $2}' | tr -d ' ')
fi
if [ -z "$VERSION" ]; then
    echo "could not determine plugin version" >&2
    exit 1
fi

COMPOSER_BIN="${COMPOSER_BIN:-composer}"

STAGE="$(mktemp -d)"
trap "rm -rf '$STAGE'" EXIT

DEST="$STAGE/signdocs-brasil"
mkdir -p "$DEST"

# Stage tracked files only (everything in HEAD), then prune the
# distribution-irrelevant ones. `git ls-files` skips anything in
# .gitignore and any untracked working-copy noise.
git ls-files | tar -cf - --files-from=- | tar -xf - -C "$DEST"

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
