#!/usr/bin/env bash
# Build the distributable plugin zips into build/. Excludes come from
# .distignore so local, GitHub release, and WordPress.org packages match.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/^ \* Version: //p' egentify-for-woocommerce.php)
SLUG=egentify-for-woocommerce

rm -rf build
mkdir -p "build/$SLUG"
rsync -a --exclude-from=.distignore --exclude=.git ./ "build/$SLUG/"
cd build
zip -rq "$SLUG.zip" "$SLUG"
cp "$SLUG.zip" "$SLUG-$VERSION.zip"
rm -rf "$SLUG"
echo "Built build/$SLUG.zip and build/$SLUG-$VERSION.zip"
