#!/usr/bin/env bash
# The pinned source archive has no compiled Canvas editor assets.
set -euo pipefail
cd "$(dirname "$0")/.."
ddev exec -d /var/www/html/web/modules/contrib/canvas env CYPRESS_INSTALL_BINARY=0 npm ci --no-audit --no-fund
ddev exec -d /var/www/html/web/modules/contrib/canvas npm run build
ddev drush cache:rebuild
