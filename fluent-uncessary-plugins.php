<?php
// Disable unnecessary plugins during FluentForm AJAX
add_filter('option_active_plugins', function($plugins) {
    // Only during FluentForm AJAX
    if (!defined('DOING_AJAX') || !DOING_AJAX || 
        !isset($_POST['action']) || $_POST['action'] !== 'fluentform_submit') {
        return $plugins;
    }
    
    // Disable these plugins during form submission
    $disable = [
        'updraftplus/updraftplus.php',                    // Backup (1.4MB) - NOT needed
        'the-events-calendar/the-events-calendar.php',    // Events (415KB) - NOT needed
        'event-tickets/event-tickets.php',                // Tickets (325KB) - NOT needed
        'event-tickets-plus/event-tickets-plus.php',      // Tickets+ (268KB) - NOT needed
        'shortpixel-image-optimiser/wp-shortpixel.php',  // Images - NOT needed
        'blogvault-real-time-backup/blogvault.php',      // Backup - NOT needed
        'oxyextras/oxyextras.php',                       // Oxygen - NOT needed
        'tribe-ext-pdf-tickets/tribe-ext-pdf-tickets.php', // PDF tickets - NOT needed
        'user-switching/user-switching.php',             // Admin tool - NOT needed
        'heartbeat-control/heartbeat-control.php',       // Heartbeat - NOT needed
        'wp-seopress/seopress.php',                      // SEO - NOT needed
        'wp-seopress-pro/seopress-pro.php',             // SEO Pro - NOT needed
        'admin-menu-editor-pro/menu-editor.php',         // Admin UI - NOT needed
    ];
    
    error_log("AJAX: Disabled " . count(array_intersect($plugins, $disable)) . " plugins");
    
    return array_diff($plugins, $disable);
}, 1);
