#!/bin/bash
# Installs Paywarr Stats into Paymenter.
#
#   bash install.sh                      # Paymenter in /opt/paymenter
#   bash install.sh /var/www/paymenter   # Paymenter somewhere else
#   bash install.sh ~/paymenter          # Docker: the folder with Paymenter's
#                                        # docker-compose.yml and extensions/
#
# Safe to run again after updating this repository.

set -euo pipefail

PAYMENTER="${1:-/opt/paymenter}"
PAYMENTER="${PAYMENTER%/}"
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/extensions/Others/PaywarrStats"
EXTENSIONS="$PAYMENTER/extensions"
TARGET="$EXTENSIONS/Others/PaywarrStats"

if [ ! -f "$SOURCE/PaywarrStats.php" ]; then
  echo "Cannot find the extension at $SOURCE" >&2
  exit 1
fi
if [ ! -d "$EXTENSIONS/Others" ]; then
  echo "No extensions/Others folder in $PAYMENTER." >&2
  echo "Give the folder that holds Paymenter's extensions/ folder: bash install.sh /path/to/paymenter" >&2
  exit 1
fi
if [ ! -d "$EXTENSIONS/Servers/Wizarr" ]; then
  echo "Note: Paywarr (extensions/Servers/Wizarr) is not installed. Paywarr Stats needs it to know" >&2
  echo "which media server users belong to which customer: https://github.com/Lolles1911/Paywarr" >&2
fi

rm -rf "$TARGET"
mkdir -p "$TARGET"
cp -r "$SOURCE"/. "$TARGET"/

# Same owner as the extensions Paymenter put there itself.
chown -R --reference="$EXTENSIONS/Others" "$TARGET"
chmod -R 755 "$TARGET"

echo "Installed to $TARGET."

if [ -f "$PAYMENTER/artisan" ] && command -v php >/dev/null 2>&1; then
  # As the user that owns storage/ (usually www-data): run as root, artisan can
  # leave root-owned files behind that the web server then cannot write.
  OWNER="$(stat -c %U "$PAYMENTER/storage")"
  RELOAD="cd '$PAYMENTER' && php artisan optimize:clear >/dev/null"
  if { [ "$(id -un)" = "$OWNER" ] && bash -c "$RELOAD"; } || su -s /bin/bash "$OWNER" -c "$RELOAD"; then
    echo "Paymenter's caches cleared."
  else
    echo "Could not clear Paymenter's caches. Run:" >&2
    echo "  su -s /bin/bash $OWNER -c \"cd $PAYMENTER && php artisan optimize:clear\"" >&2
  fi
else
  echo
  echo "No artisan here, so this is probably Docker. Restart Paymenter, from the folder with its docker-compose.yml:"
  echo "  docker compose restart paymenter"
fi
echo "First time? Admin -> Extensions -> Available Extensions -> Paywarr Stats -> Install (see the README)."
