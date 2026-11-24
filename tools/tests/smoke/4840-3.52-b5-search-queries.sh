echo "== 3.52 B5: what visitors search for on the site – Statistics and get_stats; never page 2, a bot, a signed-in user, an @ or 6+ digits"
# one search of /hledani: query, page, user agent, cookie jar
b840_get() { curl -s -o /dev/null -G -A "${3:-Mozilla/5.0 test}" ${4:+-b "$4"} "$B/hledani" --data-urlencode "q=$1" ${2:+-d "page=$2"}; }
b840_rows() { sq "SELECT COALESCE(GROUP_CONCAT(CONCAT(query, ':', searches, ':', without_result) ORDER BY query SEPARATOR ','), '-') FROM ka_search_queries WHERE query LIKE '%840%'"; }
B840_NOW=$(site_time)
B840_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B840_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B840_ADMIN, 'b840 admin', 'full', 'all', SHA2('$B840_TOKEN', 256), '$B840_NOW');
  INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES ('altany-b840', 'Zahradní altány B840', 'Altány na míru.', 1);
  DELETE FROM ka_search_queries WHERE query LIKE '%840%'; DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
mcp_call "$B840_TOKEN" update_settings '{"settings":{"stats":true}}' > /dev/null

expect "3.52 B5: no FULLTEXT ft_clanky on the news any more (3.53: migration 0141 dropped ft_clanky_hledani too – none left)" \
  "$(sq "SELECT COALESCE(GROUP_CONCAT(DISTINCT INDEX_NAME), '-') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_novinky' AND INDEX_TYPE = 'FULLTEXT'")" "-"

b840_get 'altány b840'
b840_get '  Altány   B840 '      # the same query: lower case, spaces collapsed
b840_get 'zzqq840'               # finds nothing
b840_get 'altány b840' 2         # the second page of the results – counted once already
b840_get 'altány b840' '' 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
b840_get 'altány b840' '' 'Mozilla/5.0 test' "$JAR" # the signed-in administrator
b840_get 'jan@b840.cz'           # may name a person
b840_get 'b840 777 123 456'      # 6+ digits: a phone number
expect "3.52 B5: one row per query and day – searches and searches without result; page 2, the bot, the signed-in user, the @ and the phone number left out" \
  "$(b840_rows)|$(sq "SELECT COUNT(*) FROM ka_search_queries WHERE query LIKE '%@%' OR query LIKE '%777%'")" "altány b840:2:0,zzqq840:1:1|0"

mcp_call "$B840_TOKEN" update_settings '{"settings":{"stats":"0"}}' > /dev/null
b840_get 'zzqq840'
mcp_call "$B840_TOKEN" update_settings '{"settings":{"stats":true}}' > /dev/null
expect "3.52 B5: with the statistics off nothing is recorded" "$(b840_rows)" "altány b840:2:0,zzqq840:1:1"

curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=stats&days=7"
expect "3.52 B5: Statistics show Searched terms and Searches without result with the queries" \
  "$(grep -oE '<h2>(Hledané výrazy|Searched terms)</h2>' "$WORK/response" | wc -l | tr -d ' ')|$(grep -oE '<h2>(Hledání bez výsledku|Searches without result)</h2>' "$WORK/response" | wc -l | tr -d ' ')|$(grep -o '>altány b840<' "$WORK/response" | wc -l | tr -d ' ')|$(grep -o '>zzqq840<' "$WORK/response" | wc -l | tr -d ' ')" "1|1|1|2"

expect "3.52 B5: get_stats carries site_search (additive) – the terms with their counts, the ones without result, marked as visitors' text" \
  "$(mcp_call "$B840_TOKEN" get_stats '{"days":7}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "{}", true); $s = $d["site_search"] ?? [];
    $find = fn (array $rows, string $q): string => implode(":", array_slice(array_values(array_filter($rows, fn ($x) => ($x["query"] ?? "") === $q))[0] ?? ["-"], 1));
    echo $find($s["terms"] ?? [], "altány b840"), "|", $find($s["without_result"] ?? [], "zzqq840"), "|", $find($s["without_result"] ?? [], "altány b840") ?: "-", "|", (int) in_array("site_search", $d["untrusted"] ?? [], true), "|", (int) isset($d["search"]);')" \
  "2:0|1:1|-|1|1"

sq "DELETE FROM ka_search_queries WHERE query LIKE '%840%'; DELETE FROM ka_stranky WHERE seo_link = 'altany-b840'; DELETE FROM ka_api_tokeny WHERE nazev = 'b840 admin'; DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
