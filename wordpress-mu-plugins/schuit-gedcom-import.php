<?php
/**
 * Plugin Name: Schuit GEDCOM Import
 * Description: Allows administrators to upload and import GEDCOM files into webtrees from WordPress admin.
 * Version: 1.0.0
 * Author: Schuit Foundation
 * Author URI: https://stichtingschu-y-i-ij-t.nl
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Network: false
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schuit_Gedcom_Import
 * 
 * Must-use plugin for GEDCOM file upload and import into webtrees.
 * Survives WordPress upgrades because mu-plugins are loaded from a fixed path.
 */
final class Schuit_Gedcom_Import {

    /**
     * Path to the webtrees CLI executable
     */
    const WEBTREES_CLI = '/var/www/current/webtrees/vendor/bin/webtrees';

    /**
     * Path to the GEDCOM import command
     */
    const IMPORT_COMMAND = 'tree-import';

    /**
     * Path to the metrics update script
     */
    const METRICS_SCRIPT = '/var/www/current/schuit.info/scripts/update-webtrees-metrics.sh';

    /**
     * Maximum file size for GEDCOM uploads (in bytes)
     */
    const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB

    /**
     * Import status transient key
     */
    const STATUS_TRANSIENT = 'schuit_gedcom_import_status';

    /**
     * Import lock transient key
     */
    const LOCK_TRANSIENT = 'schuit_gedcom_import_lock';

    /**
     * Initialize the plugin
     */
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_post_schuit_import_gedcom', [__CLASS__, 'handle_import']);
        add_action('admin_notices', [__CLASS__, 'display_notices']);
        add_action('wp_ajax_schuit_check_import_status', [__CLASS__, 'ajax_check_status']);
    }

    /**
     * Add admin menu page
     */
    public static function add_admin_menu(): void {
        add_management_page(
            __('GEDCOM Import', 'schuit-gedcom-import'),
            __('GEDCOM Import', 'schuit-gedcom-import'),
            'manage_options',
            'schuit-gedcom-import',
            [__CLASS__, 'render_admin_page']
        );
    }

    /**
     * Render the admin page
     */
    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'schuit-gedcom-import'));
        }

        $status = get_transient(self::STATUS_TRANSIENT);
        $lock = get_transient(self::LOCK_TRANSIENT);

        // Get current metrics
        $metrics = self::get_current_metrics();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if ($lock) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php _e('Import in progress', 'schuit-gedcom-import'); ?></strong></p>
                    <p><?php _e('A GEDCOM import is currently running. Please wait for it to complete before starting a new import.', 'schuit-gedcom-import'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($status && is_array($status)) : ?>
                <div class="notice notice-<?php echo esc_attr($status['type'] ?? 'info'); ?>">
                    <p><strong><?php echo esc_html($status['message'] ?? ''); ?></strong></p>
                    <?php if (!empty($status['details'])) : ?>
                        <pre style="margin-top: 10px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; overflow-x: auto;"><?php echo esc_html($status['details']); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2><?php _e('Current Metrics', 'schuit-gedcom-import'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Metric', 'schuit-gedcom-import'); ?></th>
                        <th><?php _e('Value', 'schuit-gedcom-import'); ?></th>
                        <th><?php _e('Last Updated', 'schuit-gedcom-import'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php _e('Individuals', 'schuit-gedcom-import'); ?></td>
                        <td><?php echo esc_html($metrics['individuals'] ?? 'N/A'); ?></td>
                        <td><?php echo esc_html($metrics['updated'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Families', 'schuit-gedcom-import'); ?></td>
                        <td><?php echo esc_html($metrics['families'] ?? 'N/A'); ?></td>
                        <td><?php echo esc_html($metrics['updated'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Places', 'schuit-gedcom-import'); ?></td>
                        <td><?php echo esc_html($metrics['places'] ?? 'N/A'); ?></td>
                        <td><?php echo esc_html($metrics['updated'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Trees', 'schuit-gedcom-import'); ?></td>
                        <td><?php echo esc_html($metrics['trees'] ?? 'N/A'); ?></td>
                        <td><?php echo esc_html($metrics['updated'] ?? 'N/A'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php _e('Upload GEDCOM File', 'schuit-gedcom-import'); ?></h2>
            <?php if (!$lock) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="schuit_import_gedcom">
                    <?php wp_nonce_field('schuit_import_gedcom', 'schuit_gedcom_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="gedcom_file"><?php _e('GEDCOM File', 'schuit-gedcom-import'); ?></label></th>
                            <td>
                                <input type="file" name="gedcom_file" id="gedcom_file" accept=".ged" required>
                                <p class="description">
                                    <?php _e('Select a GEDCOM file to import. Maximum file size: 100MB.', 'schuit-gedcom-import'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="keep_media"><?php _e('Keep Media Files', 'schuit-gedcom-import'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="keep_media" id="keep_media" value="1">
                                    <?php _e('Merge existing media files with the new import', 'schuit-gedcom-import'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('If checked, existing media files will be preserved. Otherwise, they may be replaced.', 'schuit-gedcom-import'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Import GEDCOM', 'schuit-gedcom-import'); ?>">
                    </p>
                </form>
            <?php else : ?>
                <p class="description">
                    <?php _e('Import is currently in progress. Please wait.', 'schuit-gedcom-import'); ?>
                </p>
            <?php endif; ?>

            <h2><?php _e('Manual Metrics Update', 'schuit-gedcom-import'); ?></h2>
            <p>
                <?php _e('If you have manually updated the GEDCOM file on the server, you can update the metrics here:', 'schuit-gedcom-import'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=schuit_update_metrics'), 'schuit_update_metrics', 'schuit_metrics_nonce')); ?>" class="button">
                    <?php _e('Update Metrics', 'schuit-gedcom-import'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle the GEDCOM import
     */
    public static function handle_import(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'schuit-gedcom-import'));
        }

        // Check nonce
        if (!isset($_POST['schuit_gedcom_nonce']) || !wp_verify_nonce($_POST['schuit_gedcom_nonce'], 'schuit_import_gedcom')) {
            wp_die(__('Security check failed.', 'schuit-gedcom-import'));
        }

        // Check if import is already running
        if (get_transient(self::LOCK_TRANSIENT)) {
            wp_redirect(add_query_arg('import_status', 'locked', admin_url('tools.php?page=schuit-gedcom-import')));
            exit;
        }

        // Check file upload
        if (!isset($_FILES['gedcom_file']) || $_FILES['gedcom_file']['error'] !== UPLOAD_ERR_OK) {
            self::set_status('error', __('No file uploaded or upload failed.', 'schuit-gedcom-import'));
            wp_redirect(add_query_arg('import_status', 'error', admin_url('tools.php?page=schuit-gedcom-import')));
            exit;
        }

        $file = $_FILES['gedcom_file'];

        // Validate file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            self::set_status('error', sprintf(__('File too large. Maximum size is %s.', 'schuit-gedcom-import'), size_format(self::MAX_FILE_SIZE)));
            wp_redirect(add_query_arg('import_status', 'error', admin_url('tools.php?page=schuit-gedcom-import')));
            exit;
        }

        // Validate file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'ged') {
            self::set_status('error', __('Invalid file type. Only .ged files are allowed.', 'schuit-gedcom-import'));
            wp_redirect(add_query_arg('import_status', 'error', admin_url('tools.php?page=schuit-gedcom-import')));
            exit;
        }

        // Set lock
        set_transient(self::LOCK_TRANSIENT, true, HOUR_IN_SECONDS);

        // Set initial status
        self::set_status('info', __('Starting import...', 'schuit-gedcom-import'));

        // Move uploaded file to a secure location
        $upload_dir = wp_upload_dir();
        $import_dir = $upload_dir['basedir'] . '/gedcom-imports';
        
        if (!is_dir($import_dir)) {
            wp_mkdir_p($import_dir);
        }

        $filename = sanitize_file_name($file['name']);
        $filepath = $import_dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            self::set_status('error', __('Failed to save uploaded file.', 'schuit-gedcom-import'));
            delete_transient(self::LOCK_TRANSIENT);
            wp_redirect(add_query_arg('import_status', 'error', admin_url('tools.php?page=schuit-gedcom-import')));
            exit;
        }

        // Start import in background
        $keep_media = isset($_POST['keep_media']) ? '--keep-media' : '';
        
        $command = sprintf(
            'nohup %s %s "%s" %s %s > /tmp/schuit-gedcom-import.log 2>&1 &',
            escapeshellarg(self::WEBTREES_CLI),
            self::IMPORT_COMMAND,
            escapeshellarg('0'), // tree name
            escapeshellarg($filepath),
            $keep_media
        );

        // Execute the command
        exec($command, $output, $return_var);

        // Set status to processing
        self::set_status('info', __('Import started in background. This may take several minutes.', 'schuit-gedcom-import'));

        // Clean up
        wp_redirect(add_query_arg('import_status', 'started', admin_url('tools.php?page=schuit-gedcom-import')));
        exit;
    }

    /**
     * Check import status via AJAX
     */
    public static function ajax_check_status(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'schuit-gedcom-import')]);
        }

        $status = get_transient(self::STATUS_TRANSIENT);
        $lock = get_transient(self::LOCK_TRANSIENT);

        wp_send_json_success([
            'status' => $status ?: null,
            'locked' => (bool) $lock,
        ]);
    }

    /**
     * Update metrics manually
     */
    public static function handle_update_metrics(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'schuit-gedcom-import'));
        }

        // Check nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'schuit_update_metrics')) {
            wp_die(__('Security check failed.', 'schuit-gedcom-import'));
        }

        // Run the metrics update script
        $output = [];
        $return_var = 0;
        exec(self::METRICS_SCRIPT . ' 2>&1', $output, $return_var);

        if ($return_var === 0) {
            self::set_status('success', __('Metrics updated successfully.', 'schuit-gedcom-import'));
        } else {
            self::set_status('error', __('Failed to update metrics.', 'schuit-gedcom-import') . ' ' . implode("\n", $output));
        }

        wp_redirect(add_query_arg('import_status', 'metrics_updated', admin_url('tools.php?page=schuit-gedcom-import')));
        exit;
    }

    /**
     * Get current metrics from the property file
     */
    private static function get_current_metrics(): array {
        $metrics_path = '/var/www/shared/webtrees/data/metrics.json';
        
        if (!is_readable($metrics_path)) {
            return [
                'individuals' => 'N/A',
                'families' => 'N/A',
                'places' => 'N/A',
                'trees' => 'N/A',
                'updated' => 'N/A',
            ];
        }

        $json = file_get_contents($metrics_path);
        if ($json === false) {
            return [
                'individuals' => 'N/A',
                'families' => 'N/A',
                'places' => 'N/A',
                'trees' => 'N/A',
                'updated' => 'N/A',
            ];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'individuals' => 'N/A',
                'families' => 'N/A',
                'places' => 'N/A',
                'trees' => 'N/A',
                'updated' => 'N/A',
            ];
        }

        return [
            'individuals' => $data['individuals'] ?? 'N/A',
            'families' => $data['families'] ?? 'N/A',
            'places' => $data['places'] ?? 'N/A',
            'trees' => $data['trees'] ?? 'N/A',
            'updated' => $data['updated'] ?? 'N/A',
        ];
    }

    /**
     * Set import status
     */
    private static function set_status(string $type, string $message, string $details = ''): void {
        set_transient(self::STATUS_TRANSIENT, [
            'type' => $type,
            'message' => $message,
            'details' => $details,
            'timestamp' => current_time('mysql'),
        ], 2 * HOUR_IN_SECONDS);
    }

    /**
     * Display admin notices
     */
    public static function display_notices(): void {
        $status = get_transient(self::STATUS_TRANSIENT);
        
        if ($status && is_array($status)) {
            $class = 'notice notice-' . esc_attr($status['type'] ?? 'info');
            printf(
                '<div class="%1$s"><p><strong>%2$s</strong></p></div>',
                esc_attr($class),
                esc_html($status['message'] ?? '')
            );
        }
    }
}

// Initialize the plugin
Schuit_Gedcom_Import::init();

// Handle metrics update separately (via admin-post)
add_action('admin_post_schuit_update_metrics', ['Schuit_Gedcom_Import', 'handle_update_metrics']);
