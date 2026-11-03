<?php
/**
 * Plugin Name: Smart Search
 * Description: Predictive search box with voice input, suggested keywords and search analytics.
 * Version: 2.8.0
 * Author: Cikefallinos
 * Author URI: https://cikefallinos.com
 * Text Domain: cik-smart-search
 * License: All rights reserved
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('CIK_SEARCH_VERSION', '2.8.0');
define('CIK_SEARCH_PATH', plugin_dir_path(__FILE__));
define('CIK_SEARCH_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class Cik_Smart_Search {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Cached analytics settings to prevent infinite loops
     */
    private $analytics_settings_cache = null;
    
    /**
     * Get Instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Get analytics settings (cached to prevent infinite loops)
     */
    private function get_analytics_settings() {
        if ($this->analytics_settings_cache === null) {
            $this->analytics_settings_cache = array(
                'enabled' => get_option('cik_search_enable_analytics', 1),
                'log_ip' => get_option('cik_search_log_ip', 0),
                'retention' => get_option('cik_search_analytics_retention', 90),
            );
        }
        return $this->analytics_settings_cache;
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register scripts early: block themes render shortcodes before
        // wp_enqueue_scripts fires, and wp_localize_script() needs a registered handle.
        add_action('init', array($this, 'register_scripts'));

        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Register shortcode
        add_shortcode('cik_search', array($this, 'render_search_box'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        // Add settings link
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        
        // Predictive Search AJAX
        add_action('wp_ajax_cik_search_predictive_search', array($this, 'ajax_predictive_search'));
        add_action('wp_ajax_nopriv_cik_search_predictive_search', array($this, 'ajax_predictive_search'));
        
        // Clear predictive search cache when posts are updated
        add_action('save_post', array($this, 'clear_predictive_cache'));
        add_action('delete_post', array($this, 'clear_predictive_cache'));
        
        // Search Analytics
        add_action('init', array($this, 'register_search_log_post_type'));
        add_action('pre_get_posts', array($this, 'log_search_query'));
        add_filter('manage_cik_search_log_posts_columns', array($this, 'search_log_columns'));
        add_action('manage_cik_search_log_posts_custom_column', array($this, 'search_log_column_content'), 10, 2);
        add_filter('manage_edit-cik_search_log_sortable_columns', array($this, 'search_log_sortable_columns'));
        add_action('pre_get_posts', array($this, 'search_log_orderby'));
        
        // Data retention cron (scheduled on activation; re-created here if it was lost).
        add_action('cik_search_cleanup', array($this, 'cleanup_old_logs'));
        add_action('admin_init', array(__CLASS__, 'schedule_cleanup'));
        
        // Admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Enqueue front-end styles.
     *
     * The (small) stylesheets are enqueued on every front-end page so the box
     * renders without a flash of unstyled content when a theme prints the
     * shortcode from a template. The scripts and their data (which needs a
     * database query for popular searches) are only enqueued by
     * render_search_box(), i.e. on pages that actually contain a search box.
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'cik-smart-search',
            CIK_SEARCH_URL . 'assets/css/search-box.css',
            array(),
            CIK_SEARCH_VERSION
        );

        // Component styles for the template and the JS-rendered results.
        wp_enqueue_style(
            'cik-search-theme-utilities',
            CIK_SEARCH_URL . 'assets/css/theme-utilities.css',
            array('cik-smart-search'),
            CIK_SEARCH_VERSION
        );

        wp_enqueue_style(
            'cik-search-predictive',
            CIK_SEARCH_URL . 'assets/css/predictive-search.css',
            array(),
            CIK_SEARCH_VERSION
        );
    }

    /**
     * Register the front-end scripts (enqueued later by the shortcode).
     *
     * Runs on `init` so the handles exist before any content is rendered;
     * otherwise wp_localize_script() silently drops the settings in block themes.
     */
    public function register_scripts() {
        if (is_admin()) {
            return;
        }

        wp_register_script(
            'cik-search-voice',
            CIK_SEARCH_URL . 'assets/js/voice-search.js',
            array(),
            CIK_SEARCH_VERSION,
            true
        );

        wp_register_script(
            'cik-search-placeholder',
            CIK_SEARCH_URL . 'assets/js/placeholder-animation.js',
            array(),
            CIK_SEARCH_VERSION,
            true
        );

        wp_register_script(
            'cik-search-predictive',
            CIK_SEARCH_URL . 'assets/js/predictive-search.js',
            array(),
            CIK_SEARCH_VERSION,
            true
        );
    }

    /**
     * Enqueue the scripts and pass their settings (runs once per page, from the shortcode).
     */
    private function enqueue_scripts() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        wp_enqueue_script('cik-search-voice');
        wp_enqueue_script('cik-search-placeholder');
        wp_enqueue_script('cik-search-predictive');

        wp_localize_script('cik-search-voice', 'cikSearchVoice', array(
            'autoSubmit'       => (bool) get_option('cik_search_voice_auto_submit', 0),
            'listeningBgColor' => get_option('cik_search_voice_listening_color', '#22c55e'),
            'language'         => $this->get_voice_language(),
            'i18n'             => array(
                'permissionDenied'    => __('Please allow microphone access.', 'cik-smart-search'),
                'permissionDeniedIOS' => __('Please allow microphone access: Settings > Safari > Microphone.', 'cik-smart-search'),
                'network'             => __('Network error. Please check your connection.', 'cik-smart-search'),
                'noMicrophone'        => __('Microphone is not available.', 'cik-smart-search'),
            ),
        ));

        wp_localize_script('cik-search-placeholder', 'cikSearchPlaceholders', $this->get_placeholders());
        wp_localize_script('cik-search-predictive', 'cikSearchPredictive', $this->get_predictive_settings());
    }

    /**
     * Animated placeholder texts for the current language.
     *
     * @return array
     */
    private function get_placeholders() {
        $suffix = (apply_filters('wpml_current_language', null) === 'en') ? '_en' : '';

        $placeholders = array_values(array_filter((array) get_option('cik_search_placeholders' . $suffix, array())));
        if (empty($placeholders)) {
            $single = $suffix ? get_option('cik_search_placeholder_en', '') : '';
            if (empty($single)) {
                $single = get_option('cik_search_placeholder', 'Search...');
            }
            $placeholders = array($single);
        }

        return $placeholders;
    }

    /**
     * Settings and preloaded keywords for predictive-search.js.
     *
     * @return array
     */
    private function get_predictive_settings() {
        $keywords_data = array(
            'manual'  => array(),
            'popular' => array(),
        );

        $keywords_source = get_option('cik_search_keywords_source', 'mixed');
        $total_limit     = (int) get_option('cik_search_suggested_keywords_limit', 30);
        $popular_texts   = array(); // For de-duplication against manual keywords.

        // Popular searches first (they carry counts for ordering).
        if (get_option('cik_search_show_popular_searches', 1) && get_option('cik_search_enable_analytics', 1)) {
            $popular_searches = $this->get_popular_searches((int) get_option('cik_search_popular_searches_limit', 5));

            foreach ($popular_searches as $search) {
                $keywords_data['popular'][] = array(
                    'text'    => $search['query'],
                    'count'   => (int) $search['count'],
                    'results' => isset($search['results']) ? (int) $search['results'] : 0,
                    'type'    => 'popular',
                );
                $popular_texts[] = mb_strtolower($search['query'], 'UTF-8');
            }
        }

        // Manual keywords, skipping the ones already listed as popular.
        if ($keywords_source !== 'popular_only') {
            foreach ((array) get_option('cik_search_suggested_keywords', array()) as $keyword) {
                if (!in_array(mb_strtolower($keyword, 'UTF-8'), $popular_texts, true)) {
                    $keywords_data['manual'][] = array(
                        'text' => $keyword,
                        'type' => 'manual',
                    );
                }
            }
        }

        // Total limit: popular first, then manual.
        if ($total_limit > 0) {
            $keywords_data['popular'] = array_slice($keywords_data['popular'], 0, $total_limit);
            $keywords_data['manual']  = array_slice($keywords_data['manual'], 0, max(0, $total_limit - count($keywords_data['popular'])));
        }

        // Translated singular labels per post type / taxonomy (built per request,
        // so the multilingual plugin's current language is already set).
        $type_labels = array();
        foreach ((array) get_option('cik_search_predictive_post_types', array('post', 'page')) as $post_type) {
            $label = $this->get_post_type_label($post_type);
            if ($label !== '') {
                $type_labels[$post_type] = $label;
            }
        }
        foreach ((array) get_option('cik_search_predictive_taxonomies', array()) as $taxonomy) {
            $taxonomy_object = get_taxonomy($taxonomy);
            if ($taxonomy_object) {
                $type_labels[$taxonomy] = $this->to_plain_text($taxonomy_object->labels->singular_name);
            }
        }

        return array(
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('cik_search_predictive_search'),
            'enabled'            => (bool) get_option('cik_search_enable_predictive', 1),
            'minChars'           => (int) get_option('cik_search_predictive_min_chars', 3),
            'delay'              => (int) get_option('cik_search_predictive_delay', 300),
            'preloadedKeywords'  => $keywords_data,
            'maxDisplayKeywords' => (int) get_option('cik_search_keywords_display_limit', 30),
            'typeLabels'         => $type_labels,
            'i18n'               => array(
                'searching' => __('Searching...', 'cik-smart-search'),
            ),
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'cik-smart-search') === false) {
            return;
        }
        
        // Color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        // Admin scripts
        wp_add_inline_script('wp-color-picker', 'jQuery(document).ready(function($) { $(".color-picker").wpColorPicker(); });');
    }
    
    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        // Main menu
        add_menu_page(
            __('Smart Search', 'cik-smart-search'),
            __('Smart Search', 'cik-smart-search'),
            'manage_options',
            'cik-smart-search',
            array($this, 'render_settings_page'),
            'dashicons-search',
            60
        );
        
        // Settings submenu (same as parent)
        add_submenu_page(
            'cik-smart-search',
            __('Settings', 'cik-smart-search'),
            __('Settings', 'cik-smart-search'),
            'manage_options',
            'cik-smart-search',
            array($this, 'render_settings_page')
        );
        
        // Analytics submenu
        add_submenu_page(
            'cik-smart-search',
            __('Search Analytics', 'cik-smart-search'),
            __('Analytics', 'cik-smart-search'),
            'manage_options',
            'edit.php?post_type=cik_search_log'
        );
    }
    
    /**
     * Read a text field from the submitted settings form.
     *
     * @param string $key     Field name.
     * @param string $default Value when the field is missing.
     * @return string Sanitized value.
     */
    private function posted_text($key, $default = '') {
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification -- verified in render_settings_page().
            return $default;
        }

        return sanitize_text_field(wp_unslash($_POST[$key])); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Read an integer field and clamp it to a range.
     *
     * @param string $key     Field name.
     * @param int    $default Value when the field is missing.
     * @param int    $min     Minimum allowed value.
     * @param int    $max     Maximum allowed value.
     * @return int
     */
    private function posted_int($key, $default, $min, $max) {
        $value = isset($_POST[$key]) ? (int) $_POST[$key] : $default; // phpcs:ignore WordPress.Security.NonceVerification

        return max($min, min($max, $value));
    }

    /**
     * Read a checkbox field.
     *
     * @param string $key Field name.
     * @return int 1 or 0.
     */
    private function posted_flag($key) {
        return isset($_POST[$key]) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Read a list of text values (repeater fields), dropping empty entries.
     *
     * @param string $key Field name.
     * @return array
     */
    private function posted_text_list($key) {
        $values = array();
        if (empty($_POST[$key]) || !is_array($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification
            return $values;
        }

        foreach (wp_unslash($_POST[$key]) as $text) { // phpcs:ignore WordPress.Security.NonceVerification
            $text = is_string($text) ? sanitize_text_field($text) : '';
            if ($text !== '') {
                $values[] = $text;
            }
        }

        return $values;
    }

    /**
     * Read a list of slugs and keep only the allowed ones.
     *
     * @param string $key     Field name.
     * @param array  $allowed Allowed slugs.
     * @return array
     */
    private function posted_slugs($key, $allowed) {
        if (empty($_POST[$key]) || !is_array($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification
            return array();
        }

        $slugs = array_map('sanitize_key', wp_unslash($_POST[$key])); // phpcs:ignore WordPress.Security.NonceVerification

        return array_values(array_intersect($slugs, $allowed));
    }
    /**
     * Languages offered for voice recognition (BCP 47 tag => label).
     *
     * "auto" follows the site/visitor locale (e.g. el_GR -> el-GR), so
     * multilingual sites get the right recognition language per page.
     *
     * @return array
     */
    private function get_voice_languages() {
        return array(
            'auto'  => __('Site language (automatic)', 'cik-smart-search'),
            'el-GR' => 'Ελληνικά (Greek)',
            'en-US' => 'English (US)',
            'en-GB' => 'English (UK)',
            'de-DE' => 'Deutsch (German)',
            'fr-FR' => 'Français (French)',
            'es-ES' => 'Español (Spanish)',
            'it-IT' => 'Italiano (Italian)',
        );
    }

    /**
     * Speech recognition language for the current request.
     *
     * @return string BCP 47 language tag.
     */
    private function get_voice_language() {
        $language = get_option('cik_search_voice_language', 'auto');
        if ($language === 'auto' || !array_key_exists($language, $this->get_voice_languages())) {
            $language = str_replace('_', '-', determine_locale());
        }

        return $language;
    }

    /**
     * Validate and store the settings form.
     *
     * Called from render_settings_page() after the capability and nonce checks.
     */
    private function save_settings() {
        // Search box texts.
        update_option('cik_search_placeholder', $this->posted_text('cik_search_placeholder'));
        update_option('cik_search_placeholder_en', $this->posted_text('cik_search_placeholder_en'));
        update_option('cik_search_title', $this->posted_text('cik_search_title'));
        update_option('cik_search_title_en', $this->posted_text('cik_search_title_en'));
        update_option('cik_search_results_title', $this->posted_text('cik_search_results_title', 'Search Results'));
        update_option('cik_search_results_title_en', $this->posted_text('cik_search_results_title_en'));
        update_option('cik_search_placeholders', $this->posted_text_list('cik_search_placeholders'));
        update_option('cik_search_placeholders_en', $this->posted_text_list('cik_search_placeholders_en'));

        // Search tags (repeater with label + search term per language).
        $tags = array();
        if (!empty($_POST['cik_search_tags']) && is_array($_POST['cik_search_tags'])) { // phpcs:ignore WordPress.Security.NonceVerification
            foreach (wp_unslash($_POST['cik_search_tags']) as $tag) { // phpcs:ignore WordPress.Security.NonceVerification
                if (!is_array($tag) || empty($tag['label']) || empty($tag['search_term'])) {
                    continue;
                }
                $tags[] = array(
                    'label'          => sanitize_text_field($tag['label']),
                    'search_term'    => sanitize_text_field($tag['search_term']),
                    'label_en'       => sanitize_text_field(isset($tag['label_en']) ? $tag['label_en'] : ''),
                    'search_term_en' => sanitize_text_field(isset($tag['search_term_en']) ? $tag['search_term_en'] : ''),
                );
            }
        }
        update_option('cik_search_tags', $tags);

        // Predictive search. Post types and taxonomies must be public ones.
        $public_post_types = array_values(array_diff(get_post_types(array('public' => true)), array('attachment')));
        $public_taxonomies = array_values(get_taxonomies(array('public' => true)));

        update_option('cik_search_enable_predictive', $this->posted_flag('cik_search_enable_predictive'));
        update_option('cik_search_predictive_min_chars', $this->posted_int('cik_search_predictive_min_chars', 3, 1, 10));
        update_option('cik_search_predictive_delay', $this->posted_int('cik_search_predictive_delay', 300, 100, 2000));
        update_option('cik_search_predictive_max_results', $this->posted_int('cik_search_predictive_max_results', 5, 1, 20));
        update_option('cik_search_predictive_post_types', $this->posted_slugs('cik_search_predictive_post_types', $public_post_types));
        update_option('cik_search_predictive_taxonomies', $this->posted_slugs('cik_search_predictive_taxonomies', $public_taxonomies));
        update_option('cik_search_prioritize_title_matches', $this->posted_flag('cik_search_prioritize_title_matches'));

        // Suggested keywords.
        update_option('cik_search_suggested_keywords', $this->posted_text_list('cik_search_suggested_keywords'));
        wp_cache_delete('cik_search_manual_keywords');

        $keywords_source = $this->posted_text('cik_search_keywords_source', 'mixed');
        update_option('cik_search_show_popular_searches', $this->posted_flag('cik_search_show_popular_searches'));
        update_option('cik_search_popular_searches_limit', $this->posted_int('cik_search_popular_searches_limit', 5, 0, 200));
        update_option('cik_search_suggested_keywords_limit', $this->posted_int('cik_search_suggested_keywords_limit', 30, 0, 200));
        update_option('cik_search_keywords_display_limit', $this->posted_int('cik_search_keywords_display_limit', 30, 0, 200));
        update_option('cik_search_keywords_source', in_array($keywords_source, array('mixed', 'popular_only'), true) ? $keywords_source : 'mixed');

        update_option('cik_search_predictive_show_images', $this->posted_flag('cik_search_predictive_show_images'));
        update_option('cik_search_predictive_show_excerpt', $this->posted_flag('cik_search_predictive_show_excerpt'));
        update_option('cik_search_predictive_show_matched_content', $this->posted_flag('cik_search_predictive_show_matched_content'));
        update_option('cik_search_predictive_show_match_location', $this->posted_flag('cik_search_predictive_show_match_location'));
        update_option('cik_search_predictive_search_title_only', $this->posted_flag('cik_search_predictive_search_title_only'));
        update_option('cik_search_predictive_cache_duration', $this->posted_int('cik_search_predictive_cache_duration', 5, 1, 60));

        // Voice search.
        $voice_color    = sanitize_hex_color($this->posted_text('cik_search_voice_listening_color', '#22c55e'));
        $voice_language = $this->posted_text('cik_search_voice_language', 'auto');
        update_option('cik_search_voice_auto_submit', $this->posted_flag('cik_search_voice_auto_submit'));
        update_option('cik_search_voice_listening_color', $voice_color ? $voice_color : '#22c55e');
        update_option('cik_search_voice_language', array_key_exists($voice_language, $this->get_voice_languages()) ? $voice_language : 'auto');

        // Analytics.
        update_option('cik_search_enable_analytics', $this->posted_flag('cik_search_enable_analytics'));
        update_option('cik_search_log_ip', $this->posted_flag('cik_search_log_ip'));
        update_option('cik_search_analytics_retention', $this->posted_int('cik_search_analytics_retention', 90, 0, 365));

        $this->clear_predictive_cache();
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Clear cache manually
        if (isset($_POST['cik_search_clear_cache'])) {
            check_admin_referer('cik_search_settings');
            $this->clear_predictive_cache();
            echo '<div class="notice notice-success"><p>' . esc_html__('Cache cleared successfully!', 'cik-smart-search') . '</p></div>';
        }

        // Save settings
        if (isset($_POST['cik_search_save'])) {
            check_admin_referer('cik_search_settings');
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully!', 'cik-smart-search') . '</p></div>';
        }
        
        // Get current values
        $search_placeholder = get_option('cik_search_placeholder', 'Search...');
        $search_placeholder_en = get_option('cik_search_placeholder_en', '');
        $search_placeholders = get_option('cik_search_placeholders', array());
        $search_placeholders_en = get_option('cik_search_placeholders_en', array());
        $search_title = get_option('cik_search_title', 'May interest you');
        $search_title_en = get_option('cik_search_title_en', '');
        $search_results_title = get_option('cik_search_results_title', 'Search Results');
        $search_results_title_en = get_option('cik_search_results_title_en', '');
        $search_tags = get_option('cik_search_tags', array());
        $enable_predictive = get_option('cik_search_enable_predictive', 1);
        $predictive_min_chars = get_option('cik_search_predictive_min_chars', 3);
        $predictive_delay = get_option('cik_search_predictive_delay', 300);
        $predictive_max_results = get_option('cik_search_predictive_max_results', 5);
        $predictive_post_types = get_option('cik_search_predictive_post_types', array('post', 'page'));
        $predictive_taxonomies = get_option('cik_search_predictive_taxonomies', array());
        $prioritize_title_matches = get_option('cik_search_prioritize_title_matches', 0);
        $suggested_keywords = get_option('cik_search_suggested_keywords', array());
        $show_popular_searches = get_option('cik_search_show_popular_searches', 1);
        $popular_searches_limit = get_option('cik_search_popular_searches_limit', 5);
        $suggested_keywords_limit = get_option('cik_search_suggested_keywords_limit', 30);
        $keywords_display_limit = get_option('cik_search_keywords_display_limit', 30);
        $keywords_source = get_option('cik_search_keywords_source', 'mixed');
        $predictive_show_images = get_option('cik_search_predictive_show_images', 1);
        $predictive_show_excerpt = get_option('cik_search_predictive_show_excerpt', 1);
        $predictive_show_matched_content = get_option('cik_search_predictive_show_matched_content', 1);
        $predictive_show_match_location = get_option('cik_search_predictive_show_match_location', 1);
        $search_title_only = get_option('cik_search_predictive_search_title_only', 0);
        $cache_duration = get_option('cik_search_predictive_cache_duration', 5);
        $voice_auto_submit = get_option('cik_search_voice_auto_submit', 0);
        $voice_listening_color = get_option('cik_search_voice_listening_color', '#22c55e');
        $voice_language = get_option('cik_search_voice_language', 'auto');
        $enable_analytics = get_option('cik_search_enable_analytics', 1);
        $log_ip = get_option('cik_search_log_ip', 0);
        $analytics_retention = get_option('cik_search_analytics_retention', 90);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('cik_search_settings'); ?>
                
                <h2 class="nav-tab-wrapper">
                    <a href="#tab-general" class="nav-tab nav-tab-active"><?php esc_html_e('General', 'cik-smart-search'); ?></a>
                    <a href="#tab-search-box" class="nav-tab"><?php esc_html_e('Search Box', 'cik-smart-search'); ?></a>
                    <a href="#tab-predictive" class="nav-tab"><?php esc_html_e('Predictive Search', 'cik-smart-search'); ?></a>
                    <a href="#tab-voice" class="nav-tab"><?php esc_html_e('Voice Search', 'cik-smart-search'); ?></a>
                    <a href="#tab-analytics" class="nav-tab"><?php esc_html_e('Analytics', 'cik-smart-search'); ?></a>
                </h2>
                
                <!-- Tab 1: General -->
                <div id="tab-general" class="tab-content">
                    <h3><?php esc_html_e('Search Content Settings', 'cik-smart-search'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Post Types to Search', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <?php
                                $post_types = get_post_types(array('public' => true), 'objects');
                                foreach ($post_types as $post_type) {
                                    if ($post_type->name === 'attachment') continue;
                                    $checked = in_array($post_type->name, (array) $predictive_post_types, true);
                                    ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="cik_search_predictive_post_types[]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked($checked); ?>>
                                        <?php echo esc_html($post_type->labels->name); ?>
                                    </label>
                                    <?php
                                }
                                ?>
                                <p class="description"><?php esc_html_e('Select which post types to include in search results', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Taxonomies to Search', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <?php
                                $taxonomies = get_taxonomies(array('public' => true), 'objects');
                                foreach ($taxonomies as $taxonomy) {
                                    // Skip some built-in taxonomies that don't make sense to search
                                    if (in_array($taxonomy->name, array('post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area'))) {
                                        continue;
                                    }
                                    $checked = in_array($taxonomy->name, (array) $predictive_taxonomies, true);
                                    ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="cik_search_predictive_taxonomies[]" value="<?php echo esc_attr($taxonomy->name); ?>" <?php checked($checked); ?>>
                                        <?php echo esc_html($taxonomy->labels->name); ?>
                                        <span style="color: #666; font-size: 11px;">(<?php echo esc_html($taxonomy->name); ?>)</span>
                                    </label>
                                    <?php
                                }
                                ?>
                                <p class="description"><?php esc_html_e('Select which taxonomies (categories, tags, etc.) to include in search. When enabled, taxonomy terms will also appear in results.', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_prioritize_title_matches"><?php esc_html_e('Results Order', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_prioritize_title_matches" name="cik_search_prioritize_title_matches" value="1" <?php checked($prioritize_title_matches, 1); ?>>
                                    <?php esc_html_e('Prioritize title matches over content matches', 'cik-smart-search'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('When enabled, results matching in the title will appear first, followed by content matches. When disabled, results are ordered by WordPress relevance score.', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3 style="margin-top: 40px;"><?php esc_html_e('Suggested Keywords', 'cik-smart-search'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Manual Keywords', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <div id="suggested-keywords-list">
                                    <?php if (!empty($suggested_keywords)): ?>
                                        <?php foreach ($suggested_keywords as $index => $keyword): ?>
                                            <div class="keyword-item" style="margin-bottom: 8px; display: flex; gap: 8px; align-items: center;">
                                                <input type="text" name="cik_search_suggested_keywords[]" value="<?php echo esc_attr($keyword); ?>" class="regular-text" placeholder="<?php esc_attr_e('Enter keyword', 'cik-smart-search'); ?>">
                                                <button type="button" class="button remove-keyword" style="color: #dc2626;"><?php esc_html_e('Remove', 'cik-smart-search'); ?></button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" id="add-keyword" class="button" style="margin-top: 8px;"><?php esc_html_e('+ Add Keyword', 'cik-smart-search'); ?></button>
                                <p class="description"><?php esc_html_e('Suggested keywords shown in place of the tags while the visitor types (matching is accent-insensitive). Examples: "shoes", "summer offers", "gift cards".', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_show_popular_searches"><?php esc_html_e('Show Popular Searches', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_show_popular_searches" name="cik_search_show_popular_searches" value="1" <?php checked($show_popular_searches, 1); ?>>
                                    <?php esc_html_e('Show most popular searches from analytics', 'cik-smart-search'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('When enabled, displays trending searches based on real user data (requires Analytics)', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_popular_searches_limit"><?php esc_html_e('Popular Searches Limit', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_popular_searches_limit" name="cik_search_popular_searches_limit" value="<?php echo esc_attr($popular_searches_limit); ?>" min="0" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Maximum number of popular searches to fetch from analytics (0 = unlimited)', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_suggested_keywords_limit"><?php esc_html_e('Max Suggested Keywords (fetch)', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_suggested_keywords_limit" name="cik_search_suggested_keywords_limit" value="<?php echo esc_attr($suggested_keywords_limit); ?>" min="0" max="200" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Total keywords to prepare in the background (popular first, then manual). Default: 30. Set 0 for unlimited.', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_keywords_display_limit"><?php esc_html_e('Max Keywords Displayed', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_keywords_display_limit" name="cik_search_keywords_display_limit" value="<?php echo esc_attr($keywords_display_limit); ?>" min="0" max="200" style="width: 80px;">
                                <p class="description"><?php esc_html_e('How many keywords actually appear in the search box UI. Applies both to the initial tags and when typing. Default: 30. Set 0 for unlimited.', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_keywords_source"><?php esc_html_e('Keywords Source', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <select id="cik_search_keywords_source" name="cik_search_keywords_source">
                                    <option value="mixed" <?php selected($keywords_source, 'mixed'); ?>><?php esc_html_e('Manual Keywords + Popular Searches', 'cik-smart-search'); ?></option>
                                    <option value="popular_only" <?php selected($keywords_source, 'popular_only'); ?>><?php esc_html_e('Only Popular Searches from Analytics', 'cik-smart-search'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Choose whether to show manual keywords combined with popular searches, or only show popular searches from analytics', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        // Add keyword
                        $('#add-keyword').on('click', function() {
                            var html = '<div class="keyword-item" style="margin-bottom: 8px; display: flex; gap: 8px; align-items: center;">' +
                                '<input type="text" name="cik_search_suggested_keywords[]" value="" class="regular-text" placeholder="<?php esc_attr_e('Enter keyword', 'cik-smart-search'); ?>">' +
                                '<button type="button" class="button remove-keyword" style="color: #dc2626;"><?php esc_html_e('Remove', 'cik-smart-search'); ?></button>' +
                                '</div>';
                            $('#suggested-keywords-list').append(html);
                        });
                        
                        // Remove keyword
                        $(document).on('click', '.remove-keyword', function() {
                            $(this).closest('.keyword-item').remove();
                        });
                    });
                    </script>
                </div>
                
                <!-- Tab 2: Search Box -->
                <div id="tab-search-box" class="tab-content" style="display: none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Search Placeholder (Fallback)', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:3px;">GR</label>
                                        <input type="text" id="cik_search_placeholder" name="cik_search_placeholder" value="<?php echo esc_attr($search_placeholder); ?>" class="regular-text">
                                    </div>
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:3px;">EN</label>
                                        <input type="text" id="cik_search_placeholder_en" name="cik_search_placeholder_en" value="<?php echo esc_attr($search_placeholder_en); ?>" class="regular-text">
                                    </div>
                                </div>
                                <p class="description"><?php esc_html_e('This is used as fallback if animated placeholders are not set', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Animated Placeholders', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:6px;">GR</label>
                                        <div id="placeholders-container">
                                            <?php foreach ($search_placeholders as $index => $placeholder_text) : ?>
                                                <div class="placeholder-row" style="margin-bottom:8px;">
                                                    <input type="text" name="cik_search_placeholders[<?php echo absint($index); ?>]" value="<?php echo esc_attr($placeholder_text); ?>" placeholder="<?php esc_attr_e('Placeholder text', 'cik-smart-search'); ?>" style="width:300px;">
                                                    <button type="button" class="button remove-placeholder">&times;</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" id="add-placeholder" class="button"><?php esc_html_e('Add Placeholder', 'cik-smart-search'); ?></button>
                                    </div>
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:6px;">EN</label>
                                        <div id="placeholders-en-container">
                                            <?php foreach ($search_placeholders_en as $index => $placeholder_text) : ?>
                                                <div class="placeholder-en-row" style="margin-bottom:8px;">
                                                    <input type="text" name="cik_search_placeholders_en[<?php echo absint($index); ?>]" value="<?php echo esc_attr($placeholder_text); ?>" placeholder="<?php esc_attr_e('Placeholder text EN', 'cik-smart-search'); ?>" style="width:300px;">
                                                    <button type="button" class="button remove-placeholder-en">&times;</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" id="add-placeholder-en" class="button"><?php esc_html_e('Add Placeholder', 'cik-smart-search'); ?></button>
                                    </div>
                                </div>
                                <p class="description" style="margin-top:8px;"><?php esc_html_e('Add multiple placeholder texts that will animate in a typewriter loop effect', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Tags Section Title', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:3px;">GR</label>
                                        <input type="text" id="cik_search_title" name="cik_search_title" value="<?php echo esc_attr($search_title); ?>" class="regular-text">
                                    </div>
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:3px;">EN</label>
                                        <input type="text" id="cik_search_title_en" name="cik_search_title_en" value="<?php echo esc_attr($search_title_en); ?>" class="regular-text">
                                    </div>
                                </div>
                                <p class="description"><?php esc_html_e('Title above the search tags', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Search Results Title', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:3px;">GR</label>
                                        <input type="text" id="cik_search_results_title" name="cik_search_results_title" value="<?php echo esc_attr($search_results_title); ?>" class="regular-text">
                                    </div>
                                    <div>
                                        <label style="font-size:11px;color:#666;font-weight:600;display:block;margin-bottom:3px;">EN</label>
                                        <input type="text" id="cik_search_results_title_en" name="cik_search_results_title_en" value="<?php echo esc_attr($search_results_title_en); ?>" class="regular-text">
                                    </div>
                                </div>
                                <p class="description"><?php esc_html_e('Title above the predictive search results (shown when typing 3+ characters)', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Search Tags', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <div id="search-tags-container"> 
                                    <?php if (!empty($search_tags)) : ?>
                                        <div style="display:flex;gap:4px;margin-bottom:6px;font-size:11px;color:#666;font-weight:600;">
                                            <span style="width:165px;"><?php esc_html_e('Label (GR)', 'cik-smart-search'); ?></span>
                                            <span style="width:165px;"><?php esc_html_e('Search Term (GR)', 'cik-smart-search'); ?></span>
                                            <span style="width:165px;"><?php esc_html_e('Label (EN)', 'cik-smart-search'); ?></span>
                                            <span style="width:165px;"><?php esc_html_e('Search Term (EN)', 'cik-smart-search'); ?></span>
                                        </div>
                                        <?php foreach ($search_tags as $index => $tag) : ?>
                                            <div class="search-tag-row" style="margin-bottom: 10px; display:flex; gap:4px; align-items:center;">
                                                <input type="text" name="cik_search_tags[<?php echo absint($index); ?>][label]" value="<?php echo esc_attr($tag['label']); ?>" placeholder="<?php esc_attr_e('Label GR', 'cik-smart-search'); ?>" style="width: 160px;">
                                                <input type="text" name="cik_search_tags[<?php echo absint($index); ?>][search_term]" value="<?php echo esc_attr($tag['search_term']); ?>" placeholder="<?php esc_attr_e('Term GR', 'cik-smart-search'); ?>" style="width: 160px;">
                                                <input type="text" name="cik_search_tags[<?php echo absint($index); ?>][label_en]" value="<?php echo esc_attr($tag['label_en'] ?? ''); ?>" placeholder="<?php esc_attr_e('Label EN', 'cik-smart-search'); ?>" style="width: 160px;">
                                                <input type="text" name="cik_search_tags[<?php echo absint($index); ?>][search_term_en]" value="<?php echo esc_attr($tag['search_term_en'] ?? ''); ?>" placeholder="<?php esc_attr_e('Term EN', 'cik-smart-search'); ?>" style="width: 160px;">
                                                <button type="button" class="button remove-tag">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif;
                                    ?>
                                </div>
                                <button type="button" id="add-search-tag" class="button"><?php esc_html_e('Add Tag', 'cik-smart-search'); ?></button>
                                <p class="description"><?php esc_html_e('Add search tags that will appear below the search box', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab 2: Predictive Search -->
                <div id="tab-predictive" class="tab-content" style="display: none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="cik_search_enable_predictive"><?php esc_html_e('Enable Predictive Search', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_enable_predictive" name="cik_search_enable_predictive" value="1" <?php checked($enable_predictive, 1); ?>>
                                    <?php esc_html_e('Show live search suggestions as user types', 'cik-smart-search'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_min_chars"><?php esc_html_e('Minimum Characters', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_predictive_min_chars" name="cik_search_predictive_min_chars" value="<?php echo esc_attr($predictive_min_chars); ?>" min="1" max="10" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Start showing suggestions after X characters typed', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_delay"><?php esc_html_e('Search Delay (ms)', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_predictive_delay" name="cik_search_predictive_delay" value="<?php echo esc_attr($predictive_delay); ?>" min="100" max="2000" step="100" style="width: 100px;">
                                <p class="description"><?php esc_html_e('Delay before triggering search (prevents too many requests)', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_max_results"><?php esc_html_e('Maximum Results', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_predictive_max_results" name="cik_search_predictive_max_results" value="<?php echo esc_attr($predictive_max_results); ?>" min="1" max="20" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Number of suggestions to display', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_show_images"><?php esc_html_e('Show Featured Images', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_predictive_show_images" name="cik_search_predictive_show_images" value="1" <?php checked($predictive_show_images, 1); ?>>
                                    <?php esc_html_e('Display featured image thumbnails in suggestions', 'cik-smart-search'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_show_excerpt"><?php esc_html_e('Show Excerpt', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_predictive_show_excerpt" name="cik_search_predictive_show_excerpt" value="1" <?php checked($predictive_show_excerpt, 1); ?>>
                                    <?php esc_html_e('Display post excerpt in suggestions', 'cik-smart-search'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_show_matched_content"><?php esc_html_e('Show Matched Content', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_predictive_show_matched_content" name="cik_search_predictive_show_matched_content" value="1" <?php checked($predictive_show_matched_content, 1); ?>>
                                    <?php esc_html_e('Display snippet with highlighted search terms', 'cik-smart-search'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Shows where in the content the search term was found', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_show_match_location"><?php esc_html_e('Show Match Location', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_predictive_show_match_location" name="cik_search_predictive_show_match_location" value="1" <?php checked($predictive_show_match_location, 1); ?>>
                                    <?php esc_html_e('Display "Found in title" or "Found in content" label', 'cik-smart-search'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('If unchecked, only shows the highlighted snippet without location label', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_search_title_only"><?php esc_html_e('Search Title Only', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_predictive_search_title_only" name="cik_search_predictive_search_title_only" value="1" <?php checked($search_title_only, 1); ?>>
                                    <?php esc_html_e('Search only in post titles (faster but less comprehensive)', 'cik-smart-search'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_predictive_cache_duration"><?php esc_html_e('Cache Duration', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_predictive_cache_duration" name="cik_search_predictive_cache_duration" value="<?php echo esc_attr($cache_duration); ?>" min="1" max="60" class="small-text">
                                <?php esc_html_e('minutes', 'cik-smart-search'); ?>
                                <p class="description"><?php esc_html_e('How long to cache search results (improves performance)', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Clear Cache', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <button type="submit" name="cik_search_clear_cache" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear the predictive search cache?', 'cik-smart-search'); ?>');">
                                    <?php esc_html_e('Clear Predictive Search Cache', 'cik-smart-search'); ?>
                                </button>
                                <p class="description"><?php esc_html_e('Clear cached search results to see immediate changes', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab 3: Voice Search -->
                <div id="tab-voice" class="tab-content" style="display: none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="cik_search_voice_auto_submit"><?php esc_html_e('Auto Submit', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_voice_auto_submit" name="cik_search_voice_auto_submit" value="1" <?php checked($voice_auto_submit, 1); ?>>
                                    <?php esc_html_e('Automatically submit the search form when voice input is complete', 'cik-smart-search'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_voice_listening_color"><?php esc_html_e('Listening Background Color', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="cik_search_voice_listening_color" name="cik_search_voice_listening_color" value="<?php echo esc_attr($voice_listening_color); ?>" class="color-picker">
                                <p class="description"><?php esc_html_e('Background color of the microphone icon while listening', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_voice_language"><?php esc_html_e('Voice Language', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <select id="cik_search_voice_language" name="cik_search_voice_language">
                                    <?php foreach ($this->get_voice_languages() as $code => $label) : ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($voice_language, $code); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Language for voice recognition', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab 4: Analytics -->
                <div id="tab-analytics" class="tab-content" style="display: none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="cik_search_enable_analytics"><?php esc_html_e('Enable Search Analytics', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_enable_analytics" name="cik_search_enable_analytics" value="1" <?php checked($enable_analytics, 1); ?>>
                                    <?php esc_html_e('Track all search queries in the database. View analytics in "Analytics" menu.', 'cik-smart-search'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_log_ip"><?php esc_html_e('Log User IP', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cik_search_log_ip" name="cik_search_log_ip" value="1" <?php checked($log_ip, 1); ?>>
                                    <?php esc_html_e('Store user IP addresses for analytics (check GDPR compliance)', 'cik-smart-search'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cik_search_analytics_retention"><?php esc_html_e('Data Retention', 'cik-smart-search'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="cik_search_analytics_retention" name="cik_search_analytics_retention" value="<?php echo esc_attr($analytics_retention); ?>" min="0" max="365" style="width: 80px;">
                                <?php esc_html_e('days', 'cik-smart-search'); ?>
                                <p class="description"><?php esc_html_e('Delete search logs older than X days (0 = keep forever)', 'cik-smart-search'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(__('Save Settings', 'cik-smart-search'), 'primary', 'cik_search_save'); ?>
            </form>
        </div>
        
        <style>
            .nav-tab-wrapper { margin-bottom: 20px; }
            .tab-content { background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-top: 0; }
            .search-tag-row, .placeholder-row { display: flex; gap: 10px; align-items: center; }
            .remove-tag, .remove-placeholder { color: #dc3232; font-size: 20px; line-height: 1; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.tab-content').hide();
                $(target).show();
            });
            
            // Add placeholder (GR)
            var placeholderIndex = <?php echo count($search_placeholders); ?>;
            $('#add-placeholder').on('click', function() {
                var html = '<div class="placeholder-row" style="margin-bottom:8px;">' +
                    '<input type="text" name="cik_search_placeholders[' + placeholderIndex + ']" placeholder="<?php esc_attr_e('Placeholder text', 'cik-smart-search'); ?>" style="width:300px;">' +
                    '<button type="button" class="button remove-placeholder">&times;</button>' +
                    '</div>';
                $('#placeholders-container').append(html);
                placeholderIndex++;
            });

            $(document).on('click', '.remove-placeholder', function() {
                $(this).closest('.placeholder-row').remove();
            });

            // Add placeholder (EN)
            var placeholderEnIndex = <?php echo count($search_placeholders_en); ?>;
            $('#add-placeholder-en').on('click', function() {
                var html = '<div class="placeholder-en-row" style="margin-bottom:8px;">' +
                    '<input type="text" name="cik_search_placeholders_en[' + placeholderEnIndex + ']" placeholder="<?php esc_attr_e('Placeholder text EN', 'cik-smart-search'); ?>" style="width:300px;">' +
                    '<button type="button" class="button remove-placeholder-en">&times;</button>' +
                    '</div>';
                $('#placeholders-en-container').append(html);
                placeholderEnIndex++;
            });

            $(document).on('click', '.remove-placeholder-en', function() {
                $(this).closest('.placeholder-en-row').remove();
            });
            
            // Add tag
            var tagIndex = <?php echo count($search_tags); ?>;
            $('#add-search-tag').on('click', function() {
                var html = '<div class="search-tag-row" style="margin-bottom: 10px; display:flex; gap:4px; align-items:center;">' +
                    '<input type="text" name="cik_search_tags[' + tagIndex + '][label]" placeholder="<?php esc_attr_e('Label GR', 'cik-smart-search'); ?>" style="width: 160px;">' +
                    '<input type="text" name="cik_search_tags[' + tagIndex + '][search_term]" placeholder="<?php esc_attr_e('Term GR', 'cik-smart-search'); ?>" style="width: 160px;">' +
                    '<input type="text" name="cik_search_tags[' + tagIndex + '][label_en]" placeholder="<?php esc_attr_e('Label EN', 'cik-smart-search'); ?>" style="width: 160px;">' +
                    '<input type="text" name="cik_search_tags[' + tagIndex + '][search_term_en]" placeholder="<?php esc_attr_e('Term EN', 'cik-smart-search'); ?>" style="width: 160px;">' +
                    '<button type="button" class="button remove-tag">×</button>' +
                    '</div>';
                $('#search-tags-container').append(html);
                tagIndex++;
            });
            
            // Remove tag
            $(document).on('click', '.remove-tag', function() {
                $(this).closest('.search-tag-row').remove();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render search box via shortcode
     */
    public function render_search_box($atts) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'wrapper_class' => 'cik-search-position',
        ), $atts, 'cik_search');

        // Scripts are only loaded on pages that contain a search box.
        $this->enqueue_scripts();
        
        // Get options
        $search_title = get_option('cik_search_title', __('May interest you', 'cik-smart-search'));
        $search_results_title = get_option('cik_search_results_title', __('Search Results', 'cik-smart-search'));
        $search_placeholder = get_option('cik_search_placeholder', __('Search...', 'cik-smart-search'));
        $search_tags = get_option('cik_search_tags', array());

        // WPML: pick EN fields if current language is English
        $current_lang = apply_filters('wpml_current_language', null);
        if ($current_lang === 'en') {
            $title_en = get_option('cik_search_title_en', '');
            if (!empty($title_en)) $search_title = $title_en;

            $results_title_en = get_option('cik_search_results_title_en', '');
            if (!empty($results_title_en)) $search_results_title = $results_title_en;

            $placeholder_en = get_option('cik_search_placeholder_en', '');
            if (!empty($placeholder_en)) $search_placeholder = $placeholder_en;

            $search_tags = array_map(function($tag) {
                return array(
                    'label'       => !empty($tag['label_en']) ? $tag['label_en'] : $tag['label'],
                    'search_term' => !empty($tag['search_term_en']) ? $tag['search_term_en'] : $tag['search_term'],
                );
            }, $search_tags);
        }
        
        // Apply display limit to static tags
        $display_limit = intval(get_option('cik_search_keywords_display_limit', 30));
        if ($display_limit > 0 && count($search_tags) > $display_limit) {
            $search_tags = array_slice($search_tags, 0, $display_limit);
        }
        
        // Start output buffering
        ob_start();
        
        // Load template
        $template_path = CIK_SEARCH_PATH . 'templates/search-box.php';
        
        // Allow theme override
        $theme_template = locate_template('cik-smart-search/search-box.php');
        if ($theme_template) {
            $template_path = $theme_template;
        }
        
        if (file_exists($template_path)) {
            include $template_path;
        }
        
        return ob_get_clean();
    }
    
    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link  = '<a href="' . esc_url(admin_url('admin.php?page=cik-smart-search')) . '">' . esc_html__('Settings', 'cik-smart-search') . '</a>';
        $analytics_link = '<a href="' . esc_url(admin_url('edit.php?post_type=cik_search_log')) . '" style="color:#2271b1;font-weight:600;">' . esc_html__('Analytics', 'cik-smart-search') . '</a>';
        array_unshift($links, $analytics_link, $settings_link);
        return $links;
    }
    
    /**
     * AJAX handler for predictive search
     */
    public function ajax_predictive_search() {
        // Verify nonce
        check_ajax_referer('cik_search_predictive_search', 'nonce');
        
        // Public, read-only endpoint: validate and bound the input instead of
        // trusting it. Queries are trimmed and capped at 100 characters.
        $query = isset($_GET['query']) ? sanitize_text_field(wp_unslash($_GET['query'])) : '';
        $query = trim(mb_substr($query, 0, 100, 'UTF-8'));

        if ($query === '') {
            wp_send_json_error(array('message' => 'Empty query'), 400);
        }
        
        $cache_duration = max(1, (int) get_option('cik_search_predictive_cache_duration', 5));
        $max_results    = max(1, min(20, (int) get_option('cik_search_predictive_max_results', 5)));
        $post_types     = (array) get_option('cik_search_predictive_post_types', array('post', 'page'));
        $taxonomies     = (array) get_option('cik_search_predictive_taxonomies', array());

        // Results are cached per query, language and settings. The cache version
        // is bumped whenever content or settings change (see clear_predictive_cache()),
        // which also works with persistent object caches.
        $cache_key = 'cik_search_results_' . md5(wp_json_encode(array(
            $query,
            $this->get_current_language(),
            $post_types,
            $taxonomies,
            (int) get_option('cik_search_cache_version', 1),
        )));

        $cached_results = get_transient($cache_key);
        if (is_array($cached_results)) {
            wp_send_json_success($cached_results);
        }

        $show_images = get_option('cik_search_predictive_show_images', 1);
        $show_excerpt = get_option('cik_search_predictive_show_excerpt', 1);
        $search_title_only = get_option('cik_search_predictive_search_title_only', 0);
        
        // Optimized search query - search only in titles for speed (if enabled)
        if ($search_title_only) {
            add_filter('posts_search', array($this, 'search_by_title_only'), 500, 2);
        }

        // Greeklish conversion is intentionally not applied to the post query:
        // it caused false positives (e.g. "Oil" -> "οιλ"). It is only used for
        // highlighting and for ordering title matches first.
        $args = array(
            's'                      => $query,
            'post_type'              => $post_types,
            'posts_per_page'         => $max_results,
            'post_status'            => 'publish',
            'orderby'                => 'relevance',
            'order'                  => 'DESC',
            'no_found_rows'          => true,  // No SQL_CALC_FOUND_ROWS: we never paginate.
            'update_post_meta_cache' => true,  // One batched query for thumbnails/meta instead of one per post.
            'update_post_term_cache' => false,
            'ignore_sticky_posts'    => true,
            'suppress_filters'       => false,
        );
        
        /**
         * Filter the WP_Query arguments of the predictive search.
         *
         * Useful to add a custom post type (e.g. an index of PDF documents)
         * or a meta/tax query, such as restricting results by language.
         *
         * @param array  $args  WP_Query arguments.
         * @param string $query Sanitized search string.
         */
        $args = apply_filters('cik_search_query_args', $args, $query);

        $search_query = new WP_Query($args);
        
        // Remove filter after query
        if ($search_title_only) {
            remove_filter('posts_search', array($this, 'search_by_title_only'), 500);
        }
        
        $results = array();
        
        if ($search_query->have_posts()) {
            while ($search_query->have_posts()) {
                $search_query->the_post();
                
                $post_id = get_the_ID();
                $post_type = get_post_type();
                
                $result = array(
                    'title'     => $this->to_plain_text(get_the_title()),
                    'url'       => esc_url_raw(get_permalink()),
                    'post_type' => $post_type,
                    'type'      => $this->get_post_type_label($post_type),
                );
                
                // Add featured image if enabled (optimized - no full post object)
                if ($show_images) {
                    $thumb_id = get_post_thumbnail_id($post_id);
                    if ($thumb_id) {
                        $result['image'] = esc_url_raw(wp_get_attachment_image_url($thumb_id, 'thumbnail'));
                    }
                }
                
                // Add excerpt if enabled (optimized)
                if ($show_excerpt) {
                    $excerpt = has_excerpt() ? get_the_excerpt() : wp_trim_words(get_the_content(), 20, '...');
                    if (!empty($excerpt)) {
                        $result['excerpt'] = wp_trim_words($this->to_plain_text($excerpt), 15, '...');
                    }
                }
                
                // Add matched content snippet with highlighting
                $show_matched_content = get_option('cik_search_predictive_show_matched_content', 1);
                $show_match_location = get_option('cik_search_predictive_show_match_location', 1);
                
                // Get full content - use $post directly for WooCommerce compatibility
                global $post;
                $full_content = $post->post_content;
                
                // Also include short description for WooCommerce products
                if (get_post_type() === 'product') {
                    $short_desc = get_post_meta($post_id, '_excerpt', true);
                    if (empty($short_desc)) {
                        $short_desc = $post->post_excerpt;
                    }
                    $full_content = $short_desc . ' ' . $full_content;
                }
                
                // Add matched content if enabled
                if ($show_matched_content) {
                    $matched = $this->get_matched_content($full_content, $this->to_plain_text(get_the_title()), $query, $show_match_location);
                    $result['matched_content'] = $matched;
                }
                
                // Check if match is in title (for prioritization)
                $result['match_in_title'] = $this->has_match_in_title(get_the_title(), $query);
                
                /**
                 * Filter a single predictive result before it is returned.
                 *
                 * For example, point 'url' to a file instead of the post permalink.
                 * Text fields are rendered with textContent; 'matched_content'
                 * must stay escaped HTML.
                 *
                 * @param array   $result Result data.
                 * @param WP_Post $post   The matched post.
                 */
                $result = apply_filters('cik_search_result', $result, get_post());

                $results[] = $result;
            }
            wp_reset_postdata();
        }
        
        // Search in taxonomies if enabled
        if (!empty($taxonomies) && count($results) < $max_results) {
            $taxonomy_results = $this->search_taxonomies($query, $taxonomies, $max_results - count($results));
            $results = array_merge($results, $taxonomy_results);
        }
        
        // Prioritize title matches if enabled
        $prioritize_title_matches = get_option('cik_search_prioritize_title_matches', 0);
        if ($prioritize_title_matches) {
            usort($results, function($a, $b) {
                // Title matches come first
                $a_title_match = isset($a['match_in_title']) ? $a['match_in_title'] : false;
                $b_title_match = isset($b['match_in_title']) ? $b['match_in_title'] : false;
                
                if ($a_title_match && !$b_title_match) {
                    return -1; // a comes first
                } elseif (!$a_title_match && $b_title_match) {
                    return 1; // b comes first
                } else {
                    return 0; // keep original order
                }
            });
        }
        
        // Remove match_in_title flag before sending (internal use only)
        foreach ($results as &$result) {
            unset($result['match_in_title']);
        }
        
        // Cache results (duration in minutes from settings)
        set_transient($cache_key, $results, $cache_duration * MINUTE_IN_SECONDS);
        
        wp_send_json_success($results);
    }
    
    /**
     * Remove Greek accents for matching
     * 
     * @param string $str String with accents
     * @return string String without accents
     */
    private function remove_greek_accents($str) {
        $accents = array(
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
            'ΐ' => 'ι', 'ΰ' => 'υ', 'ϊ' => 'ι', 'ϋ' => 'υ'
        );
        return str_replace(array_keys($accents), array_values($accents), $str);
    }
    
    /**
     * Get popular searches from analytics
     * 
     * @param int $limit Number of results (0 = unlimited)
     * @return array Array of popular searches
     */
    private function get_popular_searches($limit = 5) {
        // Try to get from cache first (1 hour cache for performance)
        $cache_key = 'cik_search_popular_searches_' . $limit;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        // Get searches from last 30 days with search count from meta
        $date_from = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        
        // Query using _search_count and _results_count meta fields, ordered by searches then results
        if ($limit > 0) {
            $query = $wpdb->prepare("
                SELECT p.post_title as query,
                       COALESCE(CAST(pm_count.meta_value AS UNSIGNED), 1) as count,
                       COALESCE(CAST(pm_results.meta_value AS UNSIGNED), 0) as results
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_count ON p.ID = pm_count.post_id AND pm_count.meta_key = '_search_count'
                LEFT JOIN {$wpdb->postmeta} pm_results ON p.ID = pm_results.post_id AND pm_results.meta_key = '_results_count'
                WHERE p.post_type = 'cik_search_log'
                AND p.post_status = 'publish'
                AND p.post_modified_gmt >= %s
                AND p.post_title != ''
                ORDER BY count DESC, results DESC, p.post_modified DESC
                LIMIT %d
            ", $date_from, $limit);
        } else {
            $query = $wpdb->prepare("
                SELECT p.post_title as query,
                       COALESCE(CAST(pm_count.meta_value AS UNSIGNED), 1) as count,
                       COALESCE(CAST(pm_results.meta_value AS UNSIGNED), 0) as results
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_count ON p.ID = pm_count.post_id AND pm_count.meta_key = '_search_count'
                LEFT JOIN {$wpdb->postmeta} pm_results ON p.ID = pm_results.post_id AND pm_results.meta_key = '_results_count'
                WHERE p.post_type = 'cik_search_log'
                AND p.post_status = 'publish'
                AND p.post_modified_gmt >= %s
                AND p.post_title != ''
                ORDER BY count DESC, results DESC, p.post_modified DESC
            ", $date_from);
        }
        
        // Execute with timeout protection
        $results = $wpdb->get_results($query, ARRAY_A);
        
        $results = $results ?: array();
        
        // Cache for 1 hour (3600 seconds) for better performance
        set_transient($cache_key, $results, 3600);
        
        return $results;
    }
    
    /**
     * Search in taxonomy terms (categories, tags, etc.)
     * 
     * @param string $query Search query
     * @param array $taxonomies Taxonomies to search in
     * @param int $limit Maximum number of results
     * @return array Array of taxonomy term results
     */
    private function search_taxonomies($query, $taxonomies, $limit = 5) {
        $results = array();
        
        if (empty($taxonomies) || empty($query)) {
            return $results;
        }
        
        // Search for terms that match the query
        $terms = get_terms(array(
            'taxonomy' => $taxonomies,
            'hide_empty' => true,
            'search' => $query,
            'number' => $limit,
        ));
        
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $taxonomy_obj = get_taxonomy($term->taxonomy);
                $term_link    = get_term_link($term);
                if (is_wp_error($term_link)) {
                    continue;
                }

                $result = array(
                    'title'         => $this->to_plain_text($term->name),
                    'url'           => esc_url_raw($term_link),
                    'taxonomy_slug' => $term->taxonomy,
                    'post_type'     => $term->taxonomy,
                    'type'          => $taxonomy_obj ? $this->to_plain_text($taxonomy_obj->labels->singular_name) : '',
                    'taxonomy'      => true,
                    'count'         => (int) $term->count,
                );
                
                // Add description as excerpt if available
                if (!empty($term->description)) {
                    $result['excerpt'] = wp_trim_words($this->to_plain_text($term->description), 15, '...');
                }
                
                // Highlight matched content in term name
                $show_matched_content = get_option('cik_search_predictive_show_matched_content', 1);
                if ($show_matched_content) {
                    $matched = $this->highlight_text($this->to_plain_text($term->name), array($query));
                    $result['matched_content'] = $matched;
                }
                
                // Taxonomies always match in "title" (term name)
                $result['match_in_title'] = true;
                
                $results[] = $result;
                
                if (count($results) >= $limit) {
                    break;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Get matched content snippet with highlighting
     */
    private function get_matched_content($content, $title, $query, $show_location = true) {
        // Remove HTML tags
        $content    = $this->to_plain_text($content);
        $title_text = $this->to_plain_text($title);
        
        // Prepare search terms
        $search_terms = array_filter(explode(' ', $query));
        
        // Add Greeklish-to-Greek conversions to search terms (only for Greek language and likely Greeklish)
        $current_lang = $this->get_current_language();
        $expanded_terms = array();
        foreach ($search_terms as $term) {
            $expanded_terms[] = $term; // Original term
            
            // Check if current language is Greek AND term is likely Greeklish
            if ($current_lang === 'el' && $this->is_likely_greeklish($term)) {
                $greek_version = $this->greeklish_to_greek($term);
                if ($greek_version !== $term) {
                    $expanded_terms[] = $greek_version; // Add Greek conversion
                }
            }
        }
        $search_terms = array_unique($expanded_terms);
        
        // If no search terms, return title
        if (empty($search_terms)) {
            if ($show_location) {
                return '<strong>' . esc_html__('Result:', 'cik-smart-search') . '</strong> ' . esc_html($title_text);
            }
            return esc_html($title_text);
        }
        
        $best_match = '';
        $max_matches = 0;
        $found_in_title = false;
        
        // Check if text contains Greek characters
        $has_greek = preg_match('/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $title_text . $content);
        
        // Create versions for matching (accent-free for Greek, lowercase for others)
        if ($has_greek) {
            $title_no_accents = $this->remove_greek_accents(mb_strtolower($title_text, 'UTF-8'));
            $content_no_accents = $this->remove_greek_accents(mb_strtolower($content, 'UTF-8'));
        } else {
            $title_no_accents = mb_strtolower($title_text, 'UTF-8');
            $content_no_accents = mb_strtolower($content, 'UTF-8');
        }
        
        // Check if ANY query term matches in title
        foreach ($search_terms as $term) {
            if (mb_strlen($term) >= 2) {
                $term_no_accents = $has_greek 
                    ? $this->remove_greek_accents(mb_strtolower($term, 'UTF-8'))
                    : mb_strtolower($term, 'UTF-8');
                    
                // Search in normalized version
                if (mb_strpos($title_no_accents, $term_no_accents, 0, 'UTF-8') !== false) {
                    $found_in_title = true;
                    break;
                }
            }
        }
        
        // If found in title, return highlighted title
        if ($found_in_title) {
            $highlighted = $this->highlight_text($title_text, $search_terms);
            if ($show_location) {
                return '<strong>' . esc_html__('Found in the title:', 'cik-smart-search') . '</strong> ' . $highlighted;
            } else {
                return $highlighted;
            }
        }
        
        // Search in content
        $content_has_match = false;
        if (!empty($content) && mb_strlen($content) >= 10) {
            // Find the FIRST match in content
            foreach ($search_terms as $term) {
                if (mb_strlen($term) < 2) continue;
                
                $term_no_accents = $has_greek 
                    ? $this->remove_greek_accents(mb_strtolower($term, 'UTF-8'))
                    : mb_strtolower($term, 'UTF-8');
                    
                // Search in normalized version
                $pos = mb_strpos($content_no_accents, $term_no_accents, 0, 'UTF-8');
                
                if ($pos !== false) {
                    $content_has_match = true;
                    
                    // Get context around the FIRST match (150 chars)
                    // Use position from original content (with accents)
                    $start = max(0, $pos - 75);
                    $length = 150;
                    $snippet = mb_substr($content, $start, $length); // Keep original with accents
                    
                    // Trim to word boundaries
                    if ($start > 0) {
                        $snippet = preg_replace('/^\S*\s+/', '...', $snippet);
                    }
                    if ($start + $length < mb_strlen($content)) {
                        $snippet = preg_replace('/\s+\S*$/', '...', $snippet);
                    }
                    
                    $best_match = $snippet;
                    break; // Use FIRST match only
                }
            }
        }
        
        // If match found in content, return it
        if ($content_has_match && !empty($best_match)) {
            $highlighted = $this->highlight_text($best_match, $search_terms);
            if ($show_location) {
                return '<strong>' . esc_html__('Found in the content:', 'cik-smart-search') . '</strong> ' . $highlighted;
            } else {
                return $highlighted;
            }
        }
        
        // Fallback: Always show the title (even without exact match)
        if (!empty($title_text)) {
            $highlighted = $this->highlight_text($title_text, $search_terms);
            if ($show_location) {
                return '<strong>' . esc_html__('Result:', 'cik-smart-search') . '</strong> ' . $highlighted;
            } else {
                return $highlighted;
            }
        }
        
        return '';
    }
    
    /**
     * Check if search query matches in title
     * 
     * @param string $title Post/term title
     * @param string $query Search query
     * @return bool True if match found in title
     */
    private function has_match_in_title($title, $query) {
        $title_text = wp_strip_all_tags($title);
        
        // Prepare search terms
        $search_terms = array_filter(explode(' ', $query));
        
        // Add Greeklish-to-Greek conversions to search terms (only for Greek language and likely Greeklish)
        $current_lang = $this->get_current_language();
        $expanded_terms = array();
        foreach ($search_terms as $term) {
            $expanded_terms[] = $term; // Original term
            
            // Check if current language is Greek AND term is likely Greeklish
            if ($current_lang === 'el' && $this->is_likely_greeklish($term)) {
                $greek_version = $this->greeklish_to_greek($term);
                if ($greek_version !== $term) {
                    $expanded_terms[] = $greek_version; // Add Greek conversion
                }
            }
        }
        $search_terms = array_unique($expanded_terms);
        
        // Check if text contains Greek characters
        $has_greek = preg_match('/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $title_text);
        
        // Create normalized version for matching
        if ($has_greek) {
            $title_no_accents = $this->remove_greek_accents(mb_strtolower($title_text, 'UTF-8'));
        } else {
            $title_no_accents = mb_strtolower($title_text, 'UTF-8');
        }
        
        // Check if ANY query term matches in title
        foreach ($search_terms as $term) {
            if (mb_strlen($term) >= 2) {
                $term_no_accents = $has_greek 
                    ? $this->remove_greek_accents(mb_strtolower($term, 'UTF-8'))
                    : mb_strtolower($term, 'UTF-8');
                    
                // Search in normalized version
                if (mb_strpos($title_no_accents, $term_no_accents, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get current language (WPML/Polylang compatible, works without multilingual plugins)
     * Returns 'el' for Greek, 'en' for English, etc.
     * Safe to use even if WPML/Polylang are not installed
     * 
     * @return string Language code (ISO 639-1: 'el', 'en', 'fr', etc.)
     */
    private function get_current_language() {
        // Check if WPML is active (most common multilingual plugin)
        if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
            return ICL_LANGUAGE_CODE;
        }
        
        // Check Polylang (function_exists ensures no errors if not installed)
        if (function_exists('pll_current_language')) {
            /** @var callable $pll_current_language */
            $lang = call_user_func('pll_current_language');
            if (!empty($lang)) {
                return $lang;
            }
        }
        
        // Check qTranslate-X
        if (function_exists('qtranxf_getLanguage')) {
            /** @var callable $qtranxf_getLanguage */
            $lang = call_user_func('qtranxf_getLanguage');
            if (!empty($lang)) {
                return $lang;
            }
        }
        
        // Fallback: Use WordPress locale
        $locale = get_locale(); // e.g., 'el_GR', 'en_US', 'fr_FR'
        
        // Extract language code from locale (before underscore)
        if (strpos($locale, '_') !== false) {
            $lang_code = substr($locale, 0, strpos($locale, '_')); // 'el_GR' → 'el'
        } else {
            $lang_code = $locale; // 'el' → 'el'
        }
        
        // Normalize to lowercase
        $lang_code = strtolower($lang_code);
        
        // Return language code if valid (2 letters), otherwise default to 'en'
        return (strlen($lang_code) === 2) ? $lang_code : 'en';
    }
    
    /**
     * Check if text is likely Greeklish (not pure English)
     * Greeklish contains characteristic combinations like th, ch, ps, ou, etc.
     */
    private function is_likely_greeklish($text) {
        // If already contains Greek characters, it's not Greeklish
        if (preg_match('/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $text)) {
            return false;
        }
        
        // If doesn't contain Latin characters, it's not Greeklish
        if (!preg_match('/[a-zA-Z]/', $text)) {
            return false;
        }
        
        $text_lower = mb_strtolower($text, 'UTF-8');
        
        // Common English words that might trigger false positives - exclude them
        $common_english = array(
            'the', 'this', 'that', 'these', 'those', 'there', 'their', 'they',
            'about', 'house', 'mouse', 'through', 'thought', 'though',
            'could', 'should', 'would', 'our', 'your', 'four', 'pour',
            'out', 'shout', 'route', 'south', 'mouth', 'youth',
            'search', 'reach', 'beach', 'teach', 'each', 'such', 'much', 'touch',
            'with', 'without', 'within', 'other', 'another', 'nothing', 'something',
            'chorus', 'chaos', 'echo', 'anchor', 'chrome', 'chronic'
        );
        
        if (in_array($text_lower, $common_english)) {
            return false;
        }
        
        $score = 0;
        
        // Strong Greeklish patterns (score +3 each)
        $strong_patterns = array(
            'th', 'ch', 'ps', 'ks', 'mp', 'nt', 'gk', 'ng', 'gg', 'tch', 'sch'
        );
        foreach ($strong_patterns as $pattern) {
            if (strpos($text_lower, $pattern) !== false) {
                $score += 3;
            }
        }
        
        // Medium Greeklish patterns (score +2 each)
        $medium_patterns = array(
            'ou', 'au', 'eu', 'ei', 'oi', 'ai', 'ts', 'tz', 'dz'
        );
        foreach ($medium_patterns as $pattern) {
            if (strpos($text_lower, $pattern) !== false) {
                $score += 2;
            }
        }
        
        // Weak indicators (score +1 each)
        // Words ending in common Greek endings
        $greeklish_endings = array('os', 'is', 'as', 'es', 'hs', 'ous', 'eis', 'ais', 'aki', 'ouli', 'itsa');
        foreach ($greeklish_endings as $ending) {
            if (substr($text_lower, -strlen($ending)) === $ending) {
                $score += 1;
            }
        }
        
        // Words with 'k' followed by vowels (common in Greeklish, less in English)
        if (preg_match('/k[aeiou]/i', $text_lower)) {
            $score += 1;
        }
        
        // Words starting with 'v' (very common in Greek: βέρνη → verni)
        if (preg_match('/^v[aeiou]/i', $text_lower)) {
            $score += 1;
        }
        
        // High vowel ratio (Greek has many vowels)
        $vowel_count = preg_match_all('/[aeiouy]/i', $text_lower);
        $total_length = strlen($text_lower);
        if ($total_length > 0) {
            $vowel_ratio = $vowel_count / $total_length;
            if ($vowel_ratio > 0.4) { // More than 40% vowels
                $score += 1;
            }
        }
        
        // If score is 2 or higher, likely Greeklish (lowered threshold for better detection)
        return $score >= 2;
    }
    
    /**
     * Convert Greeklish (Latin characters) to Greek
     */
    private function greeklish_to_greek($text) {
        $text = mb_strtolower($text, 'UTF-8');
        
        // Complex mappings first (order matters!)
        $replacements = array(
            // Three-letter combinations
            'tch' => 'τσ',
            'sch' => 'σχ',
            
            // Two-letter combinations
            'th' => 'θ',
            'ch' => 'χ',
            'ps' => 'ψ',
            'ks' => 'ξ',
            'ts' => 'τσ',
            'ds' => 'ντζ',
            'tz' => 'τζ',
            'dz' => 'ντζ',
            'mp' => 'μπ',
            'nt' => 'ντ',
            'gk' => 'γκ',
            'gx' => 'γξ',
            'nk' => 'γκ',
            'ng' => 'γγ',
            'gg' => 'γγ',
            'ou' => 'ου',
            'au' => 'αυ',
            'eu' => 'ευ',
            'ei' => 'ει',
            'oi' => 'οι',
            'ai' => 'αι',
            'ui' => 'υι',
            
            // Single letters
            'a' => 'α',
            'v' => 'β',
            'b' => 'μπ',
            'g' => 'γ',
            'd' => 'δ',
            'e' => 'ε',
            'z' => 'ζ',
            'i' => 'ι',
            'h' => 'η',
            'k' => 'κ',
            'l' => 'λ',
            'm' => 'μ',
            'n' => 'ν',
            'x' => 'ξ',
            'o' => 'ο',
            'p' => 'π',
            'r' => 'ρ',
            's' => 'σ',
            't' => 'τ',
            'y' => 'υ',
            'f' => 'φ',
            'w' => 'ω',
            'u' => 'ου',
            'q' => 'κ',
            'c' => 'κ',
            'j' => 'τζ',
        );
        
        // Apply replacements
        foreach ($replacements as $latin => $greek) {
            $text = str_replace($latin, $greek, $text);
        }
        
        return $text;
    }
    
    /**
     * Highlight search terms in plain text (accent-insensitive for Greek).
     *
     * The input is treated as plain text: every segment is escaped with
     * esc_html() and only <mark> tags are added, so the returned string is
     * safe to insert as HTML on the front end.
     *
     * @param string $text  Plain text (no HTML).
     * @param array  $terms Search terms.
     * @return string Escaped HTML.
     */
    private function highlight_text($text, $terms) {
        $has_greek = preg_match('/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $text);

        $haystack = mb_strtolower($text, 'UTF-8');
        if ($has_greek) {
            $haystack = $this->remove_greek_accents($haystack);
        }

        // Collect [start, end) character ranges of every match.
        $ranges = array();
        foreach ($terms as $term) {
            if (mb_strlen($term, 'UTF-8') < 2) {
                continue;
            }

            $needle = mb_strtolower($term, 'UTF-8');
            if ($has_greek) {
                $needle = $this->remove_greek_accents($needle);
            }

            $length = mb_strlen($needle, 'UTF-8');
            $offset = 0;
            while (($pos = mb_strpos($haystack, $needle, $offset, 'UTF-8')) !== false) {
                $ranges[] = array($pos, $pos + $length);
                $offset   = $pos + $length;
            }
        }

        if (empty($ranges)) {
            return esc_html($text);
        }

        usort($ranges, function ($a, $b) {
            return $a[0] - $b[0];
        });

        // Build the output from escaped segments, keeping the original characters (with accents).
        $output = '';
        $cursor = 0;
        foreach ($ranges as $range) {
            $start = max($range[0], $cursor);
            $end   = $range[1];
            if ($end <= $cursor) {
                continue; // Already covered by a previous (overlapping) match.
            }

            $output .= esc_html(mb_substr($text, $cursor, $start - $cursor, 'UTF-8'));
            $output .= '<mark>' . esc_html(mb_substr($text, $start, $end - $start, 'UTF-8')) . '</mark>';
            $cursor  = $end;
        }
        $output .= esc_html(mb_substr($text, $cursor, null, 'UTF-8'));

        return $output;
    }

    /**
     * Convert stored HTML/entity-encoded text (titles, content, term names) to plain text.
     *
     * @param string $text Raw text that may contain tags, shortcodes or HTML entities.
     * @return string Plain text.
     */
    private function to_plain_text($text) {
        $text = wp_strip_all_tags(strip_shortcodes((string) $text));

        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Singular label of a post type as plain text.
     *
     * @param string $post_type Post type slug.
     * @return string
     */
    private function get_post_type_label($post_type) {
        $object = get_post_type_object($post_type);

        return $object ? $this->to_plain_text($object->labels->singular_name) : '';
    }
    
    /**
     * Search only in post title for faster results
     */
    public function search_by_title_only($search, $wp_query) {
        global $wpdb;
        
        if (empty($search)) {
            return $search;
        }
        
        $q = $wp_query->query_vars;
        $n = !empty($q['exact']) ? '' : '%';
        $search = '';
        $searchand = '';
        
        foreach ((array) $q['search_terms'] as $term) {
            $term = esc_sql($wpdb->esc_like($term));
            $search .= "{$searchand}($wpdb->posts.post_title LIKE '{$n}{$term}{$n}')";
            $searchand = ' AND ';
        }
        
        if (!empty($search)) {
            $search = " AND ({$search}) ";
            if (!is_user_logged_in()) {
                $search .= " AND ($wpdb->posts.post_password = '') ";
            }
        }
        
        return $search;
    }
    
    /**
     * Invalidate cached predictive results.
     *
     * Instead of deleting transients with a LIKE query on wp_options (which does
     * nothing when a persistent object cache is used), the cache version that is
     * part of every cache key is incremented. Old entries simply expire.
     *
     * @param int|null $post_id Post being saved/deleted (null when called manually).
     */
    public function clear_predictive_cache($post_id = null) {
        if ($post_id) {
            // Ignore autosaves, revisions and our own search-log entries
            // (a log entry is written on every front-end search).
            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || get_post_type($post_id) === 'cik_search_log') {
                return;
            }
        }

        update_option('cik_search_cache_version', (int) get_option('cik_search_cache_version', 1) + 1);
    }
    
    /**
     * Register Search Log Custom Post Type
     */
    public function register_search_log_post_type() {
        $labels = array(
            'name'                  => __('Search Analytics', 'cik-smart-search'),
            'singular_name'         => __('Search Log', 'cik-smart-search'),
            'menu_name'             => __('Analytics', 'cik-smart-search'),
            'all_items'             => __('All Searches', 'cik-smart-search'),
            'view_item'             => __('View Search', 'cik-smart-search'),
            'search_items'          => __('Search Logs', 'cik-smart-search'),
            'not_found'             => __('No searches found', 'cik-smart-search'),
            'not_found_in_trash'    => __('No searches found in trash', 'cik-smart-search'),
        );
        
        $args = array(
            'labels'                => $labels,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => false, // We add it manually via submenu
            'capability_type'       => 'post',
            // Logs may contain (anonymized) IPs, so only administrators can
            // view or delete them; nobody can create them from the admin.
            'capabilities'          => array(
                'create_posts'           => 'do_not_allow',
                'edit_posts'             => 'manage_options',
                'edit_others_posts'      => 'manage_options',
                'edit_published_posts'   => 'manage_options',
                'publish_posts'          => 'manage_options',
                'delete_posts'           => 'manage_options',
                'delete_others_posts'    => 'manage_options',
                'delete_published_posts' => 'manage_options',
                'read_private_posts'     => 'manage_options',
            ),
            'map_meta_cap'          => true,
            'supports'              => array('title'),
            'has_archive'           => false,
            'rewrite'               => false,
            'delete_with_user'      => false,
        );
        
        register_post_type('cik_search_log', $args);
        
        // Migrate existing records that lack _search_term_normalized
        $this->migrate_search_log_normalized();
    }

    /**
     * One-time migration: add _search_term_normalized to existing log entries
     */
    private function migrate_search_log_normalized() {
        if (get_option('cik_search_normalized_migrated')) {
            return;
        }

        global $wpdb;

        // Get posts missing the normalized meta
        $posts = $wpdb->get_results("
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON p.ID = pm.post_id AND pm.meta_key = '_search_term_normalized'
            WHERE p.post_type = 'cik_search_log'
            AND p.post_status = 'publish'
            AND pm.meta_id IS NULL
            LIMIT 500
        ");

        foreach ($posts as $post) {
            $normalized = $this->normalize_search_term($post->post_title);
            update_post_meta($post->ID, '_search_term_normalized', $normalized);
        }

        // If fewer than 500 were returned we've processed all of them
        if (count($posts) < 500) {
            update_option('cik_search_normalized_migrated', true);
        }
    }
    
    /**
     * Log search queries when submitted
     */
    public function log_search_query($query) {
        // Prevent infinite loops - only run once per request
        static $already_logging = false;
        if ($already_logging) {
            return;
        }
        
        // Only log main search queries, not admin or secondary queries
        if (!$query->is_main_query() || is_admin() || !$query->is_search()) {
            return;
        }
        
        // Get cached settings (prevents infinite loop)
        $settings = $this->get_analytics_settings();
        
        // Check if analytics is enabled
        if (!$settings['enabled']) {
            return;
        }
        
        $already_logging = true; // Set flag to prevent recursion
        
        $search_term = trim(sanitize_text_field(wp_unslash((string) $query->get('s'))));

        // Cap the stored term: searches come from anonymous visitors.
        $search_term = mb_substr($search_term, 0, 100, 'UTF-8');

        // Skip empty searches
        if ($search_term === '') {
            $already_logging = false;
            return;
        }

        // Normalize for deduplication (strip accents, lowercase)
        $normalized = $this->normalize_search_term($search_term);

        // Check if we should log this search (avoid duplicates in same session)
        $session_key = 'cik_search_last_search_' . md5($normalized);
        $last_logged = get_transient($session_key);

        if ($last_logged) {
            $already_logging = false;
            return; // Already logged this search recently (within 5 minutes)
        }

        // Check if this normalized search term already exists
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'cik_search_log'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_search_term_normalized'
            AND pm.meta_value = %s
            LIMIT 1",
            $normalized
        ));

        if ($existing) {
            // Update existing record — just increment count
            $post_id = $existing->ID;

            $current_count = get_post_meta($post_id, '_search_count', true);
            $new_count = $current_count ? intval($current_count) + 1 : 2;
            update_post_meta($post_id, '_search_count', $new_count);
            update_post_meta($post_id, '_last_search_time', current_time('timestamp'));

            // Touch the "last search" date directly: wp_update_post() would run
            // the full save_post hook chain on every front-end search.
            $wpdb->update(
                $wpdb->posts,
                array(
                    'post_modified'     => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1),
                ),
                array('ID' => $post_id),
                array('%s', '%s'),
                array('%d')
            );
            clean_post_cache($post_id);

        } else {
            // Create new search log post
            $post_id = wp_insert_post(array(
                'post_type'   => 'cik_search_log',
                'post_title'  => $search_term,
                'post_status' => 'publish',
                'post_author' => 0,
            ));

            if ($post_id && !is_wp_error($post_id)) {
                update_post_meta($post_id, '_search_term',            $search_term);
                update_post_meta($post_id, '_search_term_normalized',  $normalized);
                update_post_meta($post_id, '_search_time',             current_time('timestamp'));
                update_post_meta($post_id, '_last_search_time',        current_time('timestamp'));
                update_post_meta($post_id, '_search_count',            1);

                if ($settings['log_ip']) {
                    update_post_meta($post_id, '_user_ip', $this->get_user_ip());
                }

                // Store only the referring path, without the query string (may contain personal data).
                $referer = wp_get_referer();
                update_post_meta($post_id, '_referer', $referer ? esc_url_raw(strtok($referer, '?')) : '');
            }
        }
        
        // Update results count after the query runs (for both new and existing)
        if ($post_id && !is_wp_error($post_id)) {
            add_action('wp_footer', function() use ($post_id, $query) {
                if (isset($query->found_posts)) {
                    update_post_meta($post_id, '_results_count', intval($query->found_posts));
                }
            }, 999);
            
            // Set transient to avoid duplicate logging (5 minutes)
            set_transient($session_key, time(), 5 * MINUTE_IN_SECONDS);
        }
        
        $already_logging = false; // Reset flag
    }
    
    /**
     * Normalize a search term for deduplication.
     * Strips accents (Greek & Latin), lowercases, collapses whitespace.
     */
    private function normalize_search_term($term) {
        $term = mb_strtolower(trim($term), 'UTF-8');

        // Greek accented → plain
        $from = ['ά','έ','ή','ί','ϊ','ΐ','ό','ύ','ϋ','ΰ','ώ'];
        $to   = ['α','ε','η','ι','ι','ι','ο','υ','υ','υ','ω'];
        $term = str_replace($from, $to, $term);

        // Latin accented → plain
        $from_l = ['à','á','â','ã','ä','å','è','é','ê','ë','ì','í','î','ï','ò','ó','ô','õ','ö','ù','ú','û','ü','ý','ÿ','ñ','ç'];
        $to_l   = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','y','y','n','c'];
        $term = str_replace($from_l, $to_l, $term);

        // Collapse multiple spaces
        return preg_replace('/\s+/', ' ', $term);
    }

    /**
     * Anonymized IP address of the visitor (only used when "Log User IP" is enabled).
     *
     * Only REMOTE_ADDR is trusted: X-Forwarded-For / Client-IP headers are set by
     * the client and can be spoofed. Sites behind a reverse proxy can supply the
     * real address through the `cik_search_client_ip` filter. The address is
     * stored anonymized (last octet / last 80 bits zeroed) to limit personal data.
     *
     * @return string
     */
    private function get_user_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ip = (string) apply_filters('cik_search_client_ip', $ip);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        return wp_privacy_anonymize_ip($ip);
    }
    
    /**
     * Custom columns for search log admin
     */
    public function search_log_columns($columns) {
        return array(
            'cb'            => $columns['cb'],
            'title'         => __('Search Term', 'cik-smart-search'),
            'count'         => __('Times Searched', 'cik-smart-search'),
            'results'       => __('Results', 'cik-smart-search'),
            'user_ip'       => __('User IP', 'cik-smart-search'),
            'referer'       => __('Source Page', 'cik-smart-search'),
            'date'          => __('Last Search', 'cik-smart-search'),
        );
    }
    
    /**
     * Display custom column content
     */
    public function search_log_column_content($column, $post_id) {
        switch ($column) {
            case 'count':
                $count = get_post_meta($post_id, '_search_count', true);
                $count = $count ? intval($count) : 1; // Default to 1 if not set (old entries)
                echo '<strong style="color:#0066b3;font-size:16px;">' . esc_html($count) . '</strong> ' . esc_html(_n('time', 'times', $count, 'cik-smart-search'));
                break;
                
            case 'results':
                $count = get_post_meta($post_id, '_results_count', true);
                if ($count !== '') {
                    echo '<strong>' . esc_html((int) $count) . '</strong> ' . esc_html(_n('result', 'results', (int) $count, 'cik-smart-search'));
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;
                
            case 'user_ip':
                $ip = get_post_meta($post_id, '_user_ip', true);
                if ($ip) {
                    echo '<code style="font-size:11px;">' . esc_html($ip) . '</code>';
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;
                
            case 'referer':
                $referer = get_post_meta($post_id, '_referer', true);
                if ($referer) {
                    $path = wp_parse_url($referer, PHP_URL_PATH);
                    echo '<a href="' . esc_url($referer) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($referer) . '">' . esc_html($path ?: '/') . '</a>';
                } else {
                    echo '<span style="color:#999;">' . esc_html__('Direct', 'cik-smart-search') . '</span>';
                }
                break;
        }
    }
    
    /**
     * Make columns sortable
     */
    public function search_log_sortable_columns($columns) {
        $columns['count'] = 'count';
        $columns['results'] = 'results';
        return $columns;
    }
    
    /**
     * Handle sorting by meta fields
     */
    public function search_log_orderby($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        // Check if we're on search log screen
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'cik_search_log') {
            return;
        }
        
        $orderby = $query->get('orderby');
        
        if ('count' === $orderby) {
            $query->set('meta_key', '_search_count');
            $query->set('orderby', 'meta_value_num');
        } elseif ('results' === $orderby) {
            $query->set('meta_key', '_results_count');
            $query->set('orderby', 'meta_value_num');
        }
    }
    
    /**
     * Cleanup old search logs based on retention setting
     */
    public function cleanup_old_logs() {
        $settings       = $this->get_analytics_settings();
        $retention_days = (int) $settings['retention'];

        // 0 = keep forever.
        if ($retention_days <= 0) {
            return;
        }

        $cutoff_date = gmdate('Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS);

        // Delete in batches so a large backlog cannot exhaust memory or time out.
        for ($batch = 0; $batch < 20; $batch++) {
            $old_logs = get_posts(array(
                'post_type'        => 'cik_search_log',
                'post_status'      => 'any',
                'posts_per_page'   => 200,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => true,
                'date_query'       => array(
                    array(
                        'column' => 'post_date_gmt',
                        'before' => $cutoff_date,
                    ),
                ),
            ));

            if (empty($old_logs)) {
                break;
            }

            foreach ($old_logs as $log_id) {
                wp_delete_post($log_id, true); // Skip the trash.
            }
        }
    }

    /**
     * Schedule the daily retention cleanup.
     */
    public static function schedule_cleanup() {
        if (!wp_next_scheduled('cik_search_cleanup')) {
            wp_schedule_event(time(), 'daily', 'cik_search_cleanup');
        }
    }

    /**
     * Plugin deactivation: stop the scheduled cleanup (data is kept until uninstall).
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('cik_search_cleanup');
    }
}

register_activation_hook(__FILE__, array('Cik_Smart_Search', 'schedule_cleanup'));
register_deactivation_hook(__FILE__, array('Cik_Smart_Search', 'deactivate'));

/**
 * Initialize plugin
 */
function cik_search_init() {
    return Cik_Smart_Search::get_instance();
}

// Initialize
cik_search_init();
