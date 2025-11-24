<?php
// Optimize autoload options
add_action('init', function() {
    // Remove heavy autoload options during AJAX
    if (defined('DOING_AJAX') && DOING_AJAX) {
        global $wpdb;
        
        // These options don't need to autoload
        $heavy_options = [
            'tribe_events_calendar_options',
            'updraft_last_backup',
            'shortpixel_api_key',
            'blogvault_api_key',
            'seopress_titles_option',
        ];
        
        foreach ($heavy_options as $option) {
            wp_cache_delete($option, 'options');
        }
    }
}, 1);
