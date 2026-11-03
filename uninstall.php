<?php
/**
 * Uninstall Smart Search.
 *
 * Runs only when the plugin is deleted from the Plugins screen. Removes the
 * plugin's options, transients, scheduled event and search-log entries.
 *
 * @package CikSmartSearch
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

wp_clear_scheduled_hook('cik_search_cleanup');

// Search-log posts and their meta (the post type is not registered during uninstall).
$wpdb->query(
    $wpdb->prepare(
        "DELETE pm FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type = %s",
        'cik_search_log'
    )
);
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->posts} WHERE post_type = %s",
        'cik_search_log'
    )
);

// Options and transients: every key starts with "cik_search_".
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s",
        $wpdb->esc_like('cik_search_') . '%',
        $wpdb->esc_like('_transient_cik_search_') . '%',
        $wpdb->esc_like('_transient_timeout_cik_search_') . '%'
    )
);

wp_cache_flush();
