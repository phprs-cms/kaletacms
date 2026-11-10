echo "== Claude (MCP): trash, deleting and the rest of the admin (1.6)"
# the changes a Claude connection made in the last hour, counted like Core\Guardrails (a batch or an import step by its "<n> rows")
claude_used() { sq "SELECT COALESCE(SUM(CASE WHEN akce IN ('save_redirects','save_collection_items','importuj_web','import_wordpress') AND popis REGEXP '^[0-9]+ rows' THEN CAST(SUBSTRING_INDEX(popis, ' ', 1) AS UNSIGNED) ELSE 1 END), 0) FROM ka_protokol WHERE modul = 'claude' AND via = '$1' AND cas > '$(site_time '-1 hour')'"; }
