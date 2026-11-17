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
#
# Partial runs (3.45, code-quality step 7) – to reproduce a failing section in a minute, never as a release gate:
#   ONLY=1520 tools/test.sh               only section 1520 (a four-digit pattern is a section's number)
#   ONLY=0060,quote tools/test.sh         the sections whose number or name matches any of the comma-separated patterns
#   START=1520 tools/test.sh               section 1520 and every section after it (the first number >= 1520, or a part of a name)
#   START=1500 ONLY=passkey tools/test.sh  both: the matching sections from 1500 on
# A partial run skips the syntax check (0010) and the unit tests (0030) unless it selects them, and continues from the
# snapshot of the installed site that the last run on this checkout, database and port saved after 0040 (bootstrap.sh;
# SNAPSHOT=fresh installs anew, SNAPSHOT=off saves none). Without a valid snapshot it installs and saves one. The sections
# share state: 0060 (the admin signs in) always runs, and so does every earlier section that defines a helper or a variable
# a selected one uses (tools/smoke-needs.php, listed with "+"). These only prepare the run: their failures are shown as
# "(prep)" and not counted. A section that needs what an earlier one left in the database runs with it – ONLY=0280,0300 –
# or with START=.
# Every CHYBA of a selected section still counts and fails the run; a pattern that matches no section fails it too.
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

# 3.45 (step 7): smoke_select narrows SMOKE_FILES to ONLY= / START= (see the top). The setup sections up to the install (0040)
# stay – they start the servers and define the helpers – and so does 0060 (the admin signs in), but a partial run that selects none of 0020–0040 restores the
# installed site instead of installing (SMOKE_RESTORE, decided below), runs the unit tests only when it selects 0030
# (SMOKE_UNIT) and the syntax check only when it selects 0010. Sets SMOKE_PARTIAL (0|1), SMOKE_SETUP_PICKED, SMOKE_TOTAL and
# SMOKE_PREPARE (0060 when it runs unselected).
smoke_select() {
  local only="${ONLY:-}" from="${START:-}" f name num pattern picked started=1 setup_last=40 kept=() matched=0 patterns=()
  SMOKE_TOTAL=${#SMOKE_FILES[@]}; SMOKE_PARTIAL=0; SMOKE_SETUP_PICKED=0; SMOKE_UNIT=1; SMOKE_PREPARE=
  [ -n "$only$from" ] || return 0
  SMOKE_PARTIAL=1; SMOKE_UNIT=0; [ -z "$from" ] || started=0; IFS=, read -ra patterns <<< "$only"
  for f in "${SMOKE_FILES[@]}"; do
    name="${f##*/}"; num=$((10#${name:0:4})); picked=1
    if [ "$started" = 0 ]; then
      case "$from" in
        [0-9][0-9][0-9][0-9]) [ "$num" -ge $((10#$from)) ] && started=1 ;;
        *) case "$name" in *"$from"*) started=1 ;; esac ;;
      esac
      [ "$started" = 1 ] || picked=0
    fi
    if [ "$picked" = 1 ] && [ -n "$only" ]; then
      picked=0
      for pattern in "${patterns[@]}"; do
        case "$pattern" in
          [0-9][0-9][0-9][0-9]) [ "${name:0:4}" = "$pattern" ] && picked=1 ;;
          *) case "$name" in *"$pattern"*) picked=1 ;; esac ;;
        esac
      done
    fi
    [ "$picked" = 0 ] || matched=$((matched+1))
    if [ "$num" -le "$setup_last" ]; then
      if [ "$picked" = 1 ] && [ "$num" -gt 10 ]; then SMOKE_SETUP_PICKED=1; fi
      if [ "$picked" = 1 ] && [ "$num" -eq 30 ]; then SMOKE_UNIT=1; fi
      if [ "$num" -gt 10 ] || [ "$picked" = 1 ]; then kept+=("$f"); fi
    elif [ "$picked" = 1 ]; then kept+=("$f")
    elif [ "$num" -eq 60 ]; then kept+=("$f"); SMOKE_PREPARE="${name}" # 0060 signs in and switches the features on – nearly every section needs it
    fi
  done
  if [ "$matched" = 0 ]; then echo "  CHYBA  ONLY=${only:-} START=${from:-}: no section matches – tools/tests/smoke/NNNN-<topic>.sh"; return 1; fi
  SMOKE_FILES=("${kept[@]}")
}
smoke_select || exit 1

# shellcheck source=tests/smoke/bootstrap.sh
. "$SMOKE_DIR/bootstrap.sh"
SMOKE_RESTORE=0
if [ "$SMOKE_PARTIAL" = 1 ]; then
  echo "== partial run (ONLY=${ONLY:-} START=${START:-}): ${#SMOKE_FILES[@]} of $SMOKE_TOTAL sections, the setup sections included"
  # a helper or a variable a selected section uses but a left-out section defines: that section runs too, and in turn what it
  # needs (tools/smoke-needs.php – static and approximate, like the unit runner's closure)
  SMOKE_NEEDED=$(command php "$ROOT/tools/smoke-needs.php" "$SMOKE_DIR" "${SMOKE_FILES[@]}")
  if [ -n "$SMOKE_NEEDED" ]; then
    while read -r f fns; do echo "  --     + $f (for $fns)"; SMOKE_PREPARE="$SMOKE_PREPARE $f"; done <<< "$SMOKE_NEEDED"
    SMOKE_KEEP=(); for SMOKE_FILE in "$SMOKE_DIR"/[0-9][0-9][0-9][0-9]-*.sh; do
      case " ${SMOKE_FILES[*]} " in *" $SMOKE_FILE "*) SMOKE_KEEP+=("$SMOKE_FILE"); continue ;; esac
      while read -r f fns; do [ "$f" != "${SMOKE_FILE##*/}" ] || SMOKE_KEEP+=("$SMOKE_FILE"); done <<< "$SMOKE_NEEDED"
    done; SMOKE_FILES=("${SMOKE_KEEP[@]}")
    echo "  --     ${#SMOKE_FILES[@]} sections with the ones the selected sections need"
  fi
  if [ "$SMOKE_SETUP_PICKED" = 0 ] && smoke_snapshot_valid; then SMOKE_RESTORE=1
  elif [ "$SMOKE_SETUP_PICKED" = 0 ]; then echo "  --     no snapshot of the installed site for this checkout yet (or SNAPSHOT=fresh) – installing, and saving one for the next run"
  fi
fi
# 3.44 (suite integrity): ERRORS counts only in this shell – a check run in a subshell (a pipe, $( ) or ( )) printed
# CHYBA without counting it, and the suite once ended with exit 0. Each section's output goes to its own file first
# (sourcing with a redirection is no subshell) and every printed failure is counted: test.sh exits non-zero whenever a
# line starts with "  CHYBA  ". The cleanup trap prints the file of a section that ends the suite early (bootstrap.sh).
SMOKE_DONE=0 # 3.45: the cleanup trap fails a run that ends before the last line (bootstrap.sh)
SECTION_N=0; exec 7>&1 # the suite's own stdout, for the cleanup trap while a section's output goes to its file
for SMOKE_FILE in "${SMOKE_FILES[@]}"; do
  SECTION_N=$((SECTION_N+1)); SECTION_OUT="$WORK/section-$SECTION_N.out"; SECTION_ERRORS=$ERRORS
  # shellcheck disable=SC1090
  . "$SMOKE_FILE" >> "$SECTION_OUT"
  mail_sink_guard "$SMOKE_FILE" >> "$SECTION_OUT" # 3.43 B: no section leaves the site on PHP's mail() (bootstrap.sh)
  # 3.45: a section a partial run added only for what the selected ones need (SMOKE_PREPARE) – its failures shown, not counted
  case " ${SMOKE_PREPARE:-} " in *" ${SMOKE_FILE##*/} "*)
    sed 's/^  CHYBA  /  (prep) /' "$SECTION_OUT" > "$SECTION_OUT.prep"; mv "$SECTION_OUT.prep" "$SECTION_OUT"
    [ "$ERRORS" -eq "$SECTION_ERRORS" ] || echo "  --     ${SMOKE_FILE##*/} only prepares this partial run: its $((ERRORS - SECTION_ERRORS)) failure(s), marked (prep), are not counted" >> "$SECTION_OUT"
    ERRORS=$SECTION_ERRORS ;;
  esac
  cat "$SECTION_OUT"; SECTION_PRINTED=$(grep -c '^  CHYBA  ' "$SECTION_OUT" || true); SECTION_OUT=
  if [ "$SECTION_PRINTED" -gt $((ERRORS - SECTION_ERRORS)) ]; then
    echo "  CHYBA  ${SMOKE_FILE##*/}: $SECTION_PRINTED failure(s) printed, $((ERRORS - SECTION_ERRORS)) counted – a check ran in a subshell (a pipe, \$( ) or ( )), where ERRORS does not count"
    ERRORS=$((SECTION_ERRORS + SECTION_PRINTED + 1))
  fi
done

if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
SMOKE_DONE=1
# 3.45: a partial run says so in its last line – it is no release gate
SMOKE_SCOPE=""; [ "$SMOKE_PARTIAL" = 0 ] || SMOKE_SCOPE=" (partial run: ${#SMOKE_FILES[@]} of $SMOKE_TOTAL sections – ONLY=${ONLY:-} START=${START:-})"
echo; [ "$ERRORS" -eq 0 ] && echo "VŠE V POŘÁDKU$SMOKE_SCOPE" || { echo "NALEZENO CHYB: $ERRORS$SMOKE_SCOPE"; exit 1; }
