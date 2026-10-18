#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
composer runtime
composer lint
mkdir -p dist
site_agent_stage=$(mktemp -d)
site_agent_package_stage=
site_agent_publish_started=false
site_agent_cleanup() {
  site_agent_status=$?
  # Finish rollback even if the terminating signal is delivered again.
  trap '' HUP INT TERM
  trap - EXIT
  if [ "$site_agent_status" -ne 0 ] && [ "$site_agent_publish_started" = true ]; then
    for site_agent_artifact in "$site_agent_destination" "$site_agent_destination.sha256"; do
      site_agent_backup="$site_agent_package_stage/previous-$(basename "$site_agent_artifact")"
      if [ -f "$site_agent_backup" ]; then
        mv -f "$site_agent_backup" "$site_agent_artifact"
      else
        rm -f "$site_agent_artifact"
      fi
    done
  fi
  rm -rf "$site_agent_stage"
  if [ -n "$site_agent_package_stage" ]; then
    rm -rf "$site_agent_package_stage"
  fi
  exit "$site_agent_status"
}
trap site_agent_cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -p "$site_agent_stage/site-agent"
for file in site-agent.php uninstall.php readme.txt LICENSE; do
  cp "$file" "$site_agent_stage/site-agent/"
done
for directory in assets includes languages runtime skills; do
  cp -R "$directory" "$site_agent_stage/site-agent/"
done
site_agent_version=$(sed -n 's/^ \* Version: //p' site-agent.php)
site_agent_public_key=$(sed -n "s/^[[:space:]]*const PUBLIC_KEY = '\(.*\)';/\1/p" includes/class-updater.php)
if [ -n "${SITE_AGENT_SIGNING_KEY:-}" ]; then
  php bin/sign-package.php "$site_agent_stage/site-agent" "$site_agent_version" "$site_agent_public_key"
elif [ -n "$site_agent_public_key" ]; then
  echo "Updater::PUBLIC_KEY is set, so installs reject unsigned updates. Export SITE_AGENT_SIGNING_KEY and build again." >&2
  exit 1
fi
site_agent_destination="$PWD/dist/site-agent-${site_agent_version}.zip"
site_agent_package_stage=$(mktemp -d "$PWD/dist/.site-agent-package.XXXXXX")
site_agent_archive="$site_agent_package_stage/site-agent.zip"
(cd "$site_agent_stage" && zip -qr "$site_agent_archive" site-agent)
unzip -tq "$site_agent_archive"
site_agent_checksum=$(shasum -a 256 "$site_agent_archive" | cut -d ' ' -f 1)
printf '%s  %s\n' "$site_agent_checksum" "$site_agent_destination" > "$site_agent_archive.sha256"
# Keep both old artifacts until the replacement archive and checksum are ready.
for site_agent_artifact in "$site_agent_destination" "$site_agent_destination.sha256"; do
  if [ -f "$site_agent_artifact" ]; then
    cp -p "$site_agent_artifact" "$site_agent_package_stage/previous-$(basename "$site_agent_artifact")"
  fi
done
site_agent_publish_started=true
mv -f "$site_agent_archive" "$site_agent_destination"
mv -f "$site_agent_archive.sha256" "$site_agent_destination.sha256"
site_agent_publish_started=false
echo "Built $site_agent_destination"
