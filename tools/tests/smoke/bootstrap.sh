#!/usr/bin/env bash
# Kaleta - smoke test: a clean install into a temporary copy and a pass through the main pages.
# Runs locally and in GitHub Actions. Takes the database from environment variables:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test) DB_USER (root) DB_PASS (empty) PORT (8099) WEB (firemni | remeslo | poradenstvi)
# The database DB_NAME is DROPPED during the test and created again.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
WORK="$(mktemp -d)"; JAR="$WORK/cookies.txt"; B="http://127.0.0.1:$PORT"; ERRORS=0
cleanup() { local status=$?; if [ "$status" -ne 0 ] && [ -s "$WORK/server.log" ]; then echo "== the last lines of the site's server log (exit $status; an empty reply = the PHP process died)"; tail -n 25 "$WORK/server.log"; fi
  [ -z "${RACE_PID:-}" ] || pkill -P "$RACE_PID" 2>/dev/null || true; [ -z "${RACE2_PID:-}" ] || pkill -P "$RACE2_PID" 2>/dev/null || true
  # T1 (Q4): every background server still running – all are background jobs of this shell (the fake S3, AI, webhook,
  # old-console, probe, second-site and source servers were missing from the old list of names), so an early exit under
  # set -e leaves none listening; the fake services' logs live in $WORK, removed with it
  local pid; for pid in $(jobs -p); do kill "$pid" 2>/dev/null || true; done
  rm -rf "${WORK:?}"; }
trap cleanup EXIT
# Clocks (INV-36): the site runs on its own time zone (Europe/Prague – bootstrap.php and the Czech installer), the database
# in UTC (the MySQL service on CI; this script's sessions are forced to UTC below, so a local run fails the same way).
# Times the tests seed or compare come from site_time, never from MySQL's NOW(): the site writes date() values in its zone.
# The shell's date and every php -r here run on the site's clock too (the CI runner's default is UTC).
SITE_TZ=Europe/Prague; export TZ="$SITE_TZ"
# a background server must be started with `command php` (or `exec php` in a subshell), not through this function: $! would
# then be a bash subshell, and killing it leaves the server listening on its port (bash 5 on CI)
php() { command php -d date.timezone="$SITE_TZ" "$@"; }
site_time() { php -r 'echo date($argv[2], strtotime($argv[1]));' -- "${1:-now}" "${2:-Y-m-d H:i:s}"; } # site_time ['-1 hour'|tomorrow…] [format]
site_date() { site_time "$1" Y-m-d; }
# run_job <name>: one background job of Core\Scheduler, as cron runs it (T1: the clean-up no longer runs on admin GET requests)
run_job() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo Kaleta\Core\Scheduler::jobs()[$argv[1]][3]($app, "cron");' "$1"); }

