# AGENTS.md

Smart Search — WordPress plugin: a search box shortcode with predictive results (posts, pages, WooCommerce products),
voice input, suggested and popular keywords, and privacy-minded search analytics.

## Map

- `cik-smart-search.php` — main class `Cik_Smart_Search`: hooks, settings page, AJAX endpoint, analytics, cron
- `templates/search-box.php` — shortcode markup; themes override it at `cik-smart-search/search-box.php`
- `assets/js/predictive-search.js` — keyword filtering, AJAX results, keyboard navigation
- `assets/js/voice-search.js` — Web Speech API
- `assets/js/placeholder-animation.js` — animated placeholders
- `assets/css/` — `search-box.css` (variables/base), `theme-utilities.css` (components), `predictive-search.css`
- `uninstall.php` — removes options, transients, cron event and search-log posts

## Conventions

- Prefixes: `cik_search_` (functions, options, AJAX actions, post type, cron), `CIK_SEARCH_*` constants,
  `cik-search-` handles and CSS classes. Shortcode `[cik_search]`.
- Text domain `cik-smart-search`; English source strings.
- Scripts are registered on `init` and enqueued only by the shortcode (block themes render shortcodes before
  `wp_enqueue_scripts`, so `wp_localize_script()` needs the handle registered early).
- The predictive endpoint is public and read-only: nonce, unslash + sanitize, 100-char cap, max 20 results, cached in
  a transient keyed by a cache version.
- Never build results with `innerHTML` from data; use DOM APIs and `textContent`. The only HTML field is
  `matched_content`, escaped on the server.
- Analytics: no user agent, referer without query string, IPs off by default and anonymised.
- Extension points: `cik_search_query_args`, `cik_search_result` filters.
- PHP 7.4 compatible.

## Checks before a commit

```bash
docker run --rm -v "$PWD:/app" -w /app php:7.4-cli sh -c 'for f in $(find . -name "*.php"); do php -l "$f" | grep -v "No syntax errors"; done'
node --check assets/js/predictive-search.js && node --check assets/js/voice-search.js && node --check assets/js/placeholder-animation.js
```

Then test in a block theme and a classic theme: the page with `[cik_search]` must print the `cikSearch*` settings,
and the AJAX search must return results. No `console.log` or `error_log` left behind.
Commits: short conventional messages in English.
