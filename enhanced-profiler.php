<?php
//Fluent profiling checks
if (!defined('ABSPATH')) exit;

class FluentFormDeepProfiler {
    private static $instance = null;
    private $start_time;
    private $checkpoints = [];
    private $is_profiling = false;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Start as early as possible
        $this->start_time = microtime(true);
        
        // Check if this is a FluentForm submission
        if ($this->is_fluent_ajax()) {
            $this->is_profiling = true;
            $this->checkpoint('REQUEST_START', 'AJAX request received');
            
            // Hook into WordPress lifecycle
            add_action('muplugins_loaded', [$this, 'checkpoint_muplugins'], 1);
            add_action('plugins_loaded', [$this, 'checkpoint_plugins'], 1);
            add_action('init', [$this, 'checkpoint_init'], 1);
            add_action('wp_loaded', [$this, 'checkpoint_wp_loaded'], 1);
            
            // Hook into FluentForms lifecycle - accept any number of parameters
            add_action('fluentform/before_form_data_processing', [$this, 'checkpoint_before_processing'], 1, 10);
            add_action('fluentform/validation_errors', [$this, 'checkpoint_validation'], 1, 10);
            add_action('fluentform/before_insert_submission', [$this, 'checkpoint_before_insert'], 1, 10);
            add_action('fluentform/submission_inserted', [$this, 'checkpoint_after_insert'], 1, 10);
            add_action('fluentform/before_form_actions_processing', [$this, 'checkpoint_before_actions'], 1, 10);
            add_action('fluentform/form_actions_processing', [$this, 'checkpoint_during_actions'], 1, 10);
            
            // Capture all plugin loading
            $this->track_plugin_loading();
            
            // Output results at shutdown
            add_action('shutdown', [$this, 'output_results'], 1);
        }
    }
    
    private function is_fluent_ajax() {
        return (
            defined('DOING_AJAX') && DOING_AJAX &&
            isset($_POST['action']) && $_POST['action'] === 'fluentform_submit'
        ) || (
            isset($_REQUEST['action']) && $_REQUEST['action'] === 'fluentform_submit'
        );
    }
    
    public function checkpoint($label, $description = '') {
        if (!$this->is_profiling) return;
        
        $now = microtime(true);
        $elapsed = $now - $this->start_time;
        
        $this->checkpoints[] = [
            'label' => $label,
            'description' => $description,
            'time' => $elapsed,
            'memory' => memory_get_usage(true),
            'trace' => $this->get_caller()
        ];
    }
    
    public function checkpoint_muplugins() {
        $this->checkpoint('MUPLUGINS_LOADED', 'MU-Plugins loaded');
    }
    
    public function checkpoint_plugins() {
        $this->checkpoint('PLUGINS_LOADED', 'All plugins loaded');
    }
    
    public function checkpoint_init() {
        $this->checkpoint('INIT', 'WordPress init');
    }
    
    public function checkpoint_wp_loaded() {
        $this->checkpoint('WP_LOADED', 'WordPress fully loaded');
    }
    
    public function checkpoint_before_processing($form = null) {
        $this->checkpoint('FF_BEFORE_PROCESSING', 'FluentForm: Before data processing');
    }
    
    public function checkpoint_validation($errors = [], $form_id = null) {
        $error_count = is_array($errors) ? count($errors) : 0;
        $this->checkpoint('FF_VALIDATION', 'FluentForm: Validation - ' . $error_count . ' errors');
    }
    
    public function checkpoint_before_insert($insertId = null, $formData = null, $form = null) {
        $this->checkpoint('FF_BEFORE_INSERT', 'FluentForm: Before database insert');
    }
    
    public function checkpoint_after_insert($insertId = null, $formData = null, $form = null) {
        $id = $insertId ? $insertId : 'unknown';
        $this->checkpoint('FF_AFTER_INSERT', 'FluentForm: After database insert - ID: ' . $id);
    }
    
    public function checkpoint_before_actions($insertId = null, $formData = null) {
        $this->checkpoint('FF_BEFORE_ACTIONS', 'FluentForm: Before actions (emails, integrations)');
    }
    
    public function checkpoint_during_actions($insertId = null, $formData = null) {
        $this->checkpoint('FF_DURING_ACTIONS', 'FluentForm: Processing actions');
    }
    
    private function track_plugin_loading() {
        // Track which plugins are loading
        add_action('activate_plugin', function($plugin) {
            $this->checkpoint('PLUGIN_ACTIVATE', 'Plugin activated: ' . $plugin);
        });
        
        // Track when specific slow plugins load
        $slow_plugins = [
            'The Events Calendar' => 'tribe-events',
            'Event Tickets' => 'event-tickets',
            'WooCommerce' => 'woocommerce',
            'FluentForm' => 'fluentform',
        ];
        
        foreach ($slow_plugins as $name => $slug) {
            add_action("{$slug}_loaded", function() use ($name) {
                $this->checkpoint('PLUGIN_LOADED', $name . ' initialized');
            });
        }
    }
    
    private function get_caller() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        if (isset($trace[3])) {
            $caller = $trace[3];
            $file = isset($caller['file']) ? basename($caller['file']) : 'unknown';
            $line = isset($caller['line']) ? $caller['line'] : '?';
            $function = isset($caller['function']) ? $caller['function'] : 'unknown';
            return "{$file}:{$line} -> {$function}()";
        }
        return 'unknown';
    }
    
    public function output_results() {
        if (!$this->is_profiling) return;
        
        $total_time = microtime(true) - $this->start_time;
        $peak_memory = memory_get_peak_usage(true);
        
        error_log("
========================================
FLUENTFORM DEEP PROFILING RESULTS
========================================
Total Time: " . number_format($total_time, 4) . "s
Peak Memory: " . $this->format_bytes($peak_memory) . "
Form ID: " . ($_POST['form_id'] ?? 'unknown') . "
========================================
");
        
        // Output timeline
        error_log("EXECUTION TIMELINE:");
        error_log("------------------");
        
        $last_time = 0;
        foreach ($this->checkpoints as $i => $cp) {
            $delta = $cp['time'] - $last_time;
            $percentage = ($cp['time'] / $total_time) * 100;
            
            error_log(sprintf(
                "[%d] %.4fs (+%.4fs, %5.1f%%) | %-25s | %s | Mem: %s",
                $i + 1,
                $cp['time'],
                $delta,
                $percentage,
                $cp['label'],
                $cp['description'],
                $this->format_bytes($cp['memory'])
            ));
            
            $last_time = $cp['time'];
        }
        
        // Identify gaps (time periods with no checkpoints)
        error_log("
========================================");
        error_log("SIGNIFICANT GAPS (>0.5s):");
        error_log("------------------");
        
        for ($i = 1; $i < count($this->checkpoints); $i++) {
            $gap = $this->checkpoints[$i]['time'] - $this->checkpoints[$i-1]['time'];
            if ($gap > 0.5) {
                error_log(sprintf(
                    "GAP: %.4fs between '%s' and '%s'",
                    $gap,
                    $this->checkpoints[$i-1]['label'],
                    $this->checkpoints[$i]['label']
                ));
            }
        }
        
        // Check for specific issues
        error_log("
========================================");
        error_log("DIAGNOSTIC CHECKS:");
        error_log("------------------");
        
        // Check if taking long before WordPress init
        $init_time = 0;
        foreach ($this->checkpoints as $cp) {
            if ($cp['label'] === 'INIT') {
                $init_time = $cp['time'];
                break;
            }
        }
        
        if ($init_time > 1.0) {
            error_log("⚠️  WARNING: Taking " . number_format($init_time, 2) . "s to reach WordPress init");
            error_log("   This suggests slow plugin loading or server issues");
        }
        
        // Check database queries
        global $wpdb;
        if (isset($wpdb->num_queries)) {
            error_log("Database Queries: " . $wpdb->num_queries);
            if ($wpdb->num_queries > 100) {
                error_log("⚠️  WARNING: High number of database queries");
            }
        }
        
        // Check for external HTTP requests
        $http_time = $total_time;
        foreach ($this->checkpoints as $cp) {
            if (strpos($cp['label'], 'FF_BEFORE_ACTIONS') !== false) {
                $http_time = $total_time - $cp['time'];
                break;
            }
        }
        
        if ($http_time > 2.0) {
            error_log("⚠️  WARNING: " . number_format($http_time, 2) . "s spent in actions/integrations");
            error_log("   Check: Email sending, webhooks, API calls");
        }
        
        error_log("
========================================
END PROFILING
========================================
");
    }
    
    private function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, 2) . ' ' . $units[$i];
    }
}

// Initialize profiler
FluentFormDeepProfiler::get_instance();
