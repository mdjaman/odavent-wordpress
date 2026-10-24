#!/usr/bin/env sh
# Regenerates languages/odavent-events.pot from the PHP, JS and block.json sources with WP-CLI's
# `wp i18n make-pot`, run in the official wordpress:cli Docker image (no local WP-CLI needed).
# Usage: bin/make-pot.sh
#
# The Project-Id-Version header ("Odavent Events X.Y.Z") is read from the plugin header's Version:, never
# typed by hand: run this after bumping the version and BEFORE tagging a release (README > Releasing).
#
# After running it, update languages/odavent-events-fr_FR.po (new msgids need a translation) and
# check the result with: php bin/check-translations.php
set -e
cd "$(dirname "$0")/.."

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' odavent-events.php | head -n 1)"
if [ -z "$VERSION" ]; then
  echo "Cannot read the Version: header of odavent-events.php." >&2
  exit 1
fi

# The cli image runs as www-data (uid 33); run as the invoking user so the file is ours.
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/plugin -w /plugin wordpress:cli \
  i18n make-pot . languages/odavent-events.pot \
  --slug=odavent-events \
  --domain=odavent-events \
  --package-name="Odavent Events" \
  --exclude=vendor,tests,bin,docker,docs,dist,.github,.superpowers,node_modules \
  --headers="{\"Report-Msgid-Bugs-To\":\"https://github.com/mdjaman/odavent-wordpress/issues\",\"Project-Id-Version\":\"Odavent Events ${VERSION}\"}" \
  --skip-audit
