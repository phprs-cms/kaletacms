echo "== syntaxe PHP"
# Kaleta's own files only – not the git worktrees of parallel work under .claude/ nor add-ons in extensions/
find "$ROOT" -name '*.php' -not -path '*/.git/*' -not -path '*/dist/*' -not -path '*/.claude/*' -not -path "$ROOT/extensions/*" -print0 | xargs -0 -n1 php -l > /dev/null

