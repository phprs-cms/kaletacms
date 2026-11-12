echo "== 2.2: connection access, change log, instructions, prompts, settings over MCP"
expect "3.11 N311-3: an OAuth connection approved without a choice gets drafts only, the default of the consent page" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(DISTINCT access) FROM ka_api_tokeny WHERE klient = '$CLIENT'")" "drafts"
REDIRECT=$(oauth_allow "response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=drafts" -d povolit=1 -d access=drafts)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
REFRESH_DRAFTS=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_DRAFTS" -d "client_id=$CLIENT"
OAUTH_DRAFTS=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
expect "drafts only chosen on the consent screen stays after a token refresh" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT access FROM ka_api_tokeny WHERE otisk = SHA2('$OAUTH_DRAFTS', 256)")" "drafts"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $OAUTH_DRAFTS" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
contains -q '"name":"save_build"' "$WORK/response" && ! contains -q '"name":"publish_build"' "$WORK/response" && ! contains -q '"name":"update_settings"' "$WORK/response" && echo "  ok     a drafts-only connection lists only reads and draft tools" || { echo "  CHYBA  tools/list for drafts"; ERRORS=$((ERRORS+1)); }
# personal tokens with limited access (My account)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; ACCOUNT_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude read" -d access=read --data-urlencode "soucasne=$PASSWORD"
READ_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude drafts" -d access=drafts --data-urlencode "soucasne=$PASSWORD"
DRAFT_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
expect "tokens from My account keep the chosen access" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(access ORDER BY nazev) FROM ka_api_tokeny WHERE nazev IN ('Claude read', 'Claude drafts')")" "drafts,read"
mcp_text() { php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' -- "$WORK/response" > "$WORK/text"; } # the tool result itself
mcp_as() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}"; }
mcp_as "$READ_TOKEN" create_page '{"title":"From a read-only connection"}' > "$WORK/response"
contains -q 'can only read the site' "$WORK/response" && expect "a read-only connection changes nothing" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'From a read-only connection'")" 0 || { echo "  CHYBA  read-only connection wrote"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$READ_TOKEN" get_page '{"id":1}' > "$WORK/response"; contains -q '"isError":true' "$WORK/response" && { echo "  CHYBA  a read-only connection cannot read"; ERRORS=$((ERRORS+1)); } || echo "  ok     a read-only connection reads"
# N35-2: text from a drafts-only connection never goes live by itself – a later unrelated edit by a publisher keeps the page hidden
mcp create_page '{"title":"Waiting for drafts","slug":"waiting-drafts","visible":true}' > /dev/null
WAIT_DR=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'waiting-drafts'")
mcp_as "$DRAFT_TOKEN" update_page "{\"id\":${WAIT_DR:-0},\"text\":\"<p>Text from a drafts-only connection</p>\"}" > /dev/null
mcp update_page "{\"id\":${WAIT_DR:-0},\"description\":\"Only the description changes\"}" > /dev/null
expect "3.5 N35-2: drafts-only text in a waiting page stays hidden after a publisher's unrelated edit" \
  "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', text LIKE '%drafts-only%') FROM ka_stranky WHERE ids = ${WAIT_DR:-0}")" "0/0/1"
mcp_as "$DRAFT_TOKEN" create_page '{"title":"Drafted by Claude","visible":true}' > "$WORK/response"
mcp_text; DRAFT_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://' || true)
expect "a drafts-only connection creates a page, but hidden" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}'")" 0
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft\"}}]}]}}" > "$WORK/response"
contains -q 'Publishing needs' "$WORK/response" && expect "publishing over a drafts-only connection is refused before anything is saved" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COALESCE(stavba_koncept, 'none') FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}'")" "none" || { echo "  CHYBA  save_build publish over drafts"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft\"}}]}]}}" > "$WORK/response"
mcp_text; contains -q '"status":"draft' "$WORK/text" && echo "  ok     a drafts-only connection saves a draft build" || { echo "  CHYBA  drafts: save_build"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" update_settings '{"settings":{"site_name":"Hijacked"}}' > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     a drafts-only connection does not change settings" || { echo "  CHYBA  drafts: update_settings"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 2.5.1: an administrator's drafts-only connection must not reach the administrator's browser through a draft preview
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"custom_html\",\"content\":{\"code\":\"<p>drafted-code</p>\"}}]}]}}" > /dev/null
expect "a drafts-only connection cannot insert Custom HTML" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}' AND stavba_koncept LIKE '%drafted-code%'")" 0
# 3.15 (N10): a file a drafts-only connection uploads waits outside the web root (Core\MediaStaging) – visible to signed-in users
# and draft previews, public once content using it is published, or approved in Media; nobody approves it: gone after 30 days
STAGE_PNG=$(php -r '$i = imagecreatetruecolor(1400, 900); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 200)); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
stage_upload() { mcp_as "$DRAFT_TOKEN" upload_file "{\"filename\":\"$1\",\"data\":\"$2\"}" > "$WORK/response"; mcp_text; grep -o "media/[0-9]*/[0-9]*/${1%.*}-[a-f0-9]*\.${1##*.}" "$WORK/text" | head -1 || true; }
anon_code() { curl -s -o /dev/null -w '%{http_code}' "$B/$1"; }
STAGED=$(stage_upload staged-photo.png "$STAGE_PNG")
STAGED_THUMB="${STAGED%.png}-nahled.png"
expect "3.15 N10: a drafts-only upload is staged – the answer says so, nothing in media/, the files in storage/staging/, the row marked" \
  "$(grep -c '"staged":true' "$WORK/text")|$(grep -c 'goes public with the draft' "$WORK/text")|$([ -n "$STAGED" ] && ! ls "$WORK/web/${STAGED%.png}"* > /dev/null 2>&1 && echo none)|$([ -f "$WORK/web/storage/staging/${STAGED#media/}" ] && [ -f "$WORK/web/storage/staging/${STAGED_THUMB#media/}" ] && echo staged)|$(sq "SELECT staged_at IS NOT NULL FROM ka_media WHERE obr_poloha = '$STAGED'")" \
  "1|1|none|staged|1"
expect "3.15 N10: anonymous visitors get 404 for a staged file and its variants, also the storage/ path itself" \
  "$(anon_code "$STAGED")|$(anon_code "$STAGED_THUMB")|$(anon_code "$STAGED.webp")|$(anon_code "storage/staging/${STAGED#media/}")" "404|404|404|403"
STAGE_HEAD=$(curl -s -b "$JAR" -D - -o "$WORK/staged.png" "$B/$STAGED" | tr -d '\r')
expect "3.15 N10: a signed-in user gets the staged file (200, the image, nosniff, never cached)" \
  "$(echo "$STAGE_HEAD" | head -1 | cut -d' ' -f2)|$(echo "$STAGE_HEAD" | grep -ic '^content-type: image/png')|$(echo "$STAGE_HEAD" | grep -ic '^x-content-type-options: nosniff')|$(echo "$STAGE_HEAD" | grep -ic '^cache-control: private, no-store')|$(php -r 'echo getimagesize($argv[1])[0] ?? 0;' -- "$WORK/staged.png")" \
  "200|1|1|1|1400"
[ ! -f "$WORK/web/storage/staging/${STAGED#media/}.webp" ] || expect "3.15: a browser that accepts WebP gets the staged WebP sibling" "$(curl -s -b "$JAR" -H 'Accept: image/webp,*/*' -D - -o /dev/null "$B/$STAGED" | tr -d '\r' | grep -i '^content-type' | cut -d' ' -f2)" "image/webp"
STAGED_SVG=$(stage_upload staged-logo.svg "$(printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>' | base64 | tr -d '\n')")
STAGED_PDF=$(stage_upload staged-menu.pdf "$(printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF\n' | base64 | tr -d '\n')")
expect "3.15: a staged SVG keeps the sandbox CSP and a staged document its nosniff, both only for signed-in users" \
  "$(curl -s -b "$JAR" -D - -o /dev/null "$B/$STAGED_SVG" | tr -d '\r' | grep -ic '^content-security-policy:.*sandbox')|$(curl -s -b "$JAR" -D - -o /dev/null "$B/$STAGED_PDF" | tr -d '\r' | grep -ic '^x-content-type-options: nosniff')|$(anon_code "$STAGED_SVG")|$(anon_code "$STAGED_PDF")" "1|1|404|404"
mcp list_media '{"search":"staged-photo"}' > "$WORK/response"; mcp_text
expect "3.15: list_media marks a staged file (staged: true)" "$(grep -c '"staged":true' "$WORK/text")" "1"
# a draft that uses it: the signed preview shows it (the preview sets a cookie for /media/), a visitor without it gets 404
mcp create_page '{"title":"Staged image page","slug":"staged-image-page","visible":true}' > /dev/null
STAGE_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'staged-image-page'")
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${STAGE_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Staged image\"}},{\"type\":\"image\",\"content\":{\"src\":\"$STAGED\",\"alt\":\"A blue area\"}}]}]}}" > /dev/null
mcp_as "$DRAFT_TOKEN" preview_link "{\"id\":${STAGE_PAGE:-0}}" > "$WORK/response"; mcp_text
STAGE_PREVIEW=$(grep -o 'http[^"]*preview_key=[0-9a-fk.]*' "$WORK/text" | head -1 || true)
PREVIEW_JAR="$WORK/preview-cookies.txt"
curl -s -c "$PREVIEW_JAR" -o "$WORK/response" "$STAGE_PREVIEW"
expect "3.15 N10: the signed preview of the draft shows the staged image (200 with the preview's cookie, 404 without it)" \
  "$(grep -c "$(basename "$STAGED" .png)" "$WORK/response" | sed 's/^[1-9][0-9]*$/shown/')|$(curl -s -b "$PREVIEW_JAR" -o /dev/null -w '%{http_code}' "$B/$STAGED")|$(anon_code "$STAGED")|$(grep -c 'ka_nahled_media' "$PREVIEW_JAR")" "shown|200|404|1"
# publishing the page by someone who may publish moves the file to media/ in the same request
mcp publish_build "{\"id\":${STAGE_PAGE:-0}}" > /dev/null
expect "3.15 N10: publishing a page that uses a staged file makes it public at once (media/, anonymous 200, the row public, staging empty)" \
  "$([ -f "$WORK/web/$STAGED" ] && [ -f "$WORK/web/$STAGED_THUMB" ] && echo media)|$(anon_code "$STAGED")|$(sq "SELECT staged_at IS NULL FROM ka_media WHERE obr_poloha = '$STAGED'")|$(ls "$WORK/web/storage/staging/${STAGED%.png}"* 2>/dev/null | wc -l | tr -d ' ')" \
  "media|200|1|0"
# a full-access upload is public at once, as before
mcp upload_file "{\"filename\":\"public-photo.png\",\"data\":\"$PNG\"}" > "$WORK/response"; mcp_text
PUBLIC_UP=$(grep -o 'media/[0-9]*/[0-9]*/public-photo-[a-f0-9]*\.png' "$WORK/text" | head -1 || true)
expect "3.15: a full-access upload goes straight to media/ (no staged flag, anonymous 200)" "$(grep -c '"staged"' "$WORK/text")|$(anon_code "$PUBLIC_UP")|$(sq "SELECT staged_at IS NULL FROM ka_media WHERE obr_poloha = '$PUBLIC_UP'")" "0|200|1"
# content published some other way still shows it: an anonymous request finds it in published content and publishes it – asked
# at most every 5 minutes per file (N315-5), so the 404 the PDF got a moment ago is remembered until then
sq "UPDATE ka_stranky SET text = CONCAT(text, '<p><a href=\"/$STAGED_PDF\">Menu</a></p>') WHERE ids = ${STAGE_PAGE:-0}" > /dev/null
STAGE_REMEMBERED=$(anon_code "$STAGED_PDF")
age_staging_checks() { php -r 'foreach (glob($argv[1] . "/media-staging-*") ?: [] as $f) { touch($f, time() - 600); }' -- "$WORK/web/storage/cache"; }
age_staging_checks
expect "3.15: a staged file published content refers to goes public on an anonymous request – the answer of the last 5 minutes is remembered first (N315-5)" \
  "$STAGE_REMEMBERED|$(anon_code "$STAGED_PDF")|$([ -f "$WORK/web/$STAGED_PDF" ] && echo media)|$(sq "SELECT staged_at IS NULL FROM ka_media WHERE obr_poloha = '$STAGED_PDF'")" "404|200|media|1"
# Media → Waiting for approval: the list, Publish file and Delete
check "3.15: Media lists the files waiting for approval" 200 "/admin.php?module=media&staged=1" 'staged-logo'
STAGE_CSRF=$(csrf); STAGE_SVG_ID=$(sq "SELECT ido FROM ka_media WHERE obr_poloha = '$STAGED_SVG'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=approve" -d "_csrf=$STAGE_CSRF" -d "ido=$STAGE_SVG_ID"
expect "3.15: Publish file in Media makes a staged file public" "$(anon_code "$STAGED_SVG")|$(sq "SELECT staged_at IS NULL FROM ka_media WHERE ido = $STAGE_SVG_ID")" "200|1"
STAGED_DEL=$(stage_upload staged-delete.png "$PNG"); STAGE_DEL_ID=$(sq "SELECT ido FROM ka_media WHERE obr_poloha = '$STAGED_DEL'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=discard" -d "_csrf=$STAGE_CSRF" -d "ido=${STAGE_DEL_ID:-0}"
expect "3.15: Delete in Waiting for approval removes the staged file and its row" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = ${STAGE_DEL_ID:-0}")|$(ls "$WORK/web/storage/staging/${STAGED_DEL%.png}"* 2>/dev/null | wc -l | tr -d ' ')" "0|0"
# N315-1: a shared class never makes a waiting file public by itself – a drafts-only connection creates new classes live; a new
# class whose name a published page already lists would change that page at once, so it waits in the draft look
mcp create_page '{"title":"Class page 315","slug":"class-page-315","visible":true}' > /dev/null
CLASS_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'class-page-315'")
sq "UPDATE ka_stranky SET stavba = '{\"v\":1,\"deti\":[{\"id\":\"c315a\",\"typ\":\"sekce\",\"tridy\":[\"hero-old315\"],\"deti\":[]}]}', zobrazit = 1, show_on_publish = 0 WHERE ids = ${CLASS_PAGE:-0}" > /dev/null
mcp_as "$DRAFT_TOKEN" save_classes '{"css":".hero-old315 { padding: 3rem } .fresh315 { padding: 1rem }"}' > "$WORK/response"
expect "3.15 N315-1: a drafts-only connection's new class that a published page already lists waits in the draft look; one no published build lists is live" \
  "$(sq "SELECT COUNT(*) FROM ka_tridy WHERE nazev = 'hero-old315'")|$(sq "SELECT hodnota LIKE '%hero-old315%' FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT COUNT(*) FROM ka_tridy WHERE nazev = 'fresh315'")" "0|1|1"
# a live class with a waiting background image that a published page lists: the background job, a change in Pages, a write call of
# a full connection and an anonymous request leave it waiting – only a person's publish (or Media) makes it public
STAGED_BG=$(stage_upload staged-bg.png "$PNG")
sq "INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('bg315', '{\"zaklad\":{\"obrazek_pozadi\":\"$STAGED_BG\"}}', '', '$(site_time)');
  UPDATE ka_stranky SET stavba = REPLACE(stavba, '\"hero-old315\"', '\"hero-old315\",\"bg315\"') WHERE ids = ${CLASS_PAGE:-0}; DELETE FROM ka_jobs WHERE name = 'media_staging'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=list" -d "_csrf=$STAGE_CSRF"
mcp update_page "{\"id\":${CLASS_PAGE:-0},\"title\":\"Class page 315 again\"}" > /dev/null
anon_code "$STAGED_BG" > /dev/null; age_staging_checks
expect "3.15 N315-1: a class's waiting background stays waiting after the job, a change in Pages, a full connection's write and an anonymous request" \
  "$(anon_code "$STAGED_BG")|$(sq "SELECT staged_at IS NOT NULL FROM ka_media WHERE obr_poloha = '$STAGED_BG'")|$(sq "SELECT stavba LIKE '%bg315%' FROM ka_stranky WHERE ids = ${CLASS_PAGE:-0}")" "404|1|1"
mcp save_build "{\"id\":${CLASS_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"classes\":[\"bg315\"],\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"New hero\"}}]}]}}" > /dev/null
mcp publish_build "{\"id\":${CLASS_PAGE:-0}}" > /dev/null
expect "3.15 N315-1: publishing a build that uses the class (a person or a full connection) makes its waiting background public" \
  "$(anon_code "$STAGED_BG")|$(sq "SELECT staged_at IS NULL FROM ka_media WHERE obr_poloha = '$STAGED_BG'")" "200|1"
# N315-4: every waiting file is looked at, not only the oldest 300, and every public place – here a booking service's description
php -r 'echo "INSERT INTO ka_media (obr_poloha, datum, staged_at) VALUES ", implode(", ", array_map(fn (int $n): string => "(\x27media/2026/01/filler315-" . sprintf("%06d", $n) . ".png\x27, NOW(), NOW())", range(1, 310))), ";";' > "$WORK/filler.sql"
"${MYSQL[@]}" "$DB_NAME" < "$WORK/filler.sql"
STAGED_SVC=$(stage_upload staged-service.png "$PNG")
sq "INSERT INTO ka_booking_services (name, description, active) VALUES ('Service 315', 'Our room: /$STAGED_SVC', 1); DELETE FROM ka_jobs WHERE name = 'media_staging'" > /dev/null
SVC_BEHIND=$(sq "SELECT COUNT(*) FROM ka_media WHERE staged_at IS NOT NULL AND ido < (SELECT ido FROM ka_media WHERE obr_poloha = '$STAGED_SVC')")
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.15 N315-4: the job reaches a waiting file behind 310 others and finds it in a booking service's description" \
  "$([ "${SVC_BEHIND:-0}" -gt 300 ] && echo behind)|$(anon_code "$STAGED_SVC")|$(sq "SELECT staged_at IS NULL FROM ka_media WHERE obr_poloha = '$STAGED_SVC'")" "behind|200|1"
[ "$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE 'media/2026/01/filler315-%'")" = 310 ] || { echo "  CHYBA  3.15 N315-4: the filler rows"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_media WHERE obr_poloha LIKE 'media/2026/01/filler315-%'; DELETE FROM ka_booking_services WHERE name = 'Service 315'" > /dev/null
# nobody approved it for 30 days: the background job deletes it – unless live content uses it (N315-4): a live class keeps it
# waiting for a person, never deleted
STAGED_OLD=$(stage_upload staged-old.png "$PNG")
STAGED_KEEP=$(stage_upload staged-keep.png "$PNG")
sq "INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('keep315', '{\"zaklad\":{\"obrazek_pozadi\":\"$STAGED_KEEP\"}}', '', '$(site_time)');
  UPDATE ka_media SET staged_at = '$(site_time '-31 days')' WHERE obr_poloha IN ('$STAGED_OLD', '$STAGED_KEEP'); DELETE FROM ka_jobs WHERE name = 'media_staging'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.15: the background job deletes a staged file nobody approved within 30 days (row and files), and keeps one a live class uses (N315-4)" \
  "$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha = '$STAGED_OLD'")|$(ls "$WORK/web/storage/staging/${STAGED_OLD%.png}"* 2>/dev/null | wc -l | tr -d ' ')|$(sq "SELECT last_error FROM ka_jobs WHERE name = 'media_staging'")|$(sq "SELECT staged_at IS NOT NULL FROM ka_media WHERE obr_poloha = '$STAGED_KEEP'")|$([ -f "$WORK/web/storage/staging/${STAGED_KEEP#media/}" ] && echo kept)" "0|0||1|kept"
check "3.15 N315-4: Media says why a file past 30 days is still waiting" 200 "/admin.php?module=media&staged=1" 'Ponechán po 30 dnech'
sq "DELETE FROM ka_tridy WHERE nazev IN ('keep315', 'bg315', 'fresh315')" > /dev/null
# 3.7 N37-1: a prefixed attribute is one literal attribute (xml:onerror is not onerror) – on PHP 8.3 the compat parser bound the
# prefix, the sanitizer missed the attribute and the serializer wrote it back as a live onerror
mcp_as "$DRAFT_TOKEN" create_news '{"title":"N37-1 prefixed attributes","category":"'"$CATEGORY"'","text":"<p><img xml:onerror=alert(1) src=/media/x.png><a xml:href=javascript:alert(2) href=/ok>a</a> <a xlink:href=javascript:alert(3)>b</a> <span XML:ONCLICK=alert(4) x:onmouseover=alert(5) xmlns:x=y>c</span></p><svg><a xlink:href=javascript:alert(6)><text>d</text></a></svg><math><mi xml:onclick=alert(7)>e</mi></math>"}' > /dev/null
expect "N37-1: create_news over a drafts-only connection with xml:/xlink:/x: attributes stores nothing executable, the safe href stays" \
  "$(sq "SELECT CONCAT(text REGEXP '[[:space:]]on[a-z]+[[:space:]]*=', '/', text LIKE '%javascript%', '/', text LIKE '%<a href=\"/ok\">a</a>%', '/', text LIKE '%src=\"/media/x.png\"%') FROM ka_novinky WHERE titulek = 'N37-1 prefixed attributes'")" "0/0/1/1"
# 3.8 (N37-3 follow-up): markup over a limit of Core\HtmlLimits is refused before any parser sees it, on every PHP version – a
# Custom HTML element nested 3,000 deep is stored empty with the limit and the measured value among the errors, build_from_html,
# create_news, an SVG upload and the admin news form refuse it and store nothing
LIMIT_DEEP=$(printf '<div>%.0s' $(seq 1 3000))
mcp create_page '{"title":"Limit MCP","slug":"limit-mcp"}' > /dev/null
LIMIT_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'limit-mcp'")
mcp save_build "{\"id\":${LIMIT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"custom_html\",\"content\":{\"code\":\"$LIMIT_DEEP\"}},{\"type\":\"heading\",\"content\":{\"text\":\"Kept by the limit\"}}]}]}}" > "$WORK/response"
contains -q 'The markup is nested 3000 levels deep; the limit is 512 – the field was left empty.' "$WORK/response" \
  && expect "3.8: save_build with Custom HTML nested 3,000 deep returns the limit, the element stays empty, the rest is saved" \
    "$(sq "SELECT CONCAT(stavba_koncept LIKE '%<div><div>%', '/', stavba_koncept LIKE '%Kept by the limit%') FROM ka_stranky WHERE ids = ${LIMIT_PAGE:-0}")" "0/1" \
  || { echo "  CHYBA  3.8: save_build over the nesting limit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp build_from_html "{\"id\":${LIMIT_PAGE:-0},\"html\":\"<section>$LIMIT_DEEP</section>\"}" > "$WORK/response"
contains -q '"isError":true' "$WORK/response" && contains -q 'The markup is nested 3,001 levels deep; the limit is 512.' "$WORK/response" \
  && expect "3.8: build_from_html over the nesting limit is refused with the limit, the draft stays as it was" "$(sq "SELECT stavba_koncept LIKE '%Kept by the limit%' FROM ka_stranky WHERE ids = ${LIMIT_PAGE:-0}")" 1 \
  || { echo "  CHYBA  3.8: build_from_html over the nesting limit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_news "{\"title\":\"Limit news\",\"category\":\"$CATEGORY\",\"text\":\"$LIMIT_DEEP\"}" > "$WORK/response"
mcp_as "$DRAFT_TOKEN" create_news "{\"title\":\"Limit news draft\",\"category\":\"$CATEGORY\",\"text\":\"<blockquote>$LIMIT_DEEP</blockquote>\"}" > "$WORK/response2"
contains -q 'text: The markup is nested 3,000 levels deep; the limit is 512.' "$WORK/response" && contains -q 'text: The markup is nested 3,001 levels deep' "$WORK/response2" \
  && expect "3.8: create_news over the limit is refused on a full and a drafts-only connection, nothing is stored" "$(sq "SELECT COUNT(*) FROM ka_novinky WHERE titulek LIKE 'Limit news%'")" 0 \
  || { echo "  CHYBA  3.8: create_news over the nesting limit"; head -c 300 "$WORK/response"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
LIMIT_SVG=$(php -r 'echo base64_encode("<svg xmlns=\"http://www.w3.org/2000/svg\">" . str_repeat("<g>", 300) . str_repeat("</g>", 300) . "</svg>");')
mcp upload_file "{\"filename\":\"deep.svg\",\"data\":\"$LIMIT_SVG\"}" > "$WORK/response"
contains -q '"isError":true' "$WORK/response" && contains -q 'The markup is nested 301 levels deep; the limit is 256.' "$WORK/response" \
  && expect "3.8: an SVG nested 300 deep is refused with the limit, nothing in Media" "$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE '%/deep-%'")" 0 \
  || { echo "  CHYBA  3.8: SVG upload over the nesting limit"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$(csrf)" -d idc=0 -d "titulek=Limit form" -d "tema=$(sq "SELECT MIN(idt) FROM ka_kategorie")" --data-urlencode "text=$LIMIT_DEEP")
contains -q 'chyba-pole' "$WORK/response" && contains -q '3 000' "$WORK/response" \
  && expect "3.8: the admin news form shows the limit at the text, keeps the text in the form and saves nothing" "$code/$(sq "SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Limit form'")/$(grep -c '&lt;div&gt;&lt;div&gt;' "$WORK/response")" "200/0/1" \
  || { echo "  CHYBA  3.8: admin news form over the limit: $code"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_newsletters (subject, intro, status, scheduled_at, created) VALUES ('Scheduled 251', 'Original intro', 'scheduled', '$(site_time)' + INTERVAL 1 DAY, '$(site_time)')"
NL_SCHEDULED=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT id FROM ka_newsletters WHERE subject = 'Scheduled 251'")
mcp_as "$DRAFT_TOKEN" draft_newsletter "{\"id\":$NL_SCHEDULED,\"intro\":\"Changed by a drafts connection\"}" > /dev/null
expect "a drafts-only connection cannot change a scheduled newsletter" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT intro FROM ka_newsletters WHERE id = $NL_SCHEDULED")" "Original intro"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_newsletters WHERE id = $NL_SCHEDULED"
# wrong tokens from one address do not lock out a valid one (a proxy in front of Docker, Claude's servers)
for i in $(seq 1 21); do mcp_as "kaleta_$(printf '0%.0s' $(seq 1 48))" get_page '{"id":1}' > /dev/null; done
mcp get_page '{"id":1}' > "$WORK/response"; contains -q '"isError":true\|"error"' "$WORK/response" && { echo "  CHYBA  wrong tokens locked out a valid token"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); } || echo "  ok     wrong tokens do not lock out a valid token"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'mcp'"
expect "the change log names the Claude connection" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_protokol WHERE via = 'Claude drafts' AND modul = 'claude'")" 1
mcp list_changes '{"by":"claude","limit":5}' > "$WORK/response"
mcp_text; contains -q '"claude_connection":"Claude drafts"' "$WORK/text" && echo "  ok     list_changes tells Claude's changes and their connection" || { echo "  CHYBA  list_changes"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "the change log in the admin filters Claude's changes" 200 "/admin.php?module=changelog&by=claude" 'Claude: Claude drafts'
# the site owner's instructions, resources, prompts and the protocol version
mcp update_settings '{"settings":{"claude_instructions":"Always say renovation, never reconstruction."}}' > /dev/null
# 3.15 (N44): the instructions reach every later connection – a change over MCP is in the change log with both texts, and the
# administrators' dashboard says so until one of them opens Claude settings (or dismisses it)
expect "3.15 N44: a change of the instructions over MCP is in the change log with the old and the new text and the connection" \
  "$(sq "SELECT CONCAT(modul, '|', via, '|', popis) FROM ka_protokol WHERE akce = 'instructions changed' ORDER BY idp DESC LIMIT 1")" "claude_settings|test|before: (empty) → now: «Always say renovation, never reconstruction.»"
check "3.15 N44: the dashboard tells the administrator the instructions for Claude changed, by whom and through which connection" 200 "/admin.php" "Pokyny pro Clauda se změnily"
grep -q 'přes připojení Clauda test' "$WORK/response" && grep -q 'module=claude_settings#zmena-pokynu' "$WORK/response" && grep -q 'action=instructions_seen' "$WORK/response" \
  && echo "  ok     3.15 N44: the notice names the connection, links to Claude settings and can be dismissed" || { echo "  CHYBA  N44 dashboard notice"; ERRORS=$((ERRORS+1)); }
check "3.15 N44: Claude settings show the change once, with the text before it" 200 "/admin.php?module=claude_settings" 'id="zmena-pokynu"'
expect "3.15 N44: once Claude settings were opened, the notice is gone from the dashboard and from Claude settings" \
  "$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"; grep -c 'Pokyny pro Clauda se změnily' "$WORK/response" || true)|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=claude_settings"; grep -c 'id="zmena-pokynu"' "$WORK/response" || true)" "0|0"
mcp update_settings '{"settings":{"claude_instructions":"Always say renovation, never reconstruction. Prices in CZK."}}' > /dev/null
mcp update_settings '{"settings":{"claude_instructions":"Always say renovation, never reconstruction. Prices in CZK."}}' > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=claude_settings&action=instructions_seen" -d "_csrf=$(csrf)"
expect "3.15 N44: the same text again is no change; Dismiss on the dashboard clears the notice without opening the settings" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'instructions changed'")|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"; grep -c 'Pokyny pro Clauda se změnily' "$WORK/response" || true)" "2|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=claude_settings"
n44_guards() { sq "SELECT CONCAT(COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_destructive'), '1'), COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_apps_only'), '0'))"; }
N44_GUARDS=$(n44_guards); N44_ARGS=(); [ "${N44_GUARDS:0:1}" = 1 ] && N44_ARGS+=(-d claude_destructive=1); [ "${N44_GUARDS:1:1}" = 1 ] && N44_ARGS+=(-d claude_apps_only=1)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=claude_settings&action=save" -d "_csrf=$(csrf)" -d tab=claude --data-urlencode "claude_instructions=Always say renovation, never reconstruction." ${N44_ARGS[@]+"${N44_ARGS[@]}"}
expect "3.15 N44: (the guardrails of Claude settings stay as they were)" "$(n44_guards)" "$N44_GUARDS"
expect "3.15 N44: a change in the administration is in the change log too, without a connection" \
  "$(sq "SELECT CONCAT(via, '|', popis) FROM ka_protokol WHERE akce = 'instructions changed' ORDER BY idp DESC LIMIT 1")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_instructions'")" \
  "|before: «Always say renovation, never reconstruction. Prices in CZK.» → now: «Always say renovation, never reconstruction.»|Always say renovation, never reconstruction."
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}' > "$WORK/response"
contains -q '"protocolVersion":"2025-03-26"' "$WORK/response" && contains -q 'Always say renovation' "$WORK/response" && contains -q '"prompts"' "$WORK/response" && echo "  ok     initialize: the client's protocol version, the owner's instructions, resources and prompts" || { echo "  CHYBA  initialize"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $DRAFT_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"1999-01-01"}}' > "$WORK/response"
contains -q '"protocolVersion":"2025-06-18"' "$WORK/response" && contains -q 'CAN ONLY SAVE DRAFTS' "$WORK/response" && echo "  ok     initialize tells a drafts-only connection its limits" || { echo "  CHYBA  initialize drafts"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://instructions"}}' > "$WORK/response"
contains -q 'Always say renovation' "$WORK/response" && echo "  ok     the instructions as an MCP resource" || { echo "  CHYBA  resources/read"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"build_page","arguments":{"topic":"kitchens"}}}' > "$WORK/response"
contains -q 'Build a new page about kitchens' "$WORK/response" && echo "  ok     prompts/get fills in a ready-made task" || { echo "  CHYBA  prompts/get"; ERRORS=$((ERRORS+1)); }
# 3.13: the prompts are Kaleta's skills – the new runbooks are listed with a title, every earlier name still works
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/list"}' > "$WORK/response"
php -r '$p = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["prompts"] ?? [], null, "name"); $want = ["build_page", "audit_and_fix", "translate_page", "write_news", "migrate_site", "launch_site", "weekly_care", "work_requests", "scheduled_run", "review_pending", "draft_blueprint", "set_up_bookings", "compliance_check", "add_language_version", "newsletter_release"];
    exit(array_keys($p) === $want && !isset($p["weekly_review"]) && ($p["add_language_version"]["title"] ?? "") === "Add a language version" && ($p["add_language_version"]["arguments"][0] ?? []) === ["name" => "language", "description" => "Language code, e.g. de, en-gb, pt-br or ar", "required" => true] ? 0 : 1);' -- "$WORK/response" \
  && echo "  ok     3.13: prompts/list has the runbooks (launch_site, weekly_care, set_up_bookings, compliance_check, add_language_version, newsletter_release) with titles and arguments" || { echo "  CHYBA  3.13: prompts/list"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"add_language_version","arguments":{"language":"ar"}}}' > "$WORK/response"
contains -q 'add the language version ar to this site' "$WORK/response" && contains -q 'run_read_tool (reads): ' "$WORK/response" && echo "  ok     3.13: prompts/get fills the language in and names the runners of non-essential tools" || { echo "  CHYBA  3.13: prompts/get add_language_version"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"migrate_site","arguments":{"old_url":"https://old.example","platform":"WordPress"}}}' > "$WORK/response"
OLD_PROMPTS=$(for name in weekly_review audit_and_fix review_pending work_requests scheduled_run draft_blueprint; do
  curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"prompts/get\",\"params\":{\"name\":\"$name\"}}" | grep -c '"messages"'; done | tr -d '\n')
contains -q 'old.example (WordPress) to this Kaleta site' "$WORK/response" && [ "$OLD_PROMPTS" = "111111" ] \
  && echo "  ok     3.13: migrate_site fills in the old address, and the earlier prompt names (weekly_review too) still answer" || { echo "  CHYBA  3.13: earlier prompt names: $OLD_PROMPTS"; ERRORS=$((ERRORS+1)); }
# 3.13 (N313-4): an array where a name belongs is a JSON-RPC answer, not an error page
N313_CODE=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":["x"]}}')
[ "$N313_CODE" = 200 ] && contains -q '"jsonrpc":"2.0"' "$WORK/response" && echo "  ok     3.13 N313-4: an array as the tool name gets a JSON-RPC answer, not a 500" || { echo "  CHYBA  3.13 N313-4: HTTP $N313_CODE"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"no_such_prompt"}}' > "$WORK/response"
contains -q '"code":-32602' "$WORK/response" && echo "  ok     3.13: an unknown prompt is a JSON-RPC error" || { echo "  CHYBA  3.13: unknown prompt"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://nope"}}' > "$WORK/response"
contains -q '"code":-32602' "$WORK/response" && echo "  ok     an unknown resource is a JSON-RPC error" || { echo "  CHYBA  resources/read unknown"; ERRORS=$((ERRORS+1)); }
# 3.7: a drafts-only connection saves hidden collection categories only – a category page is public
mcp_as "$DRAFT_TOKEN" save_collection_category '{"collection":"produkty","name":"Návrh","slug":"navrh","visible":true}' | contains 'cannot make a category visible' \
  && mcp_as "$DRAFT_TOKEN" save_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_TOP,\"name\":\"Přepsáno\"}" | contains 'this category is on the site' \
  && mcp_as "$DRAFT_TOKEN" delete_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_SUB}" | contains 'isError' \
  && echo "  ok     3.7 drafts: no visible category, no change of a visible one, no delete" || { echo "  CHYBA  3.7 drafts-only categories"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_collection_category '{"collection":"produkty","name":"Návrh","slug":"navrh"}' > /dev/null
expect "3.7 drafts: a new category is saved hidden, the visible ones are untouched" "$(sq "SELECT CONCAT((SELECT c.visible FROM ka_collection_categories c JOIN ka_collection_category_texts t ON t.category_id = c.id WHERE t.slug = 'navrh'), '|',
  (SELECT name FROM ka_collection_category_texts WHERE category_id = $CAT_TOP AND language = ''), '|', (SELECT COUNT(*) FROM ka_collection_categories WHERE id = $CAT_SUB))")" "0|Běžecké pásy|1"
check "3.7 drafts: the hidden category has no page" 404 /produkty/navrh
# settings that were admin-only before 2.2
mcp update_settings '{"settings":{"extensions":["novinky","poptavky"]}}' > "$WORK/response"
contains -q 'cannot switch itself off' "$WORK/response" && echo "  ok     Claude cannot switch its own connection off" || { echo "  CHYBA  extensions without claude"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
EXT_BEFORE=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$EXT_BEFORE" | sed 's/,/","/g')\",\"asistent\"],\"additional_languages\":[\"xx\"],\"llms_txt\":\"0\",\"indexing\":\"1\"}}" > "$WORK/response"
mcp_text; contains -q 'Unknown language codes: xx' "$WORK/text" && contains -q '"asistent"' "$WORK/text" && expect "extensions, SEO switches and languages over MCP, checked" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'llms_txt'")" 0 || { echo "  CHYBA  settings parity"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$EXT_BEFORE" | sed 's/,/","/g')\"],\"llms_txt\":\"1\"}}" > /dev/null
check "OAuth metadata for a site in a subfolder (openid-configuration)" 200 "/.well-known/openid-configuration" '"token_endpoint"'

