<?php
// plugin profiling checks
if (!defined('ABSPATH')) exit;

class PluginLoadTimer {
    private static $timings = [];
    private static $start_time;
    private static $is_ajax_fluent = false;
    
    public static function init() {
        self::$start_time = microtime(true);
        
        // Only run on FluentForm AJAX
        if (defined('DOING_AJAX') && DOING_AJAX && 
            isset($_POST['action']) && $_POST['action'] === 'fluentform_submit') {
            
            self::$is_ajax_fluent = true;
            
            // Track each plugin as it loads
            add_action('activate_plugin', [__CLASS__, 'track_plugin'], 1);
            
            // Hook before and after plugin loading
            add_action('muplugins_loaded', [__CLASS__, 'mark_muplugins']);
            add_action('plugins_loaded', [__CLASS__, 'mark_plugins_done']);
            add_action('shutdown', [__CLASS__, 'output_results'], 1);
            
            // Track individual plugin files
            self::intercept_plugin_loading();
        }
    }
    
    private static function intercept_plugin_loading() {
        // Get active plugins
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($active_plugins as $plugin_file) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            
            // This won't work perfectly but gives us an idea
            add_action('plugin_loaded', function() use ($plugin_file) {
                self::$timings[$plugin_file] = microtime(true) - self::$start_time;
            });
        }
    }
    
    public static function mark_muplugins() {
        if (!self::$is_ajax_fluent) return;
        self::$timings['__MUPLUGINS_DONE__'] = microtime(true) - self::$start_time;
    }
    
    public static function mark_plugins_done() {
        if (!self::$is_ajax_fluent) return;
        self::$timings['__PLUGINS_DONE__'] = microtime(true) - self::$start_time;
    }
    
    public static function track_plugin($plugin) {
        if (!self::$is_ajax_fluent) return;
        self::$timings[$plugin] = microtime(true) - self::$start_time;
    }
    
    public static function output_results() {
        if (!self::$is_ajax_fluent) return;
        
        error_log("
========================================
PLUGIN LOADING ANALYSIS
========================================");
        
        // Get detailed plugin list with load times
        $active_plugins = get_option('active_plugins', []);
        $plugin_data = [];
        
        $last_time = isset(self::$timings['__MUPLUGINS_DONE__']) ? self::$timings['__MUPLUGINS_DONE__'] : 0;
        
        // Measure each plugin file size and estimate load time
        foreach ($active_plugins as $plugin_file) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            $plugin_dir = dirname($plugin_path);
            
            // Get plugin name
            if (file_exists($plugin_path)) {
                $plugin_info = get_plugin_data($plugin_path, false, false);
                $name = $plugin_info['Name'] ?: basename($plugin_file);
            } else {
                $name = basename($plugin_file);
            }
            
            // Calculate directory size (rough estimate of plugin complexity)
            $size = 0;
            if (is_dir($plugin_dir)) {
                $size = self::get_dir_size($plugin_dir);
            }
            
            $plugin_data[] = [
                'name' => $name,
                'file' => $plugin_file,
                'size' => $size,
                'dir' => basename($plugin_dir)
            ];
        }
        
        // Sort by size (larger = likely slower)
        usort($plugin_data, function($a, $b) {
            return $b['size'] <=> $a['size'];
        });
        
        error_log("TOP 15 LARGEST PLUGINS (likely slowest):");
        error_log("------------------------------------------");
        
        foreach (array_slice($plugin_data, 0, 15) as $i => $plugin) {
            error_log(sprintf(
                "%2d. %-40s | %8s | %s",
                $i + 1,
                substr($plugin['name'], 0, 40),
                self::format_bytes($plugin['size']),
                $plugin['dir']
            ));
        }
        
        error_log("
========================================");
    }
    
    private static function get_dir_size($dir) {
        $size = 0;
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            
            $count = 0;
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                    // Stop after checking 1000 files to avoid timeout
                    if ($count > 1000) break;
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }
        
        return $size;
    }
    
    private static function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, 1) . $units[$i];
    }
}

PluginLoadTimer::init();
