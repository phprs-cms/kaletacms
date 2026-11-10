echo "== 2.11: job openings that close themselves"
mcp create_collection '{"name":"Volná místa","preset":"jobs"}' > "$WORK/response"
JOBS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE preset = 'jobs'")
expect "jobs: the preset brings JobPosting data, the contact linked to the team, the redirect of hidden jobs to the jobs page and an item template with a form and a CV field" \
  "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.employmentType')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[8].kolekce')) = (SELECT seo_link FROM ka_kolekce WHERE preset = 'people' ORDER BY idk LIMIT 1), '|', hidden_redirect, '|', stavba LIKE '%{{nazev}}%' AND stavba LIKE '%\"typ\":\"formular\"%' AND stavba LIKE '%\"typ\":\"soubor\"%') FROM ka_kolekce WHERE idk = $JOBS_IDK")" "JobPosting|employment_type|1|/volna-mista|1"
contains -q 'valid_until' "$WORK/response" && echo "  ok     jobs: Claude is told to always set the closing date (valid_until)" || { echo "  CHYBA  jobs how_to_use"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_kolekce SET schema_org = JSON_SET(schema_org, '\$.mena', 'CZK') WHERE idk = $JOBS_IDK" > /dev/null # the collection currency: salaries are published only with it
JOB_TOMORROW=$(site_time "+1 day" "Y-m-d"); JOB_YESTERDAY=$(site_time "-1 day" "Y-m-d")
mcp save_collection_item "{\"collection\":\"volna-mista\",\"name\":\"Truhlář\",\"slug\":\"truhlar\",\"values\":{\"location\":\"Brno\",\"employment_type\":\"plný úvazek\",\"salary_min\":\"35000\",\"salary_max\":\"45000\",\"salary_unit\":\"za měsíc\",\"description\":\"<p>Výroba nábytku na míru.</p>\"},\"visible\":true,\"valid_until\":\"$JOB_TOMORROW\"}" > "$WORK/response"
curl -s -o "$WORK/job.html" "$B/volna-mista/truhlar"
grep -qF '"@type":"JobPosting"' "$WORK/job.html" && grep -qF "\"validThrough\":\"$JOB_TOMORROW\"" "$WORK/job.html" && grep -qF '"employmentType":"FULL_TIME"' "$WORK/job.html" && grep -qF '"addressLocality":"Brno","addressCountry":"CZ"' "$WORK/job.html" \
  && grep -qF '"baseSalary":{"@type":"MonetaryAmount","currency":"CZK","value":{"@type":"QuantitativeValue","minValue":35000,"maxValue":45000,"unitText":"MONTH"}}' "$WORK/job.html" && grep -qF '"hiringOrganization":{"@type":"Organization","name":"' "$WORK/job.html" \
  && echo "  ok     jobs: the item page carries a JobPosting with validThrough, the company, the place and the salary" || { echo "  CHYBA  JobPosting on the item page"; grep -o '"@type":"JobPosting".*' "$WORK/job.html" | head -c 700; ERRORS=$((ERRORS+1)); }
grep -q 'name="p3" type="file"' "$WORK/job.html" && grep -qF 'type="hidden" name="p6" value="Truhlář"' "$WORK/job.html" && grep -q 'enctype="multipart/form-data"' "$WORK/job.html" \
  && echo "  ok     jobs: the application form has a CV field and carries the job name in a hidden field" || { echo "  CHYBA  application form on the item page"; ERRORS=$((ERRORS+1)); }
job_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/job.html" | head -1 | sed 's/.*value="//;s/"$//'; }
JOB_SOURCE=$(job_field zdroj); JOB_ELEMENT=$(job_field prvek); JOB_TIME=$(job_field as_cas); JOB_SIGNATURE=$(job_field as_podpis)
expect "jobs: the form is served from the collection's item template (the source of its enquiries)" "$JOB_SOURCE" "kolekce:$JOBS_IDK"
# a job whose closing date passed hides itself and its address leads to the jobs page; a job without a closing date is in the audit
mcp save_collection_item "{\"collection\":\"volna-mista\",\"name\":\"Svářeč\",\"slug\":\"svarec\",\"values\":{\"location\":\"Brno\"},\"visible\":true,\"valid_until\":\"$JOB_YESTERDAY\"}" > /dev/null
mcp save_collection_item '{"collection":"volna-mista","name":"Bez uzaverky","slug":"bez-uzaverky","values":{"location":"Praha"},"visible":true}' > /dev/null
check "jobs: before the validity job the job that closed yesterday still has its page" 200 "/volna-mista/svarec" "Svářeč"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "jobs: the validity job hid the job whose closing date passed, the open ones stay" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, '=', zobrazit) ORDER BY seo_link) FROM ka_kolekce_polozky WHERE idk = $JOBS_IDK")" "bez-uzaverky=1,svarec=0,truhlar=1"
case "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/volna-mista/svarec")" in "301 $B/volna-mista") echo "  ok     jobs: the closed job's address leads to the jobs page";; *) echo "  CHYBA  closed job redirect: $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/volna-mista/svarec")"; ERRORS=$((ERRORS+1));; esac
mcp site_audit '{"kind":"job"}' > "$WORK/response"
grep -q 'Bez uzaverky' "$WORK/response" && ! grep -q 'Truhl' "$WORK/response" && contains -q 'job\\":1' "$WORK/response" && contains -q 'collection\\":\\"volna-mista' "$WORK/response" \
  && echo "  ok     MCP: site_audit kind job lists only the visible job without a closing date, with its target" || { echo "  CHYBA  site_audit job"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "Administration → Site audit lists the job opening without a closing date" 200 "/admin.php?module=audit" "Bez uzaverky"
# the retention of applications next to the enquiries retention, with the usual practice of the company country as a hint
check "jobs: Enquiries offers the retention of job applications with the usual practice for the company country" 200 "/admin.php?module=enquiries" 'name="mesice_uchazeci" value="0"'
grep -q 'CZ: 6' "$WORK/response" && echo "  ok     jobs: the hint names the country and the months" || { echo "  CHYBA  retention hint"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=settings" -d "_csrf=$(csrf)" -d mesice=24 -d mesice_uchazeci=3
expect "jobs: the retention of applications is saved next to the enquiries retention" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'job_applications_months'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'))")" "3|24"
# an application with a CV: an enquiry from the job's page; the hidden job name comes back as plain text only
printf '%%PDF-1.4 test CV\n' > "$WORK/cv.pdf"
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -F "zdroj=$JOB_SOURCE" -F "prvek=$JOB_ELEMENT" -F zpet=/volna-mista/truhlar -F "as_cas=$JOB_TIME" -F "as_podpis=$JOB_SIGNATURE" \
  -F p0=Jan -F p1=jan@example.cz -F p2= -F "p3=@$WORK/cv.pdf" --form-string "p4=Hlásím se." -F p5=1 --form-string "p6=<b>Truhlář</b>") # --form-string: a value starting with < would be read as a file by -F
case "$location" in *"/volna-mista/truhlar?form=$JOB_ELEMENT&result=ok#"*) echo "  ok     jobs: an application with a CV was sent";; *) echo "  CHYBA  application: $location"; ERRORS=$((ERRORS+1));; esac
APP_IDP=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE zdroj = 'kolekce:$JOBS_IDK'")
expect "jobs: the application is an enquiry from the job's page with the job name as plain text and the CV outside the web root" \
  "$(sq "SELECT CONCAT(stranka, '|', email, '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[6][1]')), '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) REGEXP '^[0-9]{4}/[0-9]{2}/[a-f0-9]{24}[.]pdf$') FROM ka_poptavky WHERE idp = $APP_IDP")" "/volna-mista/truhlar|jan@example.cz|Truhlář|1"
CV_PATH=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) FROM ka_poptavky WHERE idp = $APP_IDP")
[ -n "$CV_PATH" ] && [ -f "$WORK/web/storage/prilohy/$CV_PATH" ] && echo "  ok     jobs: the CV is stored in storage/prilohy" || { echo "  CHYBA  CV file missing: $CV_PATH"; ERRORS=$((ERRORS+1)); }
# the daily clean-up deletes applications past their retention (3 months) with the CV and records it; an ordinary enquiry of the same age stays (24 months)
ENQ_IDP=$(sq "SELECT MIN(idp) FROM ka_poptavky WHERE zdroj LIKE 'stranka:%'")
sq "UPDATE ka_poptavky SET datum = '$(site_time)' - INTERVAL 4 MONTH WHERE idp IN ($APP_IDP, $ENQ_IDP)" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "jobs: the clean-up deleted the application after its retention and recorded it; the ordinary enquiry of the same age stays" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_poptavky WHERE idp = $APP_IDP), '|', (SELECT COUNT(*) FROM ka_poptavky WHERE idp = $ENQ_IDP), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'applications.purged' AND data LIKE '%\"count\":1,\"months\":3%'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'enquiries' AND akce = 'purge_applications'))")" "0|1|1|1"
[ ! -f "$WORK/web/storage/prilohy/$CV_PATH" ] && echo "  ok     jobs: the CV was deleted with the application" || { echo "  CHYBA  CV file still there after the clean-up"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'job_applications_months'" > /dev/null
