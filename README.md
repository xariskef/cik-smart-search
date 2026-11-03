# Smart Search

A WordPress search box with live (predictive) results, voice input, suggested keywords and built-in search analytics.

## Features

- **Predictive search**: results appear as the visitor types. It searches the post types you choose (posts, pages, WooCommerce products, any public custom post type) and, optionally, taxonomy terms.
- **Highlighted snippets**: each result shows where the match was found ("Found in the title" / "Found in the content"), with the matched words highlighted. Greek matching ignores accents. Greeklish input (Greek typed in Latin letters) is also used for highlighting and for putting title matches first.
- **Suggested keywords**: keywords you define, plus the most popular searches from the analytics. They are filtered in the browser while the visitor types, with no extra request.
- **Voice search**: the Web Speech API fills the field from the microphone. The language follows the site locale or can be set explicitly. The button is hidden in browsers that don't support the API.
- **Animated placeholders**: a typewriter effect that cycles through several placeholder texts.
- **Quick tags**: links under the box that run predefined searches.
- **Search analytics**: every front-end search is logged once per term with a count, the number of results, the last search date and the source page. Data is deleted automatically after a retention period you set.
- **Multilingual**: separate English texts for WPML sites, and translatable strings (text domain `cik-smart-search`).

## How it works

```
[cik_search] shortcode
   └─ templates/search-box.php            (form, tags, empty results panel)
        └─ assets/js/predictive-search.js
             ├─ < min. characters → filters preloaded keywords in the browser
             └─ ≥ min. characters → GET admin-ajax.php?action=cik_search_predictive_search
                                      └─ ajax_predictive_search()
                                           ├─ transient cache (per query + language + settings)
                                           ├─ WP_Query (posts) + get_terms() (taxonomies)
                                           └─ JSON: title, url, type, image, excerpt, matched_content
```

- **Assets**: the stylesheets load on every front-end page, so the box never shows unstyled. The three scripts and their settings (`wp_localize_script`) load only when the shortcode renders.
- **Predictive endpoint**: the endpoint is public (`wp_ajax_nopriv_`) because anonymous visitors use it. It only reads published content and never writes. The query is unslashed, sanitized and capped at 100 characters, results are capped at 20, and responses are cached in a transient. The cache is invalidated by increasing a version number when content or settings change, so it also works with persistent object caches.
- **Rendering**: the server returns plain text for titles, excerpts and labels. The browser writes them with `textContent`. The only HTML field is `matched_content`, which the server builds from `esc_html()`-escaped text plus `<mark>`/`<strong>` tags.
- **Analytics**: a `pre_get_posts` hook on the main front-end search query stores each normalized term once, in a private `cik_search_log` post type, and increments its count afterwards. The same term is counted at most once per 5 minutes. A daily cron job deletes entries older than the retention period. The admin list has sortable "Times searched" and "Results" columns.
- **Popular searches**: the most-searched terms among those searched in the last 30 days, read with one prepared SQL query and cached for an hour.

## Security & privacy

- All settings are saved by one handler, `save_settings()`, after a `manage_options` capability check and a nonce check. Every field is unslashed and sanitized. Numbers are limited to the ranges the form allows, and choices (post types, taxonomies, languages, keyword source) are checked against allowlists.
- Admin and front-end output is escaped (`esc_html`, `esc_attr`, `esc_url`). The JS never puts visitor-supplied text into `innerHTML`.
- Database queries that use variables go through `$wpdb->prepare()`, and LIKE patterns are escaped with `$wpdb->esc_like()`.
- The search log is visible only to administrators (`manage_options`). Nobody can create entries from the admin.
- **What the analytics store**: the search term, how many times it was searched, the number of results, timestamps and the referring URL without its query string. IP logging is **off by default**. When it is enabled, only `REMOTE_ADDR` is used, and the address is stored anonymized with `wp_privacy_anonymize_ip()`. Spoofable `X-Forwarded-For` headers are ignored. A site behind a trusted proxy can supply the real address with the `cik_search_client_ip` filter. Search terms can still contain personal data that visitors typed, so mention search logging in the site's privacy policy and keep the retention period short.
- Every PHP file exits when accessed directly.

## Requirements

- WordPress 5.8+
- PHP 7.4+ with the `mbstring` extension
- Optional: WooCommerce (products are searched like any other post type), WPML (English texts per language)

## Installation

1. Copy the `cik-smart-search` folder to `wp-content/plugins/`.
2. Activate **Smart Search** in **Plugins**.
3. Configure it under **Smart Search → Settings**.
4. Add the shortcode `[cik_search]` to a page, widget or template (`<?php echo do_shortcode('[cik_search]'); ?>`).

## Usage

### Shortcode

```
[cik_search]
[cik_search wrapper_class="my-search"]
```

| Attribute       | Default               | Description                                    |
|-----------------|-----------------------|------------------------------------------------|
| `wrapper_class` | `cik-search-position` | CSS class(es) added to the outer `<section>`. |

You can use the shortcode more than once on the same page.

### Settings (Smart Search → Settings)

| Tab               | Settings |
|-------------------|----------|
| General           | Post types and taxonomies to search, title matches first, manual keywords, popular searches (on/off, limit), how many keywords to prepare and to display, keyword source |
| Search Box        | Placeholder (fallback), animated placeholders, tags title, results title, tags (label + search term, in the default language and in English) |
| Predictive Search | On/off, minimum characters, delay, maximum results, images, excerpt, highlighted snippet, match location label, title-only search, cache duration, clear cache |
| Voice Search      | Auto-submit, listening colour, recognition language (automatic or fixed) |
| Analytics         | On/off, anonymized IP logging, retention in days |

Analytics are under **Smart Search → Analytics**.

### Customising

- **Template**: copy `templates/search-box.php` to `your-theme/cik-smart-search/search-box.php`.
- **Styles**: override the `.cik-search-*` classes in your theme.

### Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `cik_search_query_args` | filter | Change the `WP_Query` arguments of the predictive search (`$args`, `$query`). |
| `cik_search_result` | filter | Change one result before it is returned (`$result`, `$post`). |
| `cik_search_client_ip` | filter | Supply the visitor IP when behind a trusted reverse proxy. |

Example: also search a `document` post type created by another plugin, and link to its file instead of the post:

```php
add_filter('cik_search_query_args', function ($args) {
    $args['post_type'][] = 'document';
    return $args;
});

add_filter('cik_search_result', function ($result, $post) {
    if ($post->post_type === 'document' && ($file = get_post_meta($post->ID, '_file_url', true))) {
        $result['url'] = esc_url_raw($file);
    }
    return $result;
}, 10, 2);
```

### Uninstall

Deactivating the plugin stops the scheduled cleanup and keeps all data. Deleting it from **Plugins** runs `uninstall.php`, which removes its options, transients, scheduled event and search-log entries.

## Project structure

```
cik-smart-search/
├── cik-smart-search.php          # Plugin bootstrap and main class (hooks, settings page, AJAX, analytics)
├── uninstall.php                 # Data cleanup on plugin deletion
├── templates/
│   └── search-box.php            # Shortcode markup (theme-overridable)
└── assets/
    ├── js/
    │   ├── predictive-search.js  # Keyword filtering, AJAX results, keyboard navigation
    │   ├── voice-search.js       # Web Speech API integration
    │   └── placeholder-animation.js
    └── css/
        ├── search-box.css        # Variables and base styles
        ├── theme-utilities.css   # Component styles (box, results, voice button)
        └── predictive-search.css # Keyword suggestions and results panel
```

## Known limitations

- The settings page posts back to itself (with capability and nonce checks) instead of using `options.php` and the Settings API.
- The predictive endpoint checks a nonce. On full-page-cached sites, a cached page can carry an expired nonce (after 12–24 h), so the page cache lifetime should be shorter than that, or the check should be removed for this read-only endpoint.
- The component styles use fixed colours and the "Manrope" font, which falls back to sans-serif if the theme doesn't load it. The CSS variables declared in `search-box.css` are not used by the component styles yet.
- `cik-smart-search.php` is a single class. Splitting it into admin, front-end, AJAX and analytics classes is the next refactoring step.

## License

Copyright © Cikefallinos. All rights reserved. Source code published for portfolio review only. See [LICENSE](LICENSE).
