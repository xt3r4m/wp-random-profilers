<?php
if (!defined('ABSPATH')) exit;

class FluentFormAjaxProfiler {
    private $records = [];
    private $start = 0;
    private $last = 0;
    private $db_queries = [];
    private $http_requests = [];
    private $is_profiling = false;
    private $query_start_times = [];
    private $hooks_tracked = [];
    private $action_scheduler_actions = [];
    private $current_action_id = null;
    private $db_query_count = 0;
    private $real_start_time = 0;
    
    // Comprehensive hook tracking
    private $fluent_hooks = [
        // Form processing
        'fluentform/before_insert_submission',
        'fluentform/after_insert_submission', 
        'fluentform/submission_inserted',
        'fluentform/before_form_actions_processing',
        'fluentform/form_actions_processing',
        'fluentform/after_form_actions_processing',
        'fluentform/before_form_actions_processing_completed',
        'fluentform/after_form_actions_processing_completed',
        
        // Notifications & integrations
        'fluentform_notify',
        'fluentform_process_payment',
        'fluentform_integration_notify',
        'fluentform_webhook_received',
        'fluentform/send_to_webhook',
        
        // Validation
        'fluentform/validate_input_item_',
        'fluentform/validation_errors',
        
        // Confirmation
        'fluentform/submission_confirmation',
        'fluentform/submission_message',
        'fluentform/after_submission_confirmation',
    ];

    public function __construct() {
        // Capture REAL start time from server variables
        $this->real_start_time = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        
        // Start profiling as early as possible
        add_action('wp_ajax_fluentform_submit', [$this, 'start'], -9999);
        add_action('wp_ajax_nopriv_fluentform_submit', [$this, 'start'], -9999);
        
        // Track Fluent Forms hooks
        foreach ($this->fluent_hooks as $hook) {
            add_action($hook, function() use ($hook) {
                $args = func_get_args();
                $this->mark("FLUENT_HOOK: {$hook}", $args);
            }, 10, 5);
        }
        
        // Track database queries via wpdb
        add_action('shutdown', [$this, 'track_wpdb_queries'], 2);
        
        // Track HTTP requests
        add_filter('pre_http_request', [$this, 'track_http_start'], 9999, 3);
        add_action('http_api_debug', [$this, 'track_http_end'], 9999, 5);
        
        // Track output buffering
        add_action('wp_ajax_fluentform_submit', [$this, 'track_output_start'], 99999);
        add_action('shutdown', [$this, 'track_output_end'], 99998);
        
        // Finish profiling
        add_action('shutdown', [$this, 'finish'], 99999);
        
        // Add timing header for browser measurement
        add_filter('wp_headers', [$this, 'add_timing_headers'], 9999, 2);
    }
    
    public function start() {
        if ($this->is_profiling) return;
        
        $this->is_profiling = true;
        $this->start = microtime(true);
        $this->last = $this->start;
        $this->records = [];
        $this->db_queries = [];
        $this->http_requests = [];
        $this->query_start_times = [];
        $this->hooks_tracked = [];
        $this->action_scheduler_actions = [];
        $this->db_query_count = 0;
        
        error_log("
        ===============================================
        🚀 FLUENTFORM PROFILING STARTED
        ===============================================
        ");
        error_log("⏰ PHP Start Time: " . date('Y-m-d H:i:s'));
        error_log("🌐 REAL Start Time: " . date('Y-m-d H:i:s', (int)$this->real_start_time));
        error_log("💾 Memory: " . $this->format_bytes(memory_get_usage()));
        error_log("📊 Peak Memory: " . $this->format_bytes(memory_get_peak_usage()));
        error_log("🌐 URL: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log("📝 Form ID: " . ($_POST['form_id'] ?? 'unknown'));
        error_log("👤 User: " . (is_user_logged_in() ? 'logged_in' : 'guest'));
        error_log("🕒 Server Queue Time: " . ($this->start - $this->real_start_time) . "s");
        
        $this->mark('🚀 PROFILER_STARTED');
    }
    
    public function track_output_start() {
        if (!$this->is_profiling) return;
        
        $this->mark("📤 OUTPUT_BUFFERING_START");
        // Force flush to see if output buffering is causing delays
        if (ob_get_level() > 0) {
            ob_flush();
            flush();
        }
    }
    
    public function track_output_end() {
        if (!$this->is_profiling) return;
        
        $this->mark("📥 OUTPUT_BUFFERING_END");
        // Final flush
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
    
    public function add_timing_headers($headers, $wp) {
        if (!$this->is_profiling) return $headers;
        
        $php_execution_time = microtime(true) - $this->start;
        $total_time = microtime(true) - $this->real_start_time;
        
        $headers['X-Profiler-PHP-Time'] = round($php_execution_time, 4) . 's';
        $headers['X-Profiler-Total-Time'] = round($total_time, 4) . 's';
        $headers['X-Profiler-Queue-Time'] = round($this->start - $this->real_start_time, 4) . 's';
        $headers['X-Profiler-Memory'] = $this->format_bytes(memory_get_peak_usage());
        
        return $headers;
    }
    
    public function mark($label, $args = null) {
        if (!$this->is_profiling) return $label;
        
        $now = microtime(true);
        $elapsed = $now - $this->last;
        $total_php = $now - $this->start;
        $total_real = $now - $this->real_start_time;
        
        // Get meaningful caller information
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $caller = '';
        foreach ($trace as $frame) {
            if (isset($frame['function']) && $frame['function'] !== 'mark') {
                $caller = isset($frame['class']) ? 
                    $frame['class'] . '::' . $frame['function'] : 
                    $frame['function'];
                break;
            }
        }
        
        // Format label
        if (is_array($label) || is_object($label)) {
            $label_str = json_encode($label);
        } else {
            $label_str = (string)$label;
        }
        
        // Limit label length for readability
        $label_str = substr($label_str, 0, 300);
        
        $this->records[] = [
            'label' => $label_str,
            'caller' => $caller,
            'elapsed' => $elapsed,
            'total_php' => $total_php,
            'total_real' => $total_real,
            'memory' => memory_get_usage(),
            'peak_memory' => memory_get_peak_usage(),
            'timestamp' => $now
        ];
        
        $this->last = $now;
        return $label;
    }
    
    public function track_wpdb_queries() {
        if (!$this->is_profiling) return;
        
        global $wpdb;
        
        // Only track if wpdb has queries
        if (empty($wpdb->queries) || !is_array($wpdb->queries)) {
            return;
        }
        
        $this->db_queries = [];
        
        foreach ($wpdb->queries as $query_data) {
            if (is_array($query_data) && count($query_data) >= 2) {
                $query = $query_data[0];
                $query_time = floatval($query_data[1]);
                $backtrace = isset($query_data[2]) ? $this->simplify_backtrace($query_data[2]) : 'unknown';
                
                $this->db_queries[] = [
                    'query' => substr($query, 0, 500),
                    'time' => $query_time,
                    'hash' => md5($query),
                    'backtrace' => $backtrace
                ];
            }
        }
        
        $this->db_query_count = count($this->db_queries);
        $this->mark("🗄️  DB_QUERIES_COLLECTED: {$this->db_query_count} queries");
    }
    
    public function track_http_start($preempt, $parsed_args, $url) {
        if (!$this->is_profiling) return $preempt;
        
        $key = md5($url . microtime());
        $parsed_args['_profiler_key'] = $key;
        
        $this->http_requests[$key] = [
            'url' => $url,
            'method' => $parsed_args['method'] ?? 'GET',
            'start_time' => microtime(true),
            'time' => 0,
            'status' => 'pending'
        ];
        
        $this->mark("🌐 HTTP_START: {$parsed_args['method']} {$url}");
        
        return $preempt;
    }
    
    public function track_http_end($response, $context, $class, $args, $url) {
        if (!$this->is_profiling) return;
        
        $key = $args['_profiler_key'] ?? null;
        $current_time = microtime(true);
        
        if ($key && isset($this->http_requests[$key])) {
            $this->http_requests[$key]['time'] = $current_time - $this->http_requests[$key]['start_time'];
            $this->http_requests[$key]['status'] = is_wp_error($response) ? 'error' : 'success';
            $this->http_requests[$key]['end_time'] = $current_time;
            
            $status_icon = is_wp_error($response) ? '❌' : '✅';
            $this->mark("{$status_icon} HTTP_END: {$args['method']} {$url} - {$this->http_requests[$key]['time']}s");
        }
    }
    
    public function finish() {
        if (!$this->is_profiling) return;
        
        $php_execution_time = microtime(true) - $this->start;
        $total_real_time = microtime(true) - $this->real_start_time;
        $queue_time = $this->start - $this->real_start_time;
        $peak_memory = memory_get_peak_usage();
        
        error_log("
        ===============================================
        🏁 PROFILING COMPLETED
        ===============================================
        ");
        error_log("⏱️  PHP Execution Time: {$php_execution_time}s");
        error_log("🕒 Total Real Time: {$total_real_time}s");
        error_log("📊 Server Queue Time: {$queue_time}s");
        error_log("💾 Peak Memory: " . $this->format_bytes($peak_memory));
        error_log("📊 Total Operations: " . count($this->records));
        
        $this->log_slow_operations();
        $this->log_execution_timeline();
        $this->log_database_queries();
        $this->log_http_requests();
        $this->log_performance_summary($php_execution_time, $total_real_time, $queue_time);
        
        // Log browser vs server timing difference
        $estimated_browser_time = $total_real_time + 0.1; // Add network latency estimate
        error_log("
        🔍 TIMING ANALYSIS:
        - PHP Execution: {$php_execution_time}s
        - Server Queue: {$queue_time}s  
        - Estimated Total: {$estimated_browser_time}s
        - Browser Experience: ~5s (reported)
        - Difference: " . round(5 - $estimated_browser_time, 2) . "s unaccounted
        ");
        
        $this->is_profiling = false;
    }
    
    private function log_slow_operations() {
        $slow_threshold = 0.1; // 100ms
        $very_slow_threshold = 1.0; // 1 second
        
        $slow_ops = array_filter($this->records, function($r) use ($slow_threshold) {
            return $r['elapsed'] > $slow_threshold;
        });
        
        if (!empty($slow_ops)) {
            error_log("
            ===============================================
            🐌 SLOW OPERATIONS (> {$slow_threshold}s)
            ===============================================
            ");
            
            usort($slow_ops, function($a, $b) {
                return $b['elapsed'] <=> $a['elapsed'];
            });
            
            foreach ($slow_ops as $op) {
                $severity = $op['elapsed'] > $very_slow_threshold ? "🚨 VERY SLOW" : "⚠️ SLOW";
                error_log(sprintf(
                    "{$severity} [%.4fs] %s",
                    $op['elapsed'],
                    $op['label']
                ));
                error_log("     Caller: {$op['caller']}");
                error_log("     Memory: " . $this->format_bytes($op['memory']));
            }
        }
    }
    
    private function log_execution_timeline() {
        error_log("
        ===============================================
        📈 EXECUTION TIMELINE (Significant Events)
        ===============================================
        ");
        
        foreach ($this->records as $r) {
            if ($r['elapsed'] > 0.01) { // Only log operations > 10ms
                error_log(sprintf(
                    "[%.4fs PHP | %.4fs REAL] +%.4fs | %s | %s",
                    $r['total_php'],
                    $r['total_real'],
                    $r['elapsed'],
                    $r['label'],
                    $r['caller']
                ));
            }
        }
    }
    
    private function log_database_queries() {
        if (empty($this->db_queries)) return;
        
        $total_query_time = array_sum(array_column($this->db_queries, 'time'));
        
        error_log("
        ===============================================
        🗄️  DATABASE QUERIES (" . count($this->db_queries) . " total, {$total_query_time}s)
        ===============================================
        ");
        
        // Sort by time (slowest first)
        usort($this->db_queries, function($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        
        // Show top 10 slowest queries
        foreach (array_slice($this->db_queries, 0, 10) as $q) {
            error_log(sprintf("%.4fs | %s", $q['time'], $q['query']));
            if (!empty($q['backtrace']) && $q['time'] > 0.1) {
                error_log("     Backtrace: {$q['backtrace']}");
            }
        }
    }
    
    private function log_http_requests() {
        if (empty($this->http_requests)) return;
        
        $total_http_time = array_sum(array_column($this->http_requests, 'time'));
        
        error_log("
        ===============================================
        🌐 HTTP REQUESTS (" . count($this->http_requests) . " total, {$total_http_time}s)
        ===============================================
        ");
        
        foreach ($this->http_requests as $req) {
            $status_icon = $req['status'] === 'success' ? '✅' : 
                          ($req['status'] === 'error' ? '❌' : '❓');
            error_log(sprintf(
                "{$status_icon} %.4fs | %s %s",
                $req['time'],
                $req['method'],
                $req['url']
            ));
        }
    }
    
    private function log_performance_summary($php_time, $real_time, $queue_time) {
        $http_time = array_sum(array_column($this->http_requests, 'time'));
        $db_time = array_sum(array_column($this->db_queries, 'time'));
        
        // Ensure we don't get negative times
        $other_time = max(0, $php_time - $http_time - $db_time);
        
        error_log("
        ===============================================
        📊 PERFORMANCE SUMMARY
        ===============================================
        ");
        error_log("⏱️  PHP Execution Time: {$php_time}s");
        error_log("🕒 Total Real Time: {$real_time}s");
        error_log("📊 Server Queue Time: {$queue_time}s");
        error_log("🌐 HTTP Time: {$http_time}s (" . round(($http_time/$php_time)*100, 1) . "%)");
        error_log("🗄️  Database Time: {$db_time}s (" . round(($db_time/$php_time)*100, 1) . "%)");
        error_log("⚡ Other Time: {$other_time}s (" . round(($other_time/$php_time)*100, 1) . "%)");
        error_log("💾 Peak Memory: " . $this->format_bytes(memory_get_peak_usage()));
        error_log("📊 Total Operations: " . count($this->records));
        error_log("🗄️  Database Queries: " . count($this->db_queries));
        error_log("🌐 HTTP Requests: " . count($this->http_requests));
        
        // Performance assessment based on REAL time
        if ($real_time > 3) {
            error_log("❌ PERFORMANCE ISSUE: Total time over 3 seconds");
            if ($queue_time > 1) {
                error_log("   🔍 Issue: High server queue time ({$queue_time}s) - possible server load");
            }
            if ($php_time > 2) {
                error_log("   🔍 Issue: Slow PHP execution ({$php_time}s) - code optimization needed");
            }
        } elseif ($real_time > 1) {
            error_log("⚠️  PERFORMANCE WARNING: Total time 1-3 seconds");
        } else {
            error_log("✅ PERFORMANCE GOOD: Total time under 1 second");
        }
    }
    
    private function simplify_backtrace($backtrace) {
        if (is_string($backtrace)) return $backtrace;
        if (is_array($backtrace) && !empty($backtrace)) {
            // Find the first non-WP core frame
            foreach ($backtrace as $frame) {
                if (isset($frame['file']) && 
                    strpos($frame['file'], 'wp-content') !== false &&
                    isset($frame['function'])) {
                    $class = isset($frame['class']) ? $frame['class'] . '::' : '';
                    return $class . $frame['function'];
                }
            }
            // Fallback to first frame
            $first = $backtrace[0];
            if (isset($first['class']) && isset($first['function'])) {
                return $first['class'] . '::' . $first['function'];
            }
        }
        return 'unknown';
    }
    
    private function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . $units[$i];
    }
}

new FluentFormAjaxProfiler();
