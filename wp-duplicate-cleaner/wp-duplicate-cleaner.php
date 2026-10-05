<?php
/**
 * Plugin Name: Duplicate Post Cleaner with Redirect Logging
 * Description: Automatically identifies and trashes duplicate posts based on title and content, and logs 301 redirects for deleted posts.
 * Version: 2.9
 * Author: Reza Consulting Inc.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class DuplicatePostCleaner {
    private $option_name = 'dpc_settings';
    private $log_file_path;
    private $debug_log_path;
    private $meta_key = '_dpc_checked';
    private $lock_key = 'dpc_process_lock';

    // Define our states for clarity
    const STATE_UNCHECKED = 0;
    const STATE_KEEPER = 1;
    const STATE_UNIQUE = 2;

    public function __construct() {
        $this->log_file_path = WP_CONTENT_DIR . '/uploads/dpc_redirect_log.txt';
        $this->debug_log_path = WP_CONTENT_DIR . '/uploads/dpc_debug_log.txt';
        
        add_filter('cron_schedules', array($this, 'add_custom_schedule'));
        add_action('init', array($this, 'schedule_cron'));
        add_action('dpc_hourly_cleanup', array($this, 'clean_duplicates'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('wp_ajax_dpc_manual_run', array($this, 'ajax_manual_run'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    // Add custom 15-minute interval to WP schedules
    public function add_custom_schedule($schedules) {
        $schedules['every_15_minutes'] = array(
            'interval' => 900, 
            'display'  => __('Every 15 Minutes')
        );
        return $schedules;
    }

    // Helper function to write to debug log
    private function log_debug($message) {
        $time = date('Y-m-d H:i:s');
        $entry = "[$time] " . $message . "\n";
        file_put_contents($this->debug_log_path, $entry, FILE_APPEND | LOCK_EX);
    }
	
	// Helper function to safely read only the last N lines of large log files
    private function get_last_lines($filepath, $lines = 150) {
        if (!file_exists($filepath) || !is_readable($filepath) || filesize($filepath) === 0) {
            return '';
        }

        $handle = @fopen($filepath, 'r');
        if (!$handle) {
            return 'Unable to open log file.';
        }

        $buffer_size = 4096;
        $output = '';
        $line_count = 0;

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);

        while ($pos > 0 && $line_count <= $lines) {
            $seek_size = min($pos, $buffer_size);
            $pos -= $seek_size;
            fseek($handle, $pos);
            $chunk = fread($handle, $seek_size);
            $output = $chunk . $output;
            $line_count = substr_count($output, "\n");
        }

        fclose($handle);

        $file_lines = explode("\n", $output);
        if (count($file_lines) > $lines) {
            $file_lines = array_slice($file_lines, -$lines);
        }

        return implode("\n", $file_lines);
    }
    // Schedule cron job
    public function schedule_cron() {
        $settings = get_option($this->option_name, array());
        $enabled = isset($settings['enable_scheduling']) && $settings['enable_scheduling'];

        if ($enabled && !wp_next_scheduled('dpc_hourly_cleanup')) {
            wp_schedule_event(time(), 'every_15_minutes', 'dpc_hourly_cleanup');
        } elseif (!$enabled) {
            wp_clear_scheduled_hook('dpc_hourly_cleanup');
        }
    }

    // Schedule cron job on plugin activation
    public function activate() {
        $settings = get_option($this->option_name, array());
        if (!isset($settings['enable_scheduling'])) {
            $settings['enable_scheduling'] = false;
        }
        update_option($this->option_name, $settings);
    }

    // Remove cron job on deactivation
    public function deactivate() {
        wp_clear_scheduled_hook('dpc_hourly_cleanup');
        delete_transient($this->lock_key);
    }

    // Check if process is already running
    private function acquire_lock() {
        if (get_transient($this->lock_key)) {
            $this->log_debug("Process already running (lock exists). Exiting.");
            return false;
        }
        set_transient($this->lock_key, time(), 300); // 5-minute lock
        return true;
    }

    // Release lock
    private function release_lock() {
        delete_transient($this->lock_key);
    }

    private function redirects_table() {
        global $wpdb;
        return $wpdb->prefix . 'dpc_redirects';
    }

    // $overwrite = true for cleaner results, false for imports (existing rows win)
    private function save_redirects($pairs, $source, $overwrite) {
        global $wpdb;
        $table = $this->redirects_table();
        $saved = 0;
        foreach (array_chunk($pairs, 1000) as $chunk) {
            $values = array();
            foreach ($chunk as $pair) {
                if ($pair[0] > 0 && $pair[1] > 0 && $pair[0] !== $pair[1]) {
                    $values[] = $wpdb->prepare('(%d, %d, %s)', $pair[0], $pair[1], $source);
                }
            }
            if (empty($values)) {
                continue;
            }
            $sql = ($overwrite ? 'INSERT' : 'INSERT IGNORE')
                . " INTO {$table} (old_id, new_id, source) VALUES " . implode(',', $values)
                . ($overwrite ? ' ON DUPLICATE KEY UPDATE new_id = VALUES(new_id), source = VALUES(source)' : '');
            $saved += (int) $wpdb->query($sql);
        }
        return $saved;
    }

    // Point every redirect at the end of its chain (A->B, B->C becomes A->C)
    private function flatten_redirects() {
        global $wpdb;
        $table = $this->redirects_table();
        for ($pass = 0; $pass < 20; $pass++) {
            $changed = $wpdb->query("UPDATE {$table} r1 INNER JOIN {$table} r2 ON r1.new_id = r2.old_id
                SET r1.new_id = r2.new_id WHERE r2.new_id <> r1.old_id");
            if (!$changed) {
                break;
            }
        }
        $wpdb->query("DELETE FROM {$table} WHERE old_id = new_id");
    }

    // Reads RewriteCond/RewriteRule boost_post_id pairs from a file (htaccess or redirect log)
    public function import_redirects_from_file($path) {
        if (!is_readable($path)) {
            return false;
        }
        $handle = fopen($path, 'r');
        if (!$handle) {
            return false;
        }
        @set_time_limit(0);
        $pending = 0;
        $pairs   = array();
        $found   = 0;
        $added   = 0;

        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\s*RewriteCond\s.*boost_post_id=(\d+)/', $line, $m)) {
                $pending = (int) $m[1];
                continue;
            }
            if ($pending && preg_match('/^\s*RewriteRule\s.*boost_post_id=(\d+)/', $line, $m)) {
                $pairs[] = array($pending, (int) $m[1]);
                $found++;
                if (count($pairs) >= 1000) {
                    $added += $this->save_redirects($pairs, 'import', false);
                    $pairs = array();
                }
            }
            $pending = 0;
        }
        fclose($handle);
        $added += $this->save_redirects($pairs, 'import', false);
        $this->flatten_redirects();

        return array('found' => $found, 'added' => $added);
    }


    // Main function to clean duplicates
    public function clean_duplicates() {
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');
        
        if (!$this->acquire_lock()) {
            return;
        }
        
        try {
            $this->log_debug("=== START CLEANUP RUN ===");
            $this->log_debug("Current Time: " . date('H:i:s'));
            $this->log_debug("Memory Usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB");

            $settings = get_option($this->option_name);
            $max_posts = isset($settings['max_posts']) ? intval($settings['max_posts']) : 50;

            $this->log_debug("Settings Check:");
            $this->log_debug("  Enable Scheduling: " . (isset($settings['enable_scheduling']) && $settings['enable_scheduling'] ? 'YES' : 'NO'));
            $this->log_debug("  Max Posts to Check: " . $max_posts);

            if (!isset($settings['enable_scheduling']) || !$settings['enable_scheduling']) {
                $this->log_debug("Scheduling is disabled. Aborting.");
                $this->release_lock();
                return;
            }

            $post_ids = $this->get_posts_to_process($max_posts);
            
            if (empty($post_ids)) {
                $this->log_debug("No posts to process right now.");
                $this->release_lock();
                return;
            }

            $this->log_debug("Fetching " . count($post_ids) . " posts for processing.");
            
            $duplicates_map = $this->identify_duplicates($post_ids);
            
            $this->log_debug("Found " . count($duplicates_map) . " duplicate groups. Processing all of them.");

            $results = $this->process_duplicate_groups($duplicates_map, $post_ids);
            
            $this->log_debug("Batch complete. Processed " . count($duplicates_map) . " groups.");
            $this->log_debug("Results: " . json_encode($results));
            $this->log_debug("=== RUN FINISHED ===");
            
        } catch (Exception $e) {
            $this->log_debug("FATAL ERROR: " . $e->getMessage());
            $this->log_debug("Stack trace: " . $e->getTraceAsString());
        } finally {
            $this->release_lock();
        }
    }

    // UPDATED: Get posts with a three-state priority system
    private function get_posts_to_process($limit) {
        global $wpdb;
        
        $this->log_debug("Getting posts to process (limit: $limit)");
        $post_ids = array();

        // Priority 1: Get posts that have NEVER been checked (state = 0 or no meta)
		/*
        $sql = $wpdb->prepare("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
            AND (pm.meta_id IS NULL OR pm.meta_value = %d)
            ORDER BY p.ID DESC
            LIMIT %d
        ", $this->meta_key, self::STATE_UNCHECKED, $limit);
        */
		
		$sql = $wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->postmeta} f
            INNER JOIN {$wpdb->posts} p ON p.ID = f.post_id
            WHERE f.meta_key = 'dw_needs_dupcheck'
            AND p.post_type = 'post'
            AND p.post_status = 'publish'
            GROUP BY p.ID
            ORDER BY p.ID DESC
            LIMIT %d
        ", $limit);
		
		
        $post_ids = $wpdb->get_col($sql);
        $this->log_debug("Found " . count($post_ids) . " un-checked posts.");
        
        // Priority 2: If we need more, get "unique" posts that haven't been re-checked in a long time (e.g., 30 days)
        if (count($post_ids) < $limit) {
            $remaining = $limit - count($post_ids);
			
			$sql = $wpdb->prepare("
                SELECT p.ID
                FROM {$wpdb->posts} p FORCE INDEX (PRIMARY)
                WHERE p.post_type = 'post'
                AND p.post_status = 'publish'
                AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm
                    WHERE pm.post_id = p.ID
                    AND pm.meta_key = %s
                    AND pm.meta_value = %s
                )
                ORDER BY p.ID DESC
                LIMIT %d
            ", $this->meta_key, (string) self::STATE_UNIQUE, $remaining);
			
/*            $sql = $wpdb->prepare("
                SELECT p.ID 
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                WHERE p.post_type = 'post' 
                AND p.post_status = 'publish'
                AND pm.meta_value = %d
                AND pm.meta_value < %d
				GROUP BY p.ID
                ORDER BY p.ID DESC
                LIMIT %d
            ", $this->meta_key, self::STATE_UNIQUE, time() - (3600 * 24 * 30), $remaining);
*/            
            $old_unique_posts = $wpdb->get_col($sql);
            $post_ids = array_merge($post_ids, $old_unique_posts);
            $this->log_debug("Added " . count($old_unique_posts) . " old 'unique' posts for re-check.");
        }

        // Priority 3: As a last resort, get "keeper" posts that are very old for a final sanity check (e.g., 1 year)
        // This is optional but can catch edge cases. For now, we'll skip it to avoid complexity.
        
        //return $post_ids;
		return array_values(array_unique(array_map('intval', $post_ids)));

    }

    // Identify duplicates efficiently
    private function identify_duplicates($post_ids) {
        global $wpdb;
        
        if (empty($post_ids)) {
            return array();
        }
        
        $post_ids_str = implode(',', array_map('intval', $post_ids));
        
        $posts = $wpdb->get_results("
            SELECT ID, post_title, post_content 
            FROM {$wpdb->posts}
            WHERE ID IN ($post_ids_str)
            AND post_status = 'publish'
        ", ARRAY_A);
        
        $duplicates_map = array();
        $seen_combinations = array();
        
        foreach ($posts as $post) {
            $post_id = $post['ID'];
            $title = $post['post_title'];
            $content = wp_strip_all_tags($post['post_content']);
            $key = md5($title . $content);
            
            if (isset($seen_combinations[$key])) {
                if (!isset($duplicates_map[$key])) {
                    $duplicates_map[$key] = array(
                        'keep_id' => min($post_id, $seen_combinations[$key]),
                        'delete_ids' => array()
                    );
                }
                $duplicates_map[$key]['keep_id'] = min($duplicates_map[$key]['keep_id'], $post_id);
                if ($post_id != $duplicates_map[$key]['keep_id']) {
                    $duplicates_map[$key]['delete_ids'][] = $post_id;
                }
            } else {
                $seen_combinations[$key] = $post_id;
            }
        }
        
        return $duplicates_map;
    }

    // UPDATED: Process duplicate groups and set the correct state
    private function process_duplicate_groups($duplicate_groups, $all_post_ids_in_batch) {
        global $wpdb;
        
        $results = array(
            'total_deleted' => 0,
            'total_redirects_logged' => 0,
            'errors' => 0
        );
        
        $start_time = microtime(true);
        $uploads_dir = dirname($this->log_file_path);
        if (!is_dir($uploads_dir)) {
            wp_mkdir_p($uploads_dir);
        }
        
        $redirect_lines = array();
        $all_delete_ids = array();
        $all_keep_ids = array();
        
        $this->log_debug("Processing " . count($duplicate_groups) . " duplicate groups.");
        
        foreach ($duplicate_groups as $key => $data) {
            $keep_id = $data['keep_id'];
            $delete_ids = $data['delete_ids'];
            
            $all_keep_ids[] = $keep_id;
            $all_delete_ids = array_merge($all_delete_ids, $delete_ids);
            
            foreach ($delete_ids as $delete_id) {
                $redirect_lines[] = "RewriteCond %{QUERY_STRING} ^boost_post_id={$delete_id}$\nRewriteRule ^boost/? /boost/?boost_post_id={$keep_id} [L,R=301]\n";
                $results['total_redirects_logged']++;
            }
        }
        
        if (empty($all_delete_ids)) {
            $this->log_debug("No duplicates to delete in this batch.");
        } else {
            // ... (bulk deletion logic remains the same) ...
            try {
                $wpdb->query('START TRANSACTION');
                $delete_ids_str = implode(',', array_map('intval', $all_delete_ids));
                $trash_result = $wpdb->query("UPDATE {$wpdb->posts} SET post_status = 'trash' WHERE ID IN ($delete_ids_str) AND post_status = 'publish'");
                if ($trash_result === false) { $wpdb->query('ROLLBACK'); $results['errors']++; return $results; }
                $results['total_deleted'] = $trash_result;
                $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($delete_ids_str) AND meta_key IN ('_wp_trash_meta_status', '_wp_trash_meta_time')");
                $wpdb->query('COMMIT');
                $this->log_debug("Bulk deletion completed successfully.");
            } catch (Exception $e) { $wpdb->query('ROLLBACK'); $this->log_debug("FATAL ERROR in bulk deletion: " . $e->getMessage()); $results['errors']++; return $results; }
            
            if (!empty($redirect_lines)) {
                file_put_contents($this->log_file_path, implode('', $redirect_lines), FILE_APPEND | LOCK_EX);
            }

            // Raw SQL bypasses WordPress, so clear the (Redis) object cache for trashed posts
            foreach (array_map('intval', $all_delete_ids) as $trashed_id) {
                clean_post_cache($trashed_id);
            }

            $pairs = array();
            foreach ($duplicate_groups as $data) {
                foreach ($data['delete_ids'] as $delete_id) {
                    $pairs[] = array((int) $delete_id, (int) $data['keep_id']);
                }
            }
            $this->save_redirects($pairs, 'cleaner', true);

            // Re-point older redirects whose target was just trashed
            $table = $this->redirects_table();
            $wpdb->query("UPDATE {$table} r1 INNER JOIN {$table} r2 ON r1.new_id = r2.old_id
                SET r1.new_id = r2.new_id
                WHERE r2.old_id IN ($delete_ids_str) AND r2.new_id <> r1.old_id");
        }
        
        // UPDATED: State management logic
        $posts_to_set_as_keeper = $all_keep_ids;
        $posts_to_set_as_unique = array_diff($all_post_ids_in_batch, array_merge($all_keep_ids, $all_delete_ids));
        
        $this->log_debug("Marking " . count($posts_to_set_as_keeper) . " posts as 'Keeper'.");
        $this->log_debug("Marking " . count($posts_to_set_as_unique) . " posts as 'Unique'.");
        
		$mark_ids = array_unique(array_merge($posts_to_set_as_keeper, $posts_to_set_as_unique));
        $mark_ids_str = implode(',', array_map('intval', $mark_ids));
        if ($mark_ids_str !== '') {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($mark_ids_str)",
                $this->meta_key
            ));
        }
		
        $time = time();
        $chunks = array_chunk($posts_to_set_as_keeper, 100);
        foreach ($chunks as $chunk) {
            $values = array();
            foreach ($chunk as $post_id) {
                $values[] = $wpdb->prepare("(%d, %s, %s)", $post_id, $this->meta_key, self::STATE_KEEPER);
            }
            if (!empty($values)) {
                $query = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(', ', $values) . " ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
                $wpdb->query($query);
            }
        }

        $chunks = array_chunk($posts_to_set_as_unique, 100);
        foreach ($chunks as $chunk) {
            $values = array();
            foreach ($chunk as $post_id) {
                $values[] = $wpdb->prepare("(%d, %s, %s)", $post_id, $this->meta_key, self::STATE_UNIQUE);
            }
            if (!empty($values)) {
                $query = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(', ', $values) . " ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
                $wpdb->query($query);
            }
        }
		
        $batch_ids_str = implode(',', array_map('intval', $all_post_ids_in_batch));
        if ($batch_ids_str !== '') {
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'dw_needs_dupcheck' AND post_id IN ($batch_ids_str)");
        }
        
        $this->log_debug("Batch processing completed in " . round(microtime(true) - $start_time, 2) . " seconds");
        return $results;
    }

    // Manual run via AJAX
    public function ajax_manual_run() {
        check_ajax_referer('dpc_manual_run', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $this->clean_duplicates();
        wp_send_json_success(array('message' => 'Cleanup completed', 'debug_log' => file_exists($this->debug_log_path) ? file_get_contents($this->debug_log_path) : ''));
    }

	// Clear all checked history
    public function clear_checked_history() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql($this->meta_key) . "'");
		
		$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'dw_needs_dupcheck'");
        $wpdb->query("INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            SELECT ID, 'dw_needs_dupcheck', '1' FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'");
        
		delete_transient('dpc_status_counts'); // Clear cached count transient
        return true;
    }

    // Clear debug log
    public function clear_debug_log() {
        file_put_contents($this->debug_log_path, '');
        return true;
    }

    // Admin menu for settings
    public function add_admin_menu() {
        add_options_page('Duplicate Post Cleaner', 'Duplicate Cleaner', 'manage_options', 'duplicate_post_cleaner', array($this, 'settings_page'));
    }

    public function settings_init() {
        register_setting($this->option_name, $this->option_name);
        add_settings_section('dpc_section', 'Settings', array($this, 'settings_section_callback'), $this->option_name);
        add_settings_field('max_posts', 'Number of Posts to Check Per Run', array($this, 'max_posts_render'), $this->option_name, 'dpc_section');
        add_settings_field('enable_scheduling', 'Enable Scheduling', array($this, 'enable_scheduling_render'), $this->option_name, 'dpc_section');
    }

    public function settings_section_callback() {
        echo 'Configure how many posts to check for duplicates and manage the automatic cleanup schedule.';
    }

    public function max_posts_render() {
        $options = get_option($this->option_name);
        ?>
        <input type='number' name='dpc_settings[max_posts]' value='<?php echo isset($options['max_posts']) ? $options['max_posts'] : 50; ?>' min='1'>
        <p class="description">Maximum number of posts to check per batch (higher = more thorough but slower).</p>
        <?php
    }

    public function enable_scheduling_render() {
        $options = get_option($this->option_name);
        $checked = isset($options['enable_scheduling']) && $options['enable_scheduling'] ? 'checked' : '';
        ?>
        <label>
            <input type='checkbox' name='dpc_settings[enable_scheduling]' value='1' <?php echo $checked; ?>>
            Enable automatic cleanup every 15 minutes.
        </label>
        <?php
    }

	public function settings_page() {
        $clear_history_nonce = wp_create_nonce('dpc_clear_history');
        $clear_debug_nonce   = wp_create_nonce('dpc_clear_debug');
        $manual_run_nonce    = wp_create_nonce('dpc_manual_run');

        global $wpdb;

        // Cache count queries in a 5-minute transient to prevent full database table scans on every page load
        $counts = get_transient('dpc_status_counts');
        if (!is_array($counts)) {
            $total_posts     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");
            $unique_count    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d", $this->meta_key, self::STATE_UNIQUE));
            $keeper_count    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d", $this->meta_key, self::STATE_KEEPER));
            $unchecked_count = max(0, $total_posts - ($unique_count + $keeper_count));

            $counts = array(
                'total_posts'     => $total_posts,
                'unique_count'    => $unique_count,
                'keeper_count'    => $keeper_count,
                'unchecked_count' => $unchecked_count,
            );
            set_transient('dpc_status_counts', $counts, 300);
        }

        $total_posts     = isset($counts['total_posts']) ? (int) $counts['total_posts'] : 0;
        $unique_count    = isset($counts['unique_count']) ? (int) $counts['unique_count'] : 0;
        $keeper_count    = isset($counts['keeper_count']) ? (int) $counts['keeper_count'] : 0;
        $unchecked_count = isset($counts['unchecked_count']) ? (int) $counts['unchecked_count'] : 0;

        $processed_percentage = $total_posts > 0 ? round((($unique_count + $keeper_count) / $total_posts) * 100, 2) : 0;
        ?>
        <div class="wrap">
            <h1>Duplicate Post Cleaner Settings</h1>
            <form action='options.php' method='post'>
                <?php settings_fields($this->option_name); do_settings_sections($this->option_name); submit_button(); ?>
            </form>

            <h2>Progress</h2>
            <p>Total published posts: <strong><?php echo esc_html($total_posts); ?></strong></p>
            <p>Unchecked posts (Priority 1): <strong><?php echo esc_html($unchecked_count); ?></strong></p>
            <p>Unique posts (Priority 2): <strong><?php echo esc_html($unique_count); ?></strong></p>
            <p>Keeper posts (Finalized): <strong><?php echo esc_html($keeper_count); ?></strong></p>
            <p>Posts processed: <strong><?php echo esc_html($processed_percentage); ?>%</strong></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('dpc_clear_history'); ?>
                <input type="hidden" name="action" value="dpc_clear_history">
                <button type="submit" class="button button-secondary">Reset All Post States</button>
                <p class="description">This will clear all states, forcing a full re-check of all posts.</p>
            </form>

            <h2>Actions</h2>
            <button id="dpc-manual-run" class="button button-primary">Run Cleanup Now</button>
            <div id="dpc-result" style="margin-top: 10px;"></div>

            <script>
            jQuery(document).ready(function($) {
                $('#dpc-manual-run').click(function() {
                    $('#dpc-result').html('<p>Running cleanup...</p>');
                    $.post(ajaxurl, { action: 'dpc_manual_run', nonce: '<?php echo $manual_run_nonce; ?>' }, function(response) {
                        if (response.success) { $('#dpc-result').html('<div class="notice notice-success"><p>Cleanup completed!</p></div>'); location.reload(); }
                        else { $('#dpc-result').html('<div class="notice notice-error"><p>Error.</p></div>'); }
                    }).fail(function() { $('#dpc-result').html('<div class="notice notice-error"><p>AJAX failed.</p></div>'); });
                });
            });
            </script>
            <hr>
            <h2>Debug Log (Last 150 lines)</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('dpc_clear_debug'); ?>
                <input type="hidden" name="action" value="dpc_clear_debug">
                <button type="submit" class="button button-secondary">Clear Debug Log</button>
            </form>
            <textarea rows="20" cols="80" readonly style="font-family: monospace; font-size: 12px; width: 100%; margin-top: 10px;"><?php 
                echo esc_textarea($this->get_last_lines($this->debug_log_path, 150)); 
            ?></textarea>
            <hr>
            <h2>Redirect Table</h2>
            <?php if (isset($_GET['dpc_import_found'])) : ?>
                <div class="notice notice-success"><p>Import finished: <?php echo (int) $_GET['dpc_import_found']; ?> rules found, <?php echo (int) $_GET['dpc_import_added']; ?> new redirects added.</p></div>
            <?php elseif (isset($_GET['dpc_import_error'])) : ?>
                <div class="notice notice-error"><p>Import file not found or not readable.</p></div>
            <?php endif; ?>
            <p>Redirects stored: <strong><?php echo esc_html(number_format_i18n((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dpc_redirects"))); ?></strong></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('dpc_import_redirects'); ?>
                <input type="hidden" name="action" value="dpc_import_redirects">
                <label>File to import (<code>.htaccess</code>, or a file name in wp-content/uploads/):
                    <input type="text" name="dpc_import_file" value="dpc_redirect_log.txt" class="regular-text"></label>
                <button type="submit" class="button button-secondary">Import Redirects</button>
            </form>
            <hr>
            <h2>Redirect Log (Last 150 lines)</h2>
            <p>Redirects are logged to: <code><?php echo esc_html($this->log_file_path); ?></code></p>
            <textarea rows="10" cols="80" readonly style="font-family: monospace; font-size: 12px; width: 100%;"><?php 
                echo esc_textarea($this->get_last_lines($this->log_file_path, 150)); 
            ?></textarea>
        </div>
        <?php
    }
    
// Handle Clear History button
    public function handle_clear_history() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'dpc_clear_history') { return; }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dpc_clear_history')) { wp_die('Security check failed'); }
        $result = $this->clear_checked_history();
        if ($result) { add_action('admin_notices', function() { echo '<div class="notice notice-success is-dismissible"><p>States cleared successfully.</p></div>'; }); }
    }
    
    // Handle Clear Debug Log button
    public function handle_clear_debug() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'dpc_clear_debug') { return; }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dpc_clear_debug')) { wp_die('Security check failed'); }
        $result = $this->clear_debug_log();
        if ($result) { add_action('admin_notices', function() { echo '<div class="notice notice-success is-dismissible"><p>Debug log cleared.</p></div>'; }); }
    }

    // Handle Import Redirects button
    public function handle_import_redirects() {
        if (!current_user_can('manage_options')) { wp_die('Not allowed'); }
        check_admin_referer('dpc_import_redirects');

        $back = admin_url('options-general.php?page=duplicate_post_cleaner');
        $name = isset($_POST['dpc_import_file']) ? trim(wp_unslash($_POST['dpc_import_file'])) : '';
        if ($name === '.htaccess') {
            if (!function_exists('get_home_path')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $path = get_home_path() . '.htaccess';
        } else {
            $name = sanitize_file_name($name);
            $path = WP_CONTENT_DIR . '/uploads/' . $name;
        }

        $result = $name !== '' ? $this->import_redirects_from_file($path) : false;
        if ($result === false) {
            wp_safe_redirect(add_query_arg('dpc_import_error', 1, $back));
            exit;
        }
        wp_safe_redirect(add_query_arg(array(
            'dpc_import_found' => $result['found'],
            'dpc_import_added' => $result['added'],
        ), $back));
        exit;
    }
}

// Initialize the plugin
$duplicate_post_cleaner = new DuplicatePostCleaner();

// Hook into admin_post to handle buttons
add_action('admin_post_dpc_clear_history', array($duplicate_post_cleaner, 'handle_clear_history'));
add_action('admin_post_dpc_clear_debug', array($duplicate_post_cleaner, 'handle_clear_debug'));

add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
	if ( 'publish' === $new_status && 'post' === $post->post_type
		&& ! metadata_exists( 'post', $post->ID, '_dpc_checked' ) ) {
		update_post_meta( $post->ID, 'dw_needs_dupcheck', '1' );
	}
}, 10, 3 );

// Creates/updates the redirect table. dbDelta() requires this exact formatting.
// For future schema changes: edit the CREATE TABLE text and bump both '1' values to '2'.
function dpc_install_redirects_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table           = $wpdb->prefix . 'dpc_redirects';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
  old_id bigint(20) unsigned NOT NULL,
  new_id bigint(20) unsigned NOT NULL,
  source varchar(20) NOT NULL DEFAULT 'cleaner',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (old_id),
  KEY new_id (new_id)
) {$charset_collate};";

    dbDelta($sql);
    update_option('dpc_db_version', '1', false);
}
register_activation_hook(__FILE__, 'dpc_install_redirects_table');

// Activation hooks don't run when a file is replaced on an active plugin
add_action('plugins_loaded', function () {
    if (get_option('dpc_db_version') !== '1') {
        dpc_install_redirects_table();
    }
});

add_action('admin_post_dpc_import_redirects', array($duplicate_post_cleaner, 'handle_import_redirects'));

// 301 trashed duplicates on /boost/?boost_post_id=X to their kept post
add_action('init', function () {
    if (empty($_GET['boost_post_id'])) {
        return;
    }
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    if ($path !== 'boost') {
        return;
    }
    $requested = absint($_GET['boost_post_id']);
    if (!$requested || 'publish' === get_post_status($requested)) {
        return;
    }

    global $wpdb;
    $target = $requested;
    for ($hop = 0; $hop < 10; $hop++) {
        $next = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT new_id FROM {$wpdb->prefix}dpc_redirects WHERE old_id = %d", $target
        ));
        if (!$next || $next === $target) {
            break;
        }
        $target = $next;
        if ('publish' === get_post_status($target)) {
            break;
        }
    }

    if ($target !== $requested && 'publish' === get_post_status($target)) {
        wp_safe_redirect(add_query_arg('boost_post_id', $target, home_url('/boost/')), 301);
        exit;
    }
}, 0);
