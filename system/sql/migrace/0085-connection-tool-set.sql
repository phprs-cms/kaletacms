-- Which tools a Claude connection lists (3.10): all (every tool it may use, as before) or essentials (the core tools plus
-- find_tools and the runners, which reach the rest on demand). Existing connections keep all. The OAuth code carries the
-- choice from the consent screen; a token refresh copies it like the access.
ALTER TABLE ka_api_tokeny ADD COLUMN tool_set VARCHAR(16) NOT NULL DEFAULT 'all' AFTER access;
ALTER TABLE ka_oauth_kody ADD COLUMN tool_set VARCHAR(16) NOT NULL DEFAULT 'all' AFTER access;
