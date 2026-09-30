#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
site_agent_wp=${WP_CLI:-wp}
site_agent_pot=languages/site-agent.pot
site_agent_target=$site_agent_pot
if [ "${1:-}" = --check ]; then
  site_agent_target=$(mktemp)
  trap 'rm -f "$site_agent_target"' EXIT
fi
# WP_CLI may include a PHP executable followed by the WP-CLI phar path.
# shellcheck disable=SC2086
$site_agent_wp i18n make-pot . "$site_agent_target" --slug=site-agent --domain=site-agent --exclude=vendor,runtime,tests,bin,docs,dist,site
if [ "${1:-}" = --check ]; then
  diff <(grep -v POT-Creation-Date "$site_agent_pot") <(grep -v POT-Creation-Date "$site_agent_target")
fi
