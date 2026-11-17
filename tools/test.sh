#!/usr/bin/env bash
# Kaleta - smoke test: a clean install into a temporary copy and a pass through the main pages.
# Runs locally and in GitHub Actions. Takes the database from environment variables:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test) DB_USER (root) DB_PASS (empty) PORT (8099) WEB (firemni | remeslo | poradenstvi)
# Ports PORT … PORT+21 (PORT+21 = the suite's mail sink, tools/fake-smtp.php – bootstrap.sh).
# The database DB_NAME is DROPPED during the test and created again.
#
# The checks live in tools/tests/smoke: bootstrap.sh (the settings above, the clean-up trap, the first helpers), then
# every NNNN-<topic>.sh in number order – all sourced into this one shell: one site, one cookie jar, one database, so a
# section may use what an earlier one left. A new section is a new file (tools/tests/README.md).
set -euo pipefail

SMOKE_DIR="$(cd "$(dirname "$0")" && pwd)/tests/smoke"
SMOKE_FILES=()
for SMOKE_FILE in "$SMOKE_DIR"/*; do
  case "${SMOKE_FILE##*/}" in
    bootstrap.sh|README.md) ;;
    [0-9][0-9][0-9][0-9]-*.sh)
      if [ "${#SMOKE_FILES[@]}" -gt 0 ] && [ "$(basename "${SMOKE_FILES[${#SMOKE_FILES[@]}-1]}" | cut -c1-4)" = "$(echo "${SMOKE_FILE##*/}" | cut -c1-4)" ]; then
        echo "  CHYBA  tools/tests/smoke: two files with the number $(echo "${SMOKE_FILE##*/}" | cut -c1-4) – the order would not be clear"; exit 1
      fi
      SMOKE_FILES+=("$SMOKE_FILE") ;;
    *) echo "  CHYBA  tools/tests/smoke/${SMOKE_FILE##*/} is not NNNN-topic.sh – it would never run"; exit 1 ;;
  esac
done

# shellcheck source=tests/smoke/bootstrap.sh
. "$SMOKE_DIR/bootstrap.sh"
# 3.44 (suite integrity): ERRORS counts only in this shell – a check run in a subshell (a pipe, $( ) or ( )) printed
# CHYBA without counting it, and the suite once ended with exit 0. Each section's output goes to its own file first
# (sourcing with a redirection is no subshell) and every printed failure is counted: test.sh exits non-zero whenever a
# line starts with "  CHYBA  ". The cleanup trap prints the file of a section that ends the suite early (bootstrap.sh).
SECTION_N=0; exec 7>&1 # the suite's own stdout, for the cleanup trap while a section's output goes to its file
for SMOKE_FILE in "${SMOKE_FILES[@]}"; do
  SECTION_N=$((SECTION_N+1)); SECTION_OUT="$WORK/section-$SECTION_N.out"; SECTION_ERRORS=$ERRORS
  # shellcheck disable=SC1090
  . "$SMOKE_FILE" >> "$SECTION_OUT"
  mail_sink_guard "$SMOKE_FILE" >> "$SECTION_OUT" # 3.43 B: no section leaves the site on PHP's mail() (bootstrap.sh)
  cat "$SECTION_OUT"; SECTION_PRINTED=$(grep -c '^  CHYBA  ' "$SECTION_OUT" || true); SECTION_OUT=
  if [ "$SECTION_PRINTED" -gt $((ERRORS - SECTION_ERRORS)) ]; then
    echo "  CHYBA  ${SMOKE_FILE##*/}: $SECTION_PRINTED failure(s) printed, $((ERRORS - SECTION_ERRORS)) counted – a check ran in a subshell (a pipe, \$( ) or ( )), where ERRORS does not count"
    ERRORS=$((SECTION_ERRORS + SECTION_PRINTED + 1))
  fi
done

if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
echo; [ "$ERRORS" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
