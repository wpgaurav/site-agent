#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
composer runtime
composer lint
mkdir -p dist
site_agent_stage=$(mktemp -d)
trap 'rm -rf "$site_agent_stage"' EXIT
mkdir -p "$site_agent_stage/site-agent"
for file in site-agent.php uninstall.php readme.txt LICENSE; do
  cp "$file" "$site_agent_stage/site-agent/"
done
for directory in assets includes languages runtime; do
  cp -R "$directory" "$site_agent_stage/site-agent/"
done
site_agent_version=$(sed -n 's/^ \* Version: //p' site-agent.php)
site_agent_destination="$PWD/dist/site-agent-${site_agent_version}.zip"
rm -f "$site_agent_destination"
(cd "$site_agent_stage" && zip -qr "$site_agent_destination" site-agent)
shasum -a 256 "$site_agent_destination" > "$site_agent_destination.sha256"
unzip -tq "$site_agent_destination"
echo "Built $site_agent_destination"
