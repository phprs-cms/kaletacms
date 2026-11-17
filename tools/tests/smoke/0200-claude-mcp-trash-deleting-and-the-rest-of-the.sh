echo "== Claude (MCP): trash, deleting and the rest of the admin (1.6)"
# the changes counted against a Claude connection's hourly limit in the last hour, like Core\Guardrails (a batch or an import
# step by its "<n> rows"); 3.43 review L1: the limit is per user – every Claude row of the user who owns the connection <via>
claude_used() { sq "SELECT COALESCE(SUM(CASE WHEN akce IN ('save_redirects','save_collection_items','importuj_web','import_wordpress') AND popis REGEXP '^[0-9]+ rows' THEN CAST(SUBSTRING_INDEX(popis, ' ', 1) AS UNSIGNED) ELSE 1 END), 0) FROM ka_protokol WHERE modul = 'claude' AND cas > '$(site_time '-1 hour')'
  AND kdo = (SELECT kdo FROM (SELECT kdo FROM ka_protokol WHERE modul = 'claude' AND via = '$1' AND kdo IS NOT NULL ORDER BY idp DESC LIMIT 1) w)"; }
