#!/bin/bash
echo "=== Starting plugin installation script ==="

set -eo pipefail

PACKAGE_TAR="${1:-}"
PLUGIN_FILE="${2:-}"

if [ -z "$PACKAGE_TAR" ] || [ -z "$PLUGIN_FILE" ]; then
  echo "Usage: $0 <package_tar> <plugin_plg>"
  exit 2
fi

set -u

# This script is expected to run on the remote unRAID host.
mkdir -p /boot/config/plugins
if [[ ! -f /usr/local/emhttp/plugins/compose.manager/include/ProjectIdentity.php ]] && php -r '
libxml_use_internal_errors(true);
foreach (array_slice($argv, 1, 2) as $manifest) {
    if (!is_file($manifest)) continue;
    $plugin = simplexml_load_file($manifest, SimpleXMLElement::class, LIBXML_NONET);
    if ($plugin === false) exit(1);
    exit((string) $plugin["name"] === "compose.manager" && (string) $plugin["author"] === "dcflachs" ? 0 : 1);
}
exit(is_file($argv[3]) ? 0 : 1);
' /boot/config/plugins/compose.manager.plg /boot/config/plugins-removed/compose.manager.plg /usr/local/emhttp/plugins/compose.manager/php/util.php; then
  mkdir -p /boot/config/plugins/compose.manager
  origin_tmp=$(mktemp /boot/config/plugins/compose.manager/.legacy-origin.XXXXXX)
  printf '%s\n' 'dcflachs' > "$origin_tmp"
  mv -f "$origin_tmp" /boot/config/plugins/compose.manager/legacy-origin
fi
echo "=== Uninstalling plugin via 'plugin remove compose.manager.plg' ==="
if plugin remove compose.manager.plg; then
  echo "=== 'plugin remove' succeeded ==="
else
  echo "=== 'plugin remove' failed, plugin may not have been removed ==="
  echo "=== Trying uninstall via 'removepkg compose.manager' as fallback ==="
  if removepkg compose.manager; then
    echo "=== 'removepkg' succeeded ==="
  else
    echo "=== 'removepkg' failed, plugin may not have been removed ==="
  fi
fi


# Remove any stale legacy package entries (compose.manager-package-*)
for pkg in /var/log/packages/compose.manager-*; do
  if [ -e "$pkg" ]; then
    echo "=== Removing stale package entry: $pkg ==="
    removepkg "$(basename "$pkg")" 2>/dev/null || true
  fi
done

cp -- "$PLUGIN_FILE" "/boot/config/plugins/$(basename "$PLUGIN_FILE")"
echo "=== Attempting to install plugin with $PLUGIN_FILE ==="
if plugin install "/boot/config/plugins/$(basename "$PLUGIN_FILE")" forced; then
  echo "=== plugin install succeeded ==="
  exit 0
else
  echo "=== plugin install failed ==="
  cp -- "$PACKAGE_TAR" "/boot/config/plugins/compose.manager/$(basename "$PACKAGE_TAR")"
  if upgradepkg --install-new "/boot/config/plugins/compose.manager/$(basename "$PACKAGE_TAR")"; then
    echo "=== upgradepkg succeeded ==="
    exit 0
  else
    echo "=== upgradepkg failed ==="
  fi
fi

exit 1