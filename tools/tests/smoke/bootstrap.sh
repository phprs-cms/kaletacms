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
run_job() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo Kaleta\Core\Scheduler::jobs()[$argv[1]][3]($app, "cron");' -- "$1"); }
# setting_plain <key>: a setting as the site reads it – 3.39 (N106): a secret setting is stored sealed with the key in config.php
# mcp_call <token> <tool> <arguments as JSON> [site]: one MCP tools/call. 3.39 (N105): a tool that e-mails outside the team or
# erases for good first answers with a confirmation code – the call is repeated once with it, so a section tests what the
# tool does; 3060-… tests the two-step confirmation itself
mcp_call() {
  local out code
  out=$(curl -s -X POST "${4:-$B}/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}")
  case "$out" in *confirmation_required*)
    code=$(printf '%s' "$out" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true)["confirmation"] ?? "";')
    [ -n "$code" ] && out=$(curl -s -X POST "${4:-$B}/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "$(php -r 'echo json_encode(["jsonrpc" => "2.0", "id" => 1, "method" => "tools/call", "params" => ["name" => $argv[1], "arguments" => ((array) json_decode($argv[2], true)) + ["confirmation" => $argv[3]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' -- "$2" "$3" "$code")") ;;
  esac
  printf '%s' "$out"
}
# totp_plain <idu>: the TOTP seed of an account as the sign-in reads it (3.39, N106: stored sealed)
totp_plain() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\Vault::openSecret($app->settings(), "totp", (string) $app->db()->value("SELECT totp_tajemstvi FROM {uzivatele} WHERE idu = ?", [(int) $argv[1]]));' -- "$1"); }
setting_plain() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; echo (new Kaleta\Core\App(require "config.php"))->settings()->get($argv[1]);' -- "$1"); }

# T4 (Q4): the light background jobs run after a response, at most once a minute (Notifications::runInBackground, the clock
# notification_check) – a slow run gets one more pass than a fast one. A check that compares or counts what they would
# write first holds them:
#   background_jobs hold    – no pass starts until resume (their clock is set far ahead; cron and run_job still run)
#   background_jobs resume  – as if a pass had just run: the next one comes a minute later
#   background_jobs due     – the next response runs a pass
#   background_jobs settle  – waits until a pass that already started has finished: a static file is answered only after
#                             the single-worker test server is done with the previous request, background work included,
#                             and the scheduler's lock (Scheduler::lockName) is then free
background_jobs() {
  local at
  case "$1" in
    hold) at=4102444800 ;; # 2100-01-01
    resume) at=$(date +%s) ;;
    due) at=0 ;;
    settle)
      curl -s -o /dev/null "$B/image/web.css" || true # a static file: no app, so it starts no pass of its own
      local i; for i in $(seq 1 100); do
        [ "$(sq "SELECT IS_FREE_LOCK('$(background_jobs_lock)')")" = 1 ] && return 0; sleep 0.1
      done; echo "  CHYBA  background_jobs settle: the scheduler still runs after 10 s"; ERRORS=$((ERRORS+1)); return 0 ;;
    *) echo "background_jobs hold|resume|due|settle" >&2; return 1 ;;
  esac
  sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('notification_check', '$at') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
}
background_jobs_lock() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; echo Kaleta\Core\Scheduler::lockName((new Kaleta\Core\App(require "config.php"))->db());'); }
# T4 (Q10): a second site of a section (the console, the site to move to …) is a test copy too – its config.php defines
# KALETA_TEST_SEAMS, without which Core\TestSeams ignores the KALETA_* variables its server was started with
test_seams() { php -r '$f = $argv[1]; $c = (string) file_get_contents($f); if (!str_contains($c, "KALETA_TEST_SEAMS")) { file_put_contents($f, preg_replace("/^<\?php/", "<?php define(\x27KALETA_TEST_SEAMS\x27, true);", $c, 1)); }' -- "$1/config.php"; }
