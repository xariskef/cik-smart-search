<?php
/**
 * Search Box Template
 *
 * This template can be overridden by copying it to yourtheme/cik-smart-search/search-box.php
 *
 * @var string $search_title
 * @var string $search_results_title
 * @var string $search_placeholder
 * @var array $search_tags
 * @var array $atts
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Unique per instance, so the shortcode can be used more than once per page.
$cik_search_field_id = wp_unique_id('cik-search-field-');
?>

<section class="<?php echo esc_attr($atts['wrapper_class']); ?> cik-search-wrapper">
    <div class="cik-search-card">

        <!-- Search Input Container -->
        <form role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>" class="cik-search-form">
            <div class="cik-search-input-group">
                <label for="<?php echo esc_attr($cik_search_field_id); ?>" class="sr-only">
                    <?php esc_html_e('Search for:', 'cik-smart-search'); ?>
                </label>
                <input
                    id="<?php echo esc_attr($cik_search_field_id); ?>"
                    type="search"
                    name="s"
                    value="<?php echo esc_attr(get_search_query(false)); ?>"
                    placeholder="<?php echo esc_attr($search_placeholder); ?>"
                    autocomplete="off"
                    class="cik-search-input" />

                <div class="cik-search-actions">

                    <!-- Search Submit Button (Ghost Style) -->
                    <button type="submit" class="search-submit-btn" aria-label="<?php esc_attr_e('Search', 'cik-smart-search'); ?>">
                        <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                            <path d="M13.4173 24.5C19.5385 24.5 24.5007 19.5378 24.5007 13.4166C24.5007 7.29549 19.5385 2.33331 13.4173 2.33331C7.29616 2.33331 2.33398 7.29549 2.33398 13.4166C2.33398 19.5378 7.29616 24.5 13.4173 24.5Z" stroke="#6b7280" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M25.6673 25.6666L23.334 23.3333" stroke="#6b7280" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <!-- Voice Search (hidden by voice-search.js when the browser has no Web Speech API) -->
                    <button type="button" class="cik-search-voice-search" aria-pressed="false"
                         title="<?php esc_attr_e('Voice search', 'cik-smart-search'); ?>"
                         aria-label="<?php esc_attr_e('Voice search', 'cik-smart-search'); ?>">
                        <svg width="10" height="18" viewBox="0 0 7 13" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                            <path d="M0.5 4.1V6.5C0.5 8.48823 1.84315 10.1 3.5 10.1M6.5 3.8V6.5C6.5 8.48823 5.15685 10.1 3.5 10.1M3.5 10.1V12.5M3.5 12.5H4.5M3.5 12.5H2.5M3.5 7.7C2.94772 7.7 2.5 7.16274 2.5 6.5V1.7C2.5 1.03726 2.94772 0.5 3.5 0.5C4.05228 0.5 4.5 1.03726 4.5 1.7V6.5C4.5 7.16274 4.05228 7.7 3.5 7.7Z" stroke="white" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </form>

        <!-- Predictive Search Results (shows when typing 3+ chars) -->
        <div class="cik-search-predictive-results hidden">
            <div class="cik-search-predictive-inner">
                <div class="cik-search-predictive-heading">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <p>
                        <?php echo esc_html($search_results_title); ?>
                    </p>
                </div>
                <div class="predictive-results-container">
                    <!-- Results will be injected here by JavaScript -->
                </div>
            </div>
        </div>

        <?php if (!empty($search_tags)): ?>
        <div class="cik-search-tags">
            <p class="cik-search-tags-title">
                <?php echo esc_html($search_title); ?>
            </p>
            <div class="cik-search-tags-list">
                <?php foreach ($search_tags as $tag):
                    if (empty($tag['label']) || empty($tag['search_term'])) {
                        continue;
                    }
                ?>
                    <a href="<?php echo esc_url(home_url('/?s=' . urlencode($tag['search_term']))); ?>"
                        class="cik-search-tag">
                        <span>
                            <?php echo esc_html($tag['label']); ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>
