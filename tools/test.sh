#!/usr/bin/env bash
# Kaleta - smoke test: a clean install into a temporary copy and a pass through the main pages.
# Runs locally and in GitHub Actions. Takes the database from environment variables:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test) DB_USER (root) DB_PASS (empty) PORT (8099) WEB (firemni | remeslo | poradenstvi)
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
for SMOKE_FILE in "${SMOKE_FILES[@]}"; do
  # shellcheck disable=SC1090
  . "$SMOKE_FILE"
done

if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
echo; [ "$ERRORS" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
