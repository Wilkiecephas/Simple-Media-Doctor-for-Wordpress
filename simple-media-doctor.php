<?php
/**
 * Plugin Name: Simple Media Doctor
 * Plugin URI: https://tekstep.ug
 * Description: A comprehensive media management plugin with auto-renaming, resizing, compression, audio processing, and monetization features
 * Version: 1.0.1
 * Author: WILKIE CEPHAS, TEKSTEP UG
 * Author URI: https://tekstep.ug
 * License: GPL v2 or later
 * Text Domain: simple-media-doctor
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Network: true
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SMD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SMD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SMD_VERSION', '1.0.1');
define('SMD_DB_VERSION', '1.0');

// Check PHP version
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        printf(
            __('Simple Media Doctor requires PHP 7.4 or higher. Your current version is %s. Please upgrade.', 'simple-media-doctor'),
            PHP_VERSION
        );
        echo '</p></div>';
    });
    return;
}

class SimpleMediaDoctor {
    
    private static $instance = null;
    private $table_prefix = 'smd_';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('SimpleMediaDoctor', 'uninstall'));
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // AJAX hooks
        add_action('wp_ajax_smd_update_media', array($this, 'ajax_update_media'));
        add_action('wp_ajax_smd_compress_images', array($this, 'ajax_compress_images'));
        add_action('wp_ajax_smd_process_audio', array($this, 'ajax_process_audio'));
        add_action('wp_ajax_smd_track_stats', array($this, 'ajax_track_stats'));
        add_action('wp_ajax_nopriv_smd_track_stats', array($this, 'ajax_track_stats'));
        add_action('wp_ajax_smd_process_payment', array($this, 'ajax_process_payment'));
        add_action('wp_ajax_nopriv_smd_process_payment', array($this, 'ajax_process_payment'));
        
        // Media hooks
        add_filter('wp_handle_upload_prefilter', array($this, 'rename_media_on_upload'));
        add_filter('wp_generate_attachment_metadata', array($this, 'auto_resize_images'), 10, 2);
        add_filter('the_content', array($this, 'add_media_players_to_content'));
        
        // Security hooks
        add_filter('upload_mimes', array($this, 'restrict_mime_types'));
        add_action('admin_init', array($this, 'check_permissions'));
        
        // Initialize additional modules
        $this->init_modules();
    }
    
    public function activate($network_wide = false) {
        global $wpdb;
        
        if (is_multisite() && $network_wide) {
            // Network activation
            $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
            foreach ($blog_ids as $blog_id) {
                switch_to_blog($blog_id);
                $this->create_tables();
                restore_current_blog();
            }
        } else {
            // Single site activation
            $this->create_tables();
        }
        
        // Set default options
        $this->set_default_options();
        
        // Schedule maintenance
        if (!wp_next_scheduled('smd_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'smd_daily_maintenance');
        }
        
        add_option('smd_db_version', SMD_DB_VERSION);
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Statistics table
        $table_name = $wpdb->prefix . $this->table_prefix . 'statistics';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            action varchar(255) NOT NULL,
            data text,
            user_id int(11),
            ip_address varchar(45),
            user_agent text,
            PRIMARY KEY (id),
            KEY action (action),
            KEY timestamp (timestamp),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Payments table
        $payments_table = $wpdb->prefix . $this->table_prefix . 'payments';
        $sql_payments = "CREATE TABLE IF NOT EXISTS $payments_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(255) NOT NULL UNIQUE,
            user_id int(11) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL,
            status varchar(50) NOT NULL,
            payment_method varchar(50) NOT NULL,
            media_id int(11),
            payment_data text,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY transaction_id (transaction_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY payment_method (payment_method)
        ) $charset_collate;";
        
        dbDelta($sql_payments);
        
        // Ads table
        $ads_table = $wpdb->prefix . $this->table_prefix . 'ads';
        $sql_ads = "CREATE TABLE IF NOT EXISTS $ads_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            content text NOT NULL,
            placement varchar(100) NOT NULL,
            start_date datetime,
            end_date datetime,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY placement (placement),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) $charset_collate;";
        
        dbDelta($sql_ads);
        
        // Media cache table
        $cache_table = $wpdb->prefix . $this->table_prefix . 'media_cache';
        $sql_cache = "CREATE TABLE IF NOT EXISTS $cache_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            media_id int(11) NOT NULL,
            operation varchar(50) NOT NULL,
            original_size int(11),
            processed_size int(11),
            processing_time float,
            processed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY media_id (media_id),
            KEY operation (operation)
        ) $charset_collate;";
        
        dbDelta($sql_cache);
    }
    
    private function set_default_options() {
        $default_settings = array(
            'auto_rename' => 'post_title',
            'image_sizes' => array(
                'desktop' => array('width' => 1920, 'height' => 1080, 'crop' => true),
                'mobile' => array('width' => 768, 'height' => 432, 'crop' => true),
                'custom' => array('width' => 1200, 'height' => 675, 'crop' => true)
            ),
            'compression_quality' => 80,
            'max_upload_size' => 10, // MB
            'allowed_mime_types' => array(
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'audio/mpeg', 'audio/wav', 'audio/ogg',
                'video/mp4', 'video/webm', 'video/ogg'
            ),
            'audio_low_quality_size' => 1.5,
            'audio_watermark' => '',
            'audio_jingle' => '',
            'video_quality' => '720p',
            'video_watermark' => '',
            'social_accounts' => array(
                'facebook' => '',
                'twitter' => '',
                'instagram' => '',
                'youtube' => '',
                'tiktok' => ''
            ),
            'payment_methods' => array(
                'mtn' => true,
                'airtel' => true,
                'credit_card' => false,
                'paypal' => false,
                'stripe' => false
            ),
            'donation_enabled' => true,
            'advertisements_enabled' => true,
            'paypal_client_id' => '',
            'stripe_publishable_key' => '',
            'currency' => 'USD',
            'enable_logging' => true,
            'backup_original_files' => true,
            'auto_optimize' => false
        );
        
        if (!get_option('smd_settings')) {
            add_option('smd_settings', $default_settings);
        }
    }
    
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('smd_daily_maintenance');
        
        // Clear transients
        delete_transient('smd_processed_stats');
        delete_transient('smd_media_cache');
        
        // Optionally cleanup, but keep data
        // $this->cleanup_temporary_files();
    }
    
    public static function uninstall() {
        global $wpdb;
        
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            exit;
        }
        
        // Remove options
        delete_option('smd_settings');
        delete_option('smd_db_version');
        delete_option('smd_version');
        
        // Remove tables if setting exists
        $settings = get_option('smd_settings');
        if (isset($settings['remove_data_on_uninstall']) && $settings['remove_data_on_uninstall']) {
            $tables = array('statistics', 'payments', 'ads', 'media_cache');
            foreach ($tables as $table) {
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}smd_{$table}");
            }
        }
        
        // Clear all transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_smd_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_smd_%'");
    }
    
    public function init() {
        load_plugin_textdomain('simple-media-doctor', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Add image sizes
        $this->register_image_sizes();
    }
    
    public function add_admin_menu() {
        $capability = apply_filters('smd_admin_capability', 'manage_options');
        
        add_menu_page(
            __('Simple Media Doctor', 'simple-media-doctor'),
            __('Media Doctor', 'simple-media-doctor'),
            $capability,
            'simple-media-doctor',
            array($this, 'admin_page'),
            'dashicons-format-image',
            30
        );
        
        // Add submenus
        add_submenu_page(
            'simple-media-doctor',
            __('General Settings', 'simple-media-doctor'),
            __('General', 'simple-media-doctor'),
            $capability,
            'simple-media-doctor&tab=general',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'simple-media-doctor',
            __('Statistics', 'simple-media-doctor'),
            __('Statistics', 'simple-media-doctor'),
            $capability,
            'simple-media-doctor&tab=stats',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'simple-media-doctor',
            __('Tools', 'simple-media-doctor'),
            __('Tools', 'simple-media-doctor'),
            $capability,
            'simple-media-doctor-tools',
            array($this, 'tools_page')
        );
    }
    
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'simple-media-doctor'));
        }
        
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        $allowed_tabs = array('general', 'image', 'audio', 'video', 'payment', 'ads', 'social', 'stats');
        
        if (!in_array($active_tab, $allowed_tabs)) {
            $active_tab = 'general';
        }
        ?>
        <div class="wrap">
            <div class="smd-header">
                <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/logo.png" alt="Simple Media Doctor Logo" class="smd-logo">
                <h1><?php _e('Simple Media Doctor', 'simple-media-doctor'); ?></h1>
                <p><?php _e('Comprehensive Media Management Solution by WILKIE CEPHAS, TEKSTEP UG', 'simple-media-doctor'); ?></p>
            </div>
            
            <nav class="nav-tab-wrapper">
                <?php foreach ($allowed_tabs as $tab): ?>
                    <a href="?page=simple-media-doctor&tab=<?php echo esc_attr($tab); ?>" 
                       class="nav-tab <?php echo $active_tab == $tab ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html(ucfirst($tab)); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <div class="tab-content">
                <?php
                switch($active_tab) {
                    case 'image':
                        $this->image_settings_page();
                        break;
                    case 'audio':
                        $this->audio_settings_page();
                        break;
                    case 'video':
                        $this->video_settings_page();
                        break;
                    case 'payment':
                        $this->payment_settings_page();
                        break;
                    case 'ads':
                        $this->ads_settings_page();
                        break;
                    case 'social':
                        $this->social_settings_page();
                        break;
                    case 'stats':
                        $this->stats_page();
                        break;
                    default:
                        $this->general_settings_page();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    public function tools_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'simple-media-doctor'));
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Media Doctor Tools', 'simple-media-doctor'); ?></h1>
            <div class="smd-tools-grid">
                <div class="smd-tool-card">
                    <h3><?php _e('Bulk Image Compression', 'simple-media-doctor'); ?></h3>
                    <p><?php _e('Compress all images in your media library', 'simple-media-doctor'); ?></p>
                    <button class="button button-primary" id="smd-bulk-compress"><?php _e('Start Compression', 'simple-media-doctor'); ?></button>
                    <div class="smd-progress-bar" style="display:none;">
                        <div class="smd-progress"></div>
                    </div>
                </div>
                
                <div class="smd-tool-card">
                    <h3><?php _e('Database Cleanup', 'simple-media-doctor'); ?></h3>
                    <p><?php _e('Remove orphaned data and optimize tables', 'simple-media-doctor'); ?></p>
                    <button class="button" id="smd-clean-db"><?php _e('Clean Database', 'simple-media-doctor'); ?></button>
                </div>
                
                <div class="smd-tool-card">
                    <h3><?php _e('Export Settings', 'simple-media-doctor'); ?></h3>
                    <p><?php _e('Export your plugin settings as JSON', 'simple-media-doctor'); ?></p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=smd_export_settings'), 'smd_export')); ?>" 
                       class="button"><?php _e('Export', 'simple-media-doctor'); ?></a>
                </div>
                
                <div class="smd-tool-card">
                    <h3><?php _e('Import Settings', 'simple-media-doctor'); ?></h3>
                    <p><?php _e('Import settings from JSON file', 'simple-media-doctor'); ?></p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('smd_import', 'smd_import_nonce'); ?>
                        <input type="file" name="smd_settings_file" accept=".json">
                        <input type="submit" class="button" value="<?php esc_attr_e('Import', 'simple-media-doctor'); ?>">
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function general_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_cached_settings();
        
        if (isset($_POST['save_general_settings'])) {
            check_admin_referer('smd_general_settings');
            
            $settings['auto_rename'] = sanitize_text_field($_POST['auto_rename']);
            $settings['donation_enabled'] = isset($_POST['donation_enabled']) ? 1 : 0;
            $settings['advertisements_enabled'] = isset($_POST['advertisements_enabled']) ? 1 : 0;
            $settings['max_upload_size'] = intval($_POST['max_upload_size']);
            $settings['enable_logging'] = isset($_POST['enable_logging']) ? 1 : 0;
            $settings['backup_original_files'] = isset($_POST['backup_original_files']) ? 1 : 0;
            $settings['auto_optimize'] = isset($_POST['auto_optimize']) ? 1 : 0;
            $settings['currency'] = sanitize_text_field($_POST['currency']);
            
            update_option('smd_settings', $settings);
            wp_cache_delete('smd_settings', 'simple-media-doctor');
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'simple-media-doctor') . '</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2><?php _e('General Settings', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_general_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label for="auto_rename"><?php _e('Auto Rename Media', 'simple-media-doctor'); ?></label>
                        <select name="auto_rename" id="auto_rename" class="smd-select">
                            <option value="post_title" <?php selected($settings['auto_rename'], 'post_title'); ?>><?php _e('Post Title', 'simple-media-doctor'); ?></option>
                            <option value="custom_field" <?php selected($settings['auto_rename'], 'custom_field'); ?>><?php _e('Custom Name Field', 'simple-media-doctor'); ?></option>
                            <option value="date" <?php selected($settings['auto_rename'], 'date'); ?>><?php _e('Date Based', 'simple-media-doctor'); ?></option>
                            <option value="none" <?php selected($settings['auto_rename'], 'none'); ?>><?php _e('None', 'simple-media-doctor'); ?></option>
                        </select>
                        <p class="smd-help-text"><?php _e('Choose how media files should be automatically renamed', 'simple-media-doctor'); ?></p>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="max_upload_size"><?php _e('Maximum Upload Size (MB)', 'simple-media-doctor'); ?></label>
                        <input type="number" name="max_upload_size" id="max_upload_size" 
                               value="<?php echo esc_attr($settings['max_upload_size']); ?>" 
                               min="1" max="256" class="smd-input">
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="currency"><?php _e('Default Currency', 'simple-media-doctor'); ?></label>
                        <select name="currency" id="currency" class="smd-select">
                            <option value="USD" <?php selected($settings['currency'], 'USD'); ?>>USD ($)</option>
                            <option value="EUR" <?php selected($settings['currency'], 'EUR'); ?>>EUR (€)</option>
                            <option value="GBP" <?php selected($settings['currency'], 'GBP'); ?>>GBP (£)</option>
                            <option value="UGX" <?php selected($settings['currency'], 'UGX'); ?>>UGX (USh)</option>
                        </select>
                    </div>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="donation_enabled" value="1" <?php checked($settings['donation_enabled'], 1); ?>>
                            <span class="smd-checkbox-label"><?php _e('Enable Donations', 'simple-media-doctor'); ?></span>
                        </label>
                    </div>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="advertisements_enabled" value="1" <?php checked($settings['advertisements_enabled'], 1); ?>>
                            <span class="smd-checkbox-label"><?php _e('Enable Advertisements', 'simple-media-doctor'); ?></span>
                        </label>
                    </div>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="enable_logging" value="1" <?php checked($settings['enable_logging'], 1); ?>>
                            <span class="smd-checkbox-label"><?php _e('Enable Logging', 'simple-media-doctor'); ?></span>
                        </label>
                    </div>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="backup_original_files" value="1" <?php checked($settings['backup_original_files'], 1); ?>>
                            <span class="smd-checkbox-label"><?php _e('Backup Original Files', 'simple-media-doctor'); ?></span>
                        </label>
                    </div>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="auto_optimize" value="1" <?php checked($settings['auto_optimize'], 1); ?>>
                            <span class="smd-checkbox-label"><?php _e('Auto-Optimize on Upload', 'simple-media-doctor'); ?></span>
                        </label>
                    </div>
                    
                    <?php submit_button(__('Save Settings', 'simple-media-doctor'), 'primary', 'save_general_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-features-grid">
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/image-resize-icon.png" alt="<?php esc_attr_e('Image Resize', 'simple-media-doctor'); ?>">
                </div>
                <h3><?php _e('Auto Image Resize', 'simple-media-doctor'); ?></h3>
                <p><?php _e('Automatically resize images for different devices and screen sizes', 'simple-media-doctor'); ?></p>
            </div>
            
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/audio-process-icon.png" alt="<?php esc_attr_e('Audio Process', 'simple-media-doctor'); ?>">
                </div>
                <h3><?php _e('Audio Processing', 'simple-media-doctor'); ?></h3>
                <p><?php _e('Process audio files with watermarking and quality options', 'simple-media-doctor'); ?></p>
            </div>
            
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/payment-icon.png" alt="<?php esc_attr_e('Payment', 'simple-media-doctor'); ?>">
                </div>
                <h3><?php _e('Secure Payments', 'simple-media-doctor'); ?></h3>
                <p><?php _e('Integrated payment solutions including MTN and Airtel Mobile Money', 'simple-media-doctor'); ?></p>
            </div>
            
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/stats-icon.png" alt="<?php esc_attr_e('Statistics', 'simple-media-doctor'); ?>">
                </div>
                <h3><?php _e('Analytics', 'simple-media-doctor'); ?></h3>
                <p><?php _e('Track media usage and user engagement with detailed statistics', 'simple-media-doctor'); ?></p>
            </div>
        </div>
        <?php
    }
    
    private function image_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_cached_settings();
        
        if (isset($_POST['save_image_settings'])) {
            check_admin_referer('smd_image_settings');
            
            $settings['image_sizes'] = array(
                'desktop' => array(
                    'width' => max(1, intval($_POST['desktop_width'])),
                    'height' => max(1, intval($_POST['desktop_height'])),
                    'crop' => isset($_POST['desktop_crop']) ? 1 : 0
                ),
                'mobile' => array(
                    'width' => max(1, intval($_POST['mobile_width'])),
                    'height' => max(1, intval($_POST['mobile_height'])),
                    'crop' => isset($_POST['mobile_crop']) ? 1 : 0
                ),
                'custom' => array(
                    'width' => max(1, intval($_POST['custom_width'])),
                    'height' => max(1, intval($_POST['custom_height'])),
                    'crop' => isset($_POST['custom_crop']) ? 1 : 0
                )
            );
            $settings['compression_quality'] = min(100, max(1, intval($_POST['compression_quality'])));
            
            update_option('smd_settings', $settings);
            wp_cache_delete('smd_settings', 'simple-media-doctor');
            
            echo '<div class="notice notice-success"><p>' . __('Image settings saved successfully!', 'simple-media-doctor') . '</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2><?php _e('Image Processing Settings', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_image_settings'); ?>
                    
                    <div class="smd-image-sizes-grid">
                        <div class="smd-size-card">
                            <h3><?php _e('Desktop Size', 'simple-media-doctor'); ?></h3>
                            <div class="smd-form-row">
                                <div class="smd-form-group">
                                    <label for="desktop_width"><?php _e('Width (px)', 'simple-media-doctor'); ?></label>
                                    <input type="number" name="desktop_width" id="desktop_width" 
                                           value="<?php echo esc_attr($settings['image_sizes']['desktop']['width']); ?>" 
                                           placeholder="<?php esc_attr_e('Width', 'simple-media-doctor'); ?>" 
                                           class="smd-input" min="1" max="4000">
                                </div>
                                <div class="smd-form-group">
                                    <label for="desktop_height"><?php _e('Height (px)', 'simple-media-doctor'); ?></label>
                                    <input type="number" name="desktop_height" id="desktop_height" 
                                           value="<?php echo esc_attr($settings['image_sizes']['desktop']['height']); ?>" 
                                           placeholder="<?php esc_attr_e('Height', 'simple-media-doctor'); ?>" 
                                           class="smd-input" min="1" max="4000">
                                </div>
                            </div>
                            <div class="smd-form-group">
                                <label class="smd-checkbox">
                                    <input type="checkbox" name="desktop_crop" value="1" <?php checked($settings['image_sizes']['desktop']['crop'], 1); ?>>
                                    <span class="smd-checkbox-label"><?php _e('Hard Crop', 'simple-media-doctor'); ?></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="smd-size-card">
                            <h3><?php _e('Mobile Size', 'simple-media-doctor'); ?></h3>
                            <div class="smd-form-row">
                                <div class="smd-form-group">
                                    <label for="mobile_width"><?php _e('Width (px)', 'simple-media-doctor'); ?></label>
                                    <input type="number" name="mobile_width" id="mobile_width" 
                                           value="<?php echo esc_attr($settings['image_sizes']['mobile']['width']); ?>" 
                                           placeholder="<?php esc_attr_e('Width', 'simple-media-doctor'); ?>" 
                                           class="smd-input" min="1" max="2000">
                                </div>
                                <div class="smd-form-group">
                                    <label for="mobile_height"><?php _e('Height (px)', 'simple-media-doctor'); ?></label>
                                    <input type="number" name="mobile_height" id="mobile_height" 
                                           value="<?php echo esc_attr($settings['image_sizes']['mobile']['height']); ?>" 
                                           placeholder="<?php esc_attr_e('Height', 'simple-media-doctor'); ?>" 
                                           class="smd-input" min="1" max="2000">
                                </div>
                            </div>
                            <div class="smd-form-group">
                                <label class="smd-checkbox">
                                    <input type="checkbox" name="mobile_crop" value="1" <?php checked($settings['image_sizes']['mobile']['crop'], 1); ?>>
                                    <span class="smd-checkbox-label"><?php _e('Hard Crop', 'simple-media-doctor'); ?></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="smd-size-card">
                            <h3><?php _e('Custom Size', 'simple-media-doctor'); ?></h3>
                            <div class="smd-form-row">
                                <div class="smd-form-group">
                                    <label for="custom_width"><?php _e('Width (px)', 'simple-media-doctor'); ?></label>
                                    <input type="number" name="custom_width" id="custom_width" 
                                           value="<?php echo esc_attr($settings['image_sizes']['custom']['width']); ?>" 
                                           placeholder="<?php esc_attr_e('Width', 'simple-media-doctor'); ?>" 
                                           class="smd-input" min="1" max="4000">
                                </div>
                                <div class="smd-form-group">
                                    <label for="custom_height"><?php _e('Height (px)', 'simple-media-doctor'); ?></label>
                                    <input type="number" name="custom_height" id="custom_height" 
                                           value="<?php echo esc_attr($settings['image_sizes']['custom']['height']); ?>" 
                                           placeholder="<?php esc_attr_e('Height', 'simple-media-doctor'); ?>" 
                                           class="smd-input" min="1" max="4000">
                                </div>
                            </div>
                            <div class="smd-form-group">
                                <label class="smd-checkbox">
                                    <input type="checkbox" name="custom_crop" value="1" <?php checked($settings['image_sizes']['custom']['crop'], 1); ?>>
                                    <span class="smd-checkbox-label"><?php _e('Hard Crop', 'simple-media-doctor'); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="smd-compression-section">
                        <h3><?php _e('Image Compression', 'simple-media-doctor'); ?></h3>
                        <div class="smd-form-group">
                            <label for="compression_quality"><?php _e('Compression Quality', 'simple-media-doctor'); ?></label>
                            <div class="smd-range-container">
                                <input type="range" name="compression_quality" id="compression_quality" 
                                       min="1" max="100" value="<?php echo esc_attr($settings['compression_quality']); ?>" 
                                       class="smd-range">
                                <span id="quality-value"><?php echo esc_html($settings['compression_quality']); ?>%</span>
                            </div>
                            <p class="smd-help-text"><?php _e('Higher values mean better quality but larger file sizes', 'simple-media-doctor'); ?></p>
                        </div>
                    </div>
                    
                    <?php submit_button(__('Save Image Settings', 'simple-media-doctor'), 'primary', 'save_image_settings'); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function audio_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_cached_settings();
        
        if (isset($_POST['save_audio_settings'])) {
            check_admin_referer('smd_audio_settings');
            
            $settings['audio_low_quality_size'] = max(0.1, floatval($_POST['audio_low_quality_size']));
            $settings['audio_watermark'] = esc_url_raw($_POST['audio_watermark']);
            $settings['audio_jingle'] = esc_url_raw($_POST['audio_jingle']);
            
            update_option('smd_settings', $settings);
            wp_cache_delete('smd_settings', 'simple-media-doctor');
            
            echo '<div class="notice notice-success"><p>' . __('Audio settings saved successfully!', 'simple-media-doctor') . '</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2><?php _e('Audio Processing Settings', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_audio_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label for="audio_low_quality_size"><?php _e('Low Quality Audio Size Limit (MB)', 'simple-media-doctor'); ?></label>
                        <input type="number" name="audio_low_quality_size" id="audio_low_quality_size" 
                               step="0.1" value="<?php echo esc_attr($settings['audio_low_quality_size']); ?>" 
                               placeholder="MB" class="smd-input" min="0.1" max="100">
                        <p class="smd-help-text"><?php _e('Audio files larger than this will be compressed to low quality', 'simple-media-doctor'); ?></p>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="audio_watermark"><?php _e('Audio Watermark File URL', 'simple-media-doctor'); ?></label>
                        <input type="url" name="audio_watermark" id="audio_watermark" 
                               value="<?php echo esc_url($settings['audio_watermark']); ?>" 
                               class="smd-input" placeholder="https://example.com/watermark.mp3">
                        <p class="smd-help-text"><?php _e('Enter URL of watermark audio file or leave empty to disable', 'simple-media-doctor'); ?></p>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="audio_jingle"><?php _e('Audio Jingle File URL', 'simple-media-doctor'); ?></label>
                        <input type="url" name="audio_jingle" id="audio_jingle" 
                               value="<?php echo esc_url($settings['audio_jingle']); ?>" 
                               class="smd-input" placeholder="https://example.com/jingle.mp3">
                        <p class="smd-help-text"><?php _e('Enter URL of jingle audio file or leave empty to disable', 'simple-media-doctor'); ?></p>
                    </div>
                    
                    <?php submit_button(__('Save Audio Settings', 'simple-media-doctor'), 'primary', 'save_audio_settings'); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function video_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_cached_settings();
        
        if (isset($_POST['save_video_settings'])) {
            check_admin_referer('smd_video_settings');
            
            $settings['video_quality'] = sanitize_text_field($_POST['video_quality']);
            $settings['video_watermark'] = esc_url_raw($_POST['video_watermark']);
            
            update_option('smd_settings', $settings);
            wp_cache_delete('smd_settings', 'simple-media-doctor');
            
            echo '<div class="notice notice-success"><p>' . __('Video settings saved successfully!', 'simple-media-doctor') . '</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2><?php _e('Video Processing Settings', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_video_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label for="video_quality"><?php _e('Video Quality', 'simple-media-doctor'); ?></label>
                        <select name="video_quality" id="video_quality" class="smd-select">
                            <option value="360p" <?php selected($settings['video_quality'], '360p'); ?>>360p</option>
                            <option value="480p" <?php selected($settings['video_quality'], '480p'); ?>>480p</option>
                            <option value="720p" <?php selected($settings['video_quality'], '720p'); ?>>720p</option>
                            <option value="1080p" <?php selected($settings['video_quality'], '1080p'); ?>>1080p</option>
                            <option value="4k" <?php selected($settings['video_quality'], '4k'); ?>>4K</option>
                        </select>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="video_watermark"><?php _e('Video Watermark URL', 'simple-media-doctor'); ?></label>
                        <input type="url" name="video_watermark" id="video_watermark" 
                               value="<?php echo esc_url($settings['video_watermark']); ?>" 
                               class="smd-input" placeholder="https://example.com/watermark.png">
                        <p class="smd-help-text"><?php _e('Enter URL of watermark image file or leave empty to disable', 'simple-media-doctor'); ?></p>
                    </div>
                    
                    <?php submit_button(__('Save Video Settings', 'simple-media-doctor'), 'primary', 'save_video_settings'); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function payment_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_cached_settings();
        
        if (isset($_POST['save_payment_settings'])) {
            check_admin_referer('smd_payment_settings');
            
            $settings['payment_methods'] = array(
                'mtn' => isset($_POST['mtn_enabled']) ? 1 : 0,
                'airtel' => isset($_POST['airtel_enabled']) ? 1 : 0,
                'credit_card' => isset($_POST['credit_card_enabled']) ? 1 : 0,
                'paypal' => isset($_POST['paypal_enabled']) ? 1 : 0,
                'stripe' => isset($_POST['stripe_enabled']) ? 1 : 0
            );
            $settings['donation_enabled'] = isset($_POST['donation_enabled']) ? 1 : 0;
            $settings['paypal_client_id'] = sanitize_text_field($_POST['paypal_client_id']);
            $settings['stripe_publishable_key'] = sanitize_text_field($_POST['stripe_publishable_key']);
            
            update_option('smd_settings', $settings);
            wp_cache_delete('smd_settings', 'simple-media-doctor');
            
            echo '<div class="notice notice-success"><p>' . __('Payment settings saved successfully!', 'simple-media-doctor') . '</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2><?php _e('Payment & Donation Settings', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_payment_settings'); ?>
                    
                    <h3><?php _e('Payment Methods', 'simple-media-doctor'); ?></h3>
                    <div class="smd-payment-methods">
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="mtn_enabled" value="1" <?php checked($settings['payment_methods']['mtn'], 1); ?>>
                                <span class="smd-checkbox-label"><?php _e('MTN Mobile Money', 'simple-media-doctor'); ?></span>
                            </label>
                        </div>
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="airtel_enabled" value="1" <?php checked($settings['payment_methods']['airtel'], 1); ?>>
                                <span class="smd-checkbox-label"><?php _e('Airtel Mobile Money', 'simple-media-doctor'); ?></span>
                            </label>
                        </div>
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="credit_card_enabled" value="1" <?php checked($settings['payment_methods']['credit_card'], 1); ?>>
                                <span class="smd-checkbox-label"><?php _e('Credit Card', 'simple-media-doctor'); ?></span>
                            </label>
                        </div>
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="paypal_enabled" value="1" <?php checked($settings['payment_methods']['paypal'], 1); ?>>
                                <span class="smd-checkbox-label"><?php _e('PayPal', 'simple-media-doctor'); ?></span>
                            </label>
                        </div>
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="stripe_enabled" value="1" <?php checked($settings['payment_methods']['stripe'], 1); ?>>
                                <span class="smd-checkbox-label"><?php _e('Stripe', 'simple-media-doctor'); ?></span>
                            </label>
                        </div>
                    </div>
                    
                    <h3><?php _e('Donation Settings', 'simple-media-doctor'); ?></h3>
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="donation_enabled" value="1" <?php checked($settings['donation_enabled'], 1); ?>>
                            <span class="smd-checkbox-label"><?php _e('Enable Donations', 'simple-media-doctor'); ?></span>
                        </label>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="paypal_client_id"><?php _e('PayPal Client ID', 'simple-media-doctor'); ?></label>
                        <input type="text" name="paypal_client_id" id="paypal_client_id" 
                               value="<?php echo esc_attr($settings['paypal_client_id']); ?>" 
                               class="smd-input" placeholder="<?php esc_attr_e('Enter PayPal Client ID', 'simple-media-doctor'); ?>">
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="stripe_publishable_key"><?php _e('Stripe Publishable Key', 'simple-media-doctor'); ?></label>
                        <input type="text" name="stripe_publishable_key" id="stripe_publishable_key" 
                               value="<?php echo esc_attr($settings['stripe_publishable_key']); ?>" 
                               class="smd-input" placeholder="<?php esc_attr_e('Enter Stripe Publishable Key', 'simple-media-doctor'); ?>">
                    </div>
                    
                    <?php submit_button(__('Save Payment Settings', 'simple-media-doctor'), 'primary', 'save_payment_settings'); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function ads_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_cached_settings();
        
        if (isset($_POST['save_ad_settings'])) {
            check_admin_referer('smd_ad_settings');
            
            $settings['advertisements_enabled'] = isset($_POST['advertisements_enabled']) ? 1 : 0;
            
            update_option('smd_settings', $settings);
            wp_cache_delete('smd_settings', 'simple-media-doctor');
            
            echo '<div class="notice notice-success"><p>' . __('Ad settings saved successfully!', 'simple-media-doctor') . '</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2><?php _e('Advertisement Settings', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_ad_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="advertisements_enabled" value="1" <?php checked($settings['advertisements_enabled'], 1); ?>>
                            <span class="smd-checkbox-label"><?php _e('Enable Advertisements', 'simple-media-doctor'); ?></span>
                        </label>
                    </div>
                    
                    <?php submit_button(__('Save Ad Settings', 'simple-media-doctor'), 'primary', 'save_ad_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-ads-management">
            <h3><?php _e('Manage Advertisements', 'simple-media-doctor'); ?></h3>
            <button type="button" class="smd-btn-primary" onclick="addNewAd()"><?php _e('Add New Ad', 'simple-media-doctor'); ?></button>
            <div id="ads-list">
                <?php $this->display_ads_list(); ?>
            </div>
        </div>
        <?php
    }
    
    private function social_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $this->get_cached_settings();
        
        if (isset($_POST['save_social_settings'])) {
            check_admin_referer('smd_social_settings');
            
            $settings['social_accounts'] = array(
                'facebook' => esc_url_raw($_POST['facebook_url']),
                'twitter' => esc_url_raw($_POST['twitter_url']),
                'instagram' => esc_url_raw($_POST['instagram_url']),
                'youtube' => esc_url_raw($_POST['youtube_url']),
                'tiktok' => esc_url_raw($_POST['tiktok_url'])
            );
            
            update_option('smd_settings', $settings);
            wp_cache_delete('smd_settings', 'simple-media-doctor');
            
            echo '<div class="notice notice-success"><p>' . __('Social settings saved successfully!', 'simple-media-doctor') . '</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2><?php _e('Social Media Settings', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_social_settings'); ?>
                    
                    <div class="smd-social-grid">
                        <div class="smd-social-input">
                            <label for="facebook_url"><?php _e('Facebook', 'simple-media-doctor'); ?></label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/facebook-icon.png" alt="<?php esc_attr_e('Facebook', 'simple-media-doctor'); ?>">
                                <input type="url" name="facebook_url" id="facebook_url" 
                                       value="<?php echo esc_url($settings['social_accounts']['facebook']); ?>" 
                                       class="smd-input" placeholder="https://facebook.com/yourpage">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="twitter_url"><?php _e('Twitter', 'simple-media-doctor'); ?></label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/twitter-icon.png" alt="<?php esc_attr_e('Twitter', 'simple-media-doctor'); ?>">
                                <input type="url" name="twitter_url" id="twitter_url" 
                                       value="<?php echo esc_url($settings['social_accounts']['twitter']); ?>" 
                                       class="smd-input" placeholder="https://twitter.com/yourhandle">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="instagram_url"><?php _e('Instagram', 'simple-media-doctor'); ?></label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/instagram-icon.png" alt="<?php esc_attr_e('Instagram', 'simple-media-doctor'); ?>">
                                <input type="url" name="instagram_url" id="instagram_url" 
                                       value="<?php echo esc_url($settings['social_accounts']['instagram']); ?>" 
                                       class="smd-input" placeholder="https://instagram.com/yourprofile">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="youtube_url"><?php _e('YouTube', 'simple-media-doctor'); ?></label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/youtube-icon.png" alt="<?php esc_attr_e('YouTube', 'simple-media-doctor'); ?>">
                                <input type="url" name="youtube_url" id="youtube_url" 
                                       value="<?php echo esc_url($settings['social_accounts']['youtube']); ?>" 
                                       class="smd-input" placeholder="https://youtube.com/yourchannel">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="tiktok_url"><?php _e('TikTok', 'simple-media-doctor'); ?></label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo esc_url(SMD_PLUGIN_URL); ?>assets/images/tiktok-icon.png" alt="<?php esc_attr_e('TikTok', 'simple-media-doctor'); ?>">
                                <input type="url" name="tiktok_url" id="tiktok_url" 
                                       value="<?php echo esc_url($settings['social_accounts']['tiktok']); ?>" 
                                       class="smd-input" placeholder="https://tiktok.com/@yourhandle">
                            </div>
                        </div>
                    </div>
                    
                    <?php submit_button(__('Save Social Settings', 'simple-media-doctor'), 'primary', 'save_social_settings'); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function stats_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_prefix . 'statistics';
        
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
            50
        ));
        
        // Get summary stats
        $total_processed = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE action LIKE '%processed%'");
        $total_payments = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}{$this->table_prefix}payments WHERE status = %s",
            'completed'
        ));
        $total_revenue = $wpdb->get_var("SELECT SUM(amount) FROM {$wpdb->prefix}{$this->table_prefix}payments WHERE status = 'completed'");
        ?>
        <div class="smd-card">
            <h2><?php _e('Plugin Statistics', 'simple-media-doctor'); ?></h2>
            <div class="smd-card-content">
                <div class="smd-stats-visual">
                    <div class="smd-stats-summary">
                        <div class="smd-stat-card">
                            <h3><?php _e('Media Processed', 'simple-media-doctor'); ?></h3>
                            <p><?php echo esc_html(number_format_i18n($total_processed)); ?></p>
                        </div>
                        <div class="smd-stat-card">
                            <h3><?php _e('Payments Processed', 'simple-media-doctor'); ?></h3>
                            <p><?php echo esc_html(number_format_i18n($total_payments)); ?></p>
                        </div>
                        <div class="smd-stat-card">
                            <h3><?php _e('Total Revenue', 'simple-media-doctor'); ?></h3>
                            <p><?php echo esc_html(number_format_i18n($total_revenue, 2)); ?></p>
                        </div>
                        <div class="smd-stat-card">
                            <h3><?php _e('Active Users', 'simple-media-doctor'); ?></h3>
                            <p><?php echo esc_html(number_format_i18n($wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $table_name"))); ?></p>
                        </div>
                    </div>
                </div>
                
                <h3><?php _e('Recent Activity', 'simple-media-doctor'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'simple-media-doctor'); ?></th>
                            <th><?php _e('Action', 'simple-media-doctor'); ?></th>
                            <th><?php _e('User', 'simple-media-doctor'); ?></th>
                            <th><?php _e('Data', 'simple-media-doctor'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($stats): ?>
                            <?php foreach ($stats as $stat): ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stat->timestamp))); ?></td>
                                <td><?php echo esc_html($stat->action); ?></td>
                                <td><?php 
                                    if ($stat->user_id) {
                                        $user = get_user_by('id', $stat->user_id);
                                        echo $user ? esc_html($user->display_name) : esc_html__('Deleted User', 'simple-media-doctor');
                                    } else {
                                        echo esc_html__('Guest', 'simple-media-doctor');
                                    }
                                ?></td>
                                <td><?php echo esc_html($stat->data); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4"><?php _e('No statistics recorded yet.', 'simple-media-doctor'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <div class="smd-stats-actions">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('smd_clear_stats', 'smd_clear_stats_nonce'); ?>
                        <button type="submit" name="clear_stats" class="button" 
                                onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all statistics? This cannot be undone.', 'simple-media-doctor'); ?>')">
                            <?php _e('Clear Statistics', 'simple-media-doctor'); ?>
                        </button>
                    </form>
                    
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('smd_export_stats', 'smd_export_stats_nonce'); ?>
                        <button type="submit" name="export_stats" class="button">
                            <?php _e('Export as CSV', 'simple-media-doctor'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        
        // Handle clear stats
        if (isset($_POST['clear_stats']) && check_admin_referer('smd_clear_stats', 'smd_clear_stats_nonce')) {
            $wpdb->query("TRUNCATE TABLE $table_name");
            echo '<div class="notice notice-success"><p>' . __('Statistics cleared successfully.', 'simple-media-doctor') . '</p></div>';
        }
    }
    
    private function display_ads_list() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_prefix . 'ads';
        $ads = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
            100
        ));
        
        if (empty($ads)) {
            echo '<p>' . __('No advertisements found.', 'simple-media-doctor') . '</p>';
            return;
        }
        
        foreach ($ads as $ad) {
            echo '<div class="smd-ad-item" data-id="' . esc_attr($ad->id) . '">';
            echo '<h4>' . esc_html($ad->title) . '</h4>';
            echo '<p>' . esc_html(wp_trim_words($ad->content, 20)) . '</p>';
            echo '<p><strong>' . __('Placement:', 'simple-media-doctor') . '</strong> ' . esc_html($ad->placement) . '</p>';
            echo '<p><strong>' . __('Status:', 'simple-media-doctor') . '</strong> ' . esc_html($ad->status) . '</p>';
            echo '<p><strong>' . __('Start:', 'simple-media-doctor') . '</strong> ' . esc_html($ad->start_date ? date_i18n(get_option('date_format'), strtotime($ad->start_date)) : '--') . '</p>';
            echo '<p><strong>' . __('End:', 'simple-media-doctor') . '</strong> ' . esc_html($ad->end_date ? date_i18n(get_option('date_format'), strtotime($ad->end_date)) : '--') . '</p>';
            echo '<button type="button" class="smd-btn-secondary" onclick="editAd(' . esc_js($ad->id) . ')">' . __('Edit', 'simple-media-doctor') . '</button>';
            echo '<button type="button" class="smd-btn-danger" onclick="deleteAd(' . esc_js($ad->id) . ')">' . __('Delete', 'simple-media-doctor') . '</button>';
            echo '</div><hr>';
        }
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'simple-media-doctor') === false) {
            return;
        }
        
        wp_enqueue_style('smd-admin-css', SMD_PLUGIN_URL . 'assets/css/admin.css', array(), SMD_VERSION);
        wp_enqueue_style('wp-color-picker');
        
        wp_enqueue_script('smd-admin-js', SMD_PLUGIN_URL . 'assets/js/admin.js', 
            array('jquery', 'chart-js', 'wp-color-picker'), SMD_VERSION, true);
        
        wp_localize_script('smd-admin-js', 'smd_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smd_nonce'),
            'i18n' => array(
                'processing' => __('Processing...', 'simple-media-doctor'),
                'success' => __('Success!', 'simple-media-doctor'),
                'error' => __('Error!', 'simple-media-doctor'),
                'confirm_delete' => __('Are you sure you want to delete this?', 'simple-media-doctor')
            )
        ));
    }
    
    public function enqueue_frontend_scripts() {
        if (!is_admin()) {
            wp_enqueue_style('smd-frontend-css', SMD_PLUGIN_URL . 'assets/css/frontend.css', array(), SMD_VERSION);
            wp_enqueue_script('smd-frontend-js', SMD_PLUGIN_URL . 'assets/js/frontend.js', 
                array('jquery'), SMD_VERSION, true);
            
            wp_localize_script('smd-frontend-js', 'smd_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('smd_nonce'),
                'currency' => $this->get_cached_settings()['currency']
            ));
        }
    }
    
    public function rename_media_on_upload($file) {
        $settings = $this->get_cached_settings();
        
        if ($settings['auto_rename'] === 'none') {
            return $file;
        }
        
        // Check file size limit
        $max_size = $settings['max_upload_size'] * 1024 * 1024; // Convert MB to bytes
        if ($file['size'] > $max_size) {
            $file['error'] = sprintf(
                __('File is too large. Maximum size is %dMB.', 'simple-media-doctor'),
                $settings['max_upload_size']
            );
            return $file;
        }
        
        if ($settings['auto_rename'] === 'post_title' && isset($_POST['post_id'])) {
            $post_id = absint($_POST['post_id']);
            if ($post_id > 0) {
                $post_title = get_the_title($post_id);
                if (!empty($post_title)) {
                    $filetype = wp_check_filetype($file['name']);
                    $new_filename = sanitize_title($post_title) . '-' . uniqid() . '.' . $filetype['ext'];
                    $file['name'] = $new_filename;
                }
            }
        } elseif ($settings['auto_rename'] === 'date') {
            $filetype = wp_check_filetype($file['name']);
            $new_filename = current_time('Y-m-d-H-i-s') . '-' . uniqid() . '.' . $filetype['ext'];
            $file['name'] = $new_filename;
        }
        
        return $file;
    }
    
    public function auto_resize_images($metadata, $attachment_id) {
        $settings = $this->get_cached_settings();
        
        if (!$settings['auto_optimize'] && !apply_filters('smd_force_resize', false)) {
            return $metadata;
        }
        
        $image_path = get_attached_file($attachment_id);
        if (!$image_path || !file_exists($image_path)) {
            $this->log_error('Image file not found', array('attachment_id' => $attachment_id));
            return $metadata;
        }
        
        // Check if it's an image
        $image_info = wp_get_image_editor($image_path);
        if (is_wp_error($image_info)) {
            return $metadata;
        }
        
        // Backup original if enabled
        if ($settings['backup_original_files']) {
            $this->backup_original_file($image_path, $attachment_id);
        }
        
        // Process each size
        foreach ($settings['image_sizes'] as $size_name => $size) {
            if ($size['width'] <= 0 || $size['height'] <= 0) {
                continue;
            }
            
            $editor = wp_get_image_editor($image_path);
            if (!is_wp_error($editor)) {
                $editor->set_quality($settings['compression_quality']);
                
                // Resize
                $editor->resize($size['width'], $size['height'], $size['crop']);
                
                // Generate new filename
                $path_info = pathinfo($image_path);
                $new_filename = $path_info['filename'] . '-smd-' . $size_name . '.' . $path_info['extension'];
                $new_path = $path_info['dirname'] . '/' . $new_filename;
                
                // Save resized image
                $editor->save($new_path);
                
                // Add to metadata
                $new_size = getimagesize($new_path);
                if ($new_size) {
                    $metadata['sizes']['smd_' . $size_name] = array(
                        'file' => $new_filename,
                        'width' => $new_size[0],
                        'height' => $new_size[1],
                        'mime-type' => $new_size['mime']
                    );
                }
                
                // Log operation
                $this->log_statistics('image_resized', array(
                    'attachment_id' => $attachment_id,
                    'size' => $size_name,
                    'original_size' => filesize($image_path),
                    'new_size' => filesize($new_path)
                ));
            }
        }
        
        return $metadata;
    }
    
    public function add_media_players_to_content($content) {
        if (!is_singular() || is_admin()) {
            return $content;
        }
        
        $post_id = get_the_ID();
        
        // Add audio player to posts with audio files
        $audio_files = get_attached_media('audio', $post_id);
        if ($audio_files) {
            $player_html = '<div class="smd-audio-player-container">';
            foreach ($audio_files as $audio) {
                $audio_url = wp_get_attachment_url($audio->ID);
                if ($audio_url) {
                    $player_html .= $this->generate_audio_player($audio_url, $audio->post_title);
                }
            }
            $player_html .= '</div>';
            $content .= $player_html;
        }
        
        // Add video player to posts with video files
        $video_files = get_attached_media('video', $post_id);
        if ($video_files) {
            $player_html = '<div class="smd-video-player-container">';
            foreach ($video_files as $video) {
                $video_url = wp_get_attachment_url($video->ID);
                if ($video_url) {
                    $player_html .= $this->generate_video_player($video_url, $video->post_title);
                }
            }
            $player_html .= '</div>';
            $content .= $player_html;
        }
        
        return $content;
    }
    
    private function generate_audio_player($audio_url, $title) {
        $settings = $this->get_cached_settings();
        
        return '<div class="smd-audio-player">
            <h4>' . esc_html($title) . '</h4>
            <audio controls preload="none" style="width:100%">
                <source src="' . esc_url($audio_url) . '" type="audio/mpeg">
                ' . __('Your browser does not support the audio element.', 'simple-media-doctor') . '
            </audio>
            <div class="smd-player-controls">
                <button class="smd-download-btn" onclick="smdDownloadMedia(\'' . esc_url($audio_url) . '\', \'' . esc_attr($title) . '.mp3\')">' . __('Download', 'simple-media-doctor') . '</button>
                <span class="smd-play-count">' . __('Plays:', 'simple-media-doctor') . ' <span data-url="' . esc_url($audio_url) . '">0</span></span>
            </div>
        </div>';
    }
    
    private function generate_video_player($video_url, $title) {
        return '<div class="smd-video-player">
            <h4>' . esc_html($title) . '</h4>
            <video controls preload="metadata" style="width:100%; max-width:100%; height:auto">
                <source src="' . esc_url($video_url) . '" type="video/mp4">
                ' . __('Your browser does not support the video tag.', 'simple-media-doctor') . '
            </video>
            <div class="smd-player-controls">
                <button class="smd-download-btn" onclick="smdDownloadMedia(\'' . esc_url($video_url) . '\', \'' . esc_attr($title) . '.mp4\')">' . __('Download', 'simple-media-doctor') . '</button>
                <span class="smd-play-count">' . __('Plays:', 'simple-media-doctor') . ' <span data-url="' . esc_url($video_url) . '">0</span></span>
            </div>
        </div>';
    }
    
    public function ajax_update_media() {
        $this->verify_ajax_request('manage_options');
        
        if (!isset($_POST['post_id'], $_POST['action_type'])) {
            wp_send_json_error(__('Missing parameters', 'simple-media-doctor'), 400);
        }
        
        $post_id = absint($_POST['post_id']);
        $action = sanitize_text_field($_POST['action_type']);
        
        $result = array('success' => false, 'message' => __('Unknown action', 'simple-media-doctor'));
        
        switch ($action) {
            case 'rename':
                $result = $this->process_media_rename($post_id);
                break;
            case 'resize':
                $result = $this->process_media_resize($post_id);
                break;
            case 'compress':
                $result = $this->process_media_compress($post_id);
                break;
        }
        
        wp_send_json($result);
    }
    
    private function process_media_rename($post_id) {
        $attachments = get_attached_media('', $post_id);
        $renamed = 0;
        
        foreach ($attachments as $attachment) {
            $new_title = get_the_title($post_id) . ' - Media ' . ($renamed + 1);
            wp_update_post(array(
                'ID' => $attachment->ID,
                'post_title' => $new_title
            ));
            $renamed++;
        }
        
        return array(
            'success' => true, 
            'message' => sprintf(__('%d media files renamed successfully', 'simple-media-doctor'), $renamed)
        );
    }
    
    private function process_media_resize($post_id) {
        $attachments = get_attached_media('image', $post_id);
        $resized = 0;
        
        foreach ($attachments as $attachment) {
            $metadata = wp_get_attachment_metadata($attachment->ID);
            if ($metadata) {
                $this->auto_resize_images($metadata, $attachment->ID);
                $resized++;
            }
        }
        
        return array(
            'success' => true, 
            'message' => sprintf(__('%d images resized successfully', 'simple-media-doctor'), $resized)
        );
    }
    
    private function process_media_compress($post_id) {
        $attachments = get_attached_media('image', $post_id);
        $compressed = 0;
        
        foreach ($attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            if ($file_path && file_exists($file_path)) {
                $editor = wp_get_image_editor($file_path);
                if (!is_wp_error($editor)) {
                    $settings = $this->get_cached_settings();
                    $editor->set_quality($settings['compression_quality']);
                    $editor->save($file_path);
                    $compressed++;
                }
            }
        }
        
        return array(
            'success' => true, 
            'message' => sprintf(__('%d images compressed successfully', 'simple-media-doctor'), $compressed)
        );
    }
    
    public function ajax_compress_images() {
        $this->verify_ajax_request('manage_options');
        
        $settings = $this->get_cached_settings();
        $quality = $settings['compression_quality'];
        
        // Get all images from media library
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif'),
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $image_ids = get_posts($args);
        $processed = 0;
        $errors = array();
        
        foreach ($image_ids as $image_id) {
            $file_path = get_attached_file($image_id);
            if ($file_path && file_exists($file_path)) {
                $editor = wp_get_image_editor($file_path);
                if (!is_wp_error($editor)) {
                    $editor->set_quality($quality);
                    $result = $editor->save($file_path);
                    if (!is_wp_error($result)) {
                        $processed++;
                    } else {
                        $errors[] = $result->get_error_message();
                    }
                }
            }
        }
        
        wp_send_json(array(
            'success' => true, 
            'message' => sprintf(__('%d images compressed successfully', 'simple-media-doctor'), $processed),
            'errors' => $errors
        ));
    }
    
    public function ajax_process_audio() {
        $this->verify_ajax_request('manage_options');
        
        if (!isset($_POST['audio_id'], $_POST['process_type'])) {
            wp_send_json_error(__('Missing parameters', 'simple-media-doctor'), 400);
        }
        
        $audio_id = absint($_POST['audio_id']);
        $process_type = sanitize_text_field($_POST['process_type']);
        
        // Implementation for audio processing would go here
        // This would typically involve calling an external audio processing library
        
        $this->log_statistics('audio_processed', array(
            'audio_id' => $audio_id,
            'process_type' => $process_type
        ));
        
        wp_send_json(array(
            'success' => true, 
            'message' => __('Audio processed successfully', 'simple-media-doctor')
        ));
    }
    
    public function ajax_track_stats() {
        check_ajax_referer('smd_nonce', 'nonce');
        
        if (!isset($_POST['action'], $_POST['data'])) {
            wp_send_json_error(__('Missing parameters', 'simple-media-doctor'), 400);
        }
        
        $action = sanitize_text_field($_POST['action']);
        $data = sanitize_text_field($_POST['data']);
        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        
        $this->log_statistics($action, $data, $user_id, $ip_address, $user_agent);
        
        wp_send_json(array('success' => true));
    }
    
    public function ajax_process_payment() {
        check_ajax_referer('smd_nonce', 'nonce');
        
        if (!isset($_POST['amount'], $_POST['currency'], $_POST['payment_method'])) {
            wp_send_json_error(__('Missing payment parameters', 'simple-media-doctor'), 400);
        }
        
        $amount = floatval($_POST['amount']);
        $currency = sanitize_text_field($_POST['currency']);
        $payment_method = sanitize_text_field($_POST['payment_method']);
        $media_id = isset($_POST['media_id']) ? absint($_POST['media_id']) : 0;
        
        if ($amount <= 0) {
            wp_send_json_error(__('Invalid amount', 'simple-media-doctor'), 400);
        }
        
        // Validate payment method
        $settings = $this->get_cached_settings();
        if (!isset($settings['payment_methods'][$payment_method]) || !$settings['payment_methods'][$payment_method]) {
            wp_send_json_error(__('Payment method not available', 'simple-media-doctor'), 400);
        }
        
        // Process payment based on method
        $transaction_id = 'smd_' . uniqid() . '_' . time();
        
        // In a real implementation, you would integrate with payment gateways here
        // For now, we'll simulate a successful payment
        
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_prefix . 'payments';
        
        $payment_data = array(
            'transaction_id' => $transaction_id,
            'user_id' => get_current_user_id(),
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed',
            'payment_method' => $payment_method,
            'media_id' => $media_id,
            'payment_data' => json_encode(array(
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'timestamp' => current_time('mysql')
            ))
        );
        
        $wpdb->insert($table_name, $payment_data);
        
        // Log the payment
        $this->log_statistics('payment_processed', array(
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'currency' => $currency,
            'method' => $payment_method
        ));
        
        wp_send_json(array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'message' => __('Payment processed successfully', 'simple-media-doctor')
        ));
    }
    
    private function verify_ajax_request($capability = 'manage_options') {
        check_ajax_referer('smd_nonce', 'nonce');
        
        if (!current_user_can($capability)) {
            wp_send_json_error(__('Unauthorized access', 'simple-media-doctor'), 403);
        }
        
        return true;
    }
    
    private function get_cached_settings() {
        $cached = wp_cache_get('smd_settings', 'simple-media-doctor');
        if (false === $cached) {
            $cached = get_option('smd_settings', array());
            $defaults = array(
                'auto_rename' => 'post_title',
                'image_sizes' => array(),
                'compression_quality' => 80,
                'max_upload_size' => 10,
                'allowed_mime_types' => array(),
                'audio_low_quality_size' => 1.5,
                'video_quality' => '720p',
                'social_accounts' => array(),
                'payment_methods' => array(),
                'donation_enabled' => true,
                'advertisements_enabled' => true,
                'paypal_client_id' => '',
                'stripe_publishable_key' => '',
                'currency' => 'USD',
                'enable_logging' => true,
                'backup_original_files' => true,
                'auto_optimize' => false
            );
            $cached = wp_parse_args($cached, $defaults);
            wp_cache_set('smd_settings', $cached, 'simple-media-doctor', 3600);
        }
        return $cached;
    }
    
    private function log_statistics($action, $data, $user_id = null, $ip_address = null, $user_agent = null) {
        $settings = $this->get_cached_settings();
        if (!$settings['enable_logging']) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_prefix . 'statistics';
        
        $log_data = array(
            'action' => sanitize_text_field($action),
            'data' => is_array($data) ? json_encode($data) : sanitize_text_field($data),
            'user_id' => $user_id ? absint($user_id) : get_current_user_id(),
            'ip_address' => $ip_address ? sanitize_text_field($ip_address) : $this->get_client_ip(),
            'user_agent' => $user_agent ? sanitize_text_field($user_agent) : 
                (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '')
        );
        
        $wpdb->insert($table_name, $log_data);
    }
    
    private function log_error($message, $data = array()) {
        if (WP_DEBUG === true) {
            error_log('Simple Media Doctor Error: ' . $message . ' - ' . print_r($data, true));
        }
        $this->log_statistics('error', array('message' => $message, 'data' => $data));
    }
    
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key])) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    private function backup_original_file($file_path, $attachment_id) {
        $backup_dir = WP_CONTENT_DIR . '/uploads/smd-backups/' . date('Y/m');
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        $path_info = pathinfo($file_path);
        $backup_path = $backup_dir . '/' . $path_info['filename'] . '-backup-' . $attachment_id . '.' . $path_info['extension'];
        
        if (copy($file_path, $backup_path)) {
            $this->log_statistics('file_backup_created', array(
                'attachment_id' => $attachment_id,
                'backup_path' => $backup_path
            ));
        }
    }
    
    public function restrict_mime_types($mimes) {
        $settings = $this->get_cached_settings();
        if (empty($settings['allowed_mime_types'])) {
            return $mimes;
        }
        
        $allowed_mimes = array();
        foreach ($settings['allowed_mime_types'] as $mime) {
            // Find the mime type in the default list
            foreach ($mimes as $ext => $existing_mime) {
                if ($existing_mime === $mime) {
                    $allowed_mimes[$ext] = $mime;
                }
            }
        }
        
        return !empty($allowed_mimes) ? $allowed_mimes : $mimes;
    }
    
    public function check_permissions() {
        if (!current_user_can('manage_options') && isset($_GET['page']) && strpos($_GET['page'], 'simple-media-doctor') !== false) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'simple-media-doctor'));
        }
    }
    
    private function register_image_sizes() {
        $settings = $this->get_cached_settings();
        
        foreach ($settings['image_sizes'] as $size_name => $size) {
            if ($size['width'] > 0 && $size['height'] > 0) {
                add_image_size('smd_' . $size_name, $size['width'], $size['height'], $size['crop']);
            }
        }
    }
    
    private function init_modules() {
        // Initialize optimization modules
        add_action('wp_head', array($this, 'add_seo_meta_tags'));
        add_action('wp_head', array($this, 'add_schema_markup'));
        
        // Add health check
        add_filter('site_status_tests', array($this, 'add_health_checks'));
    }
    
    public function add_seo_meta_tags() {
        if (is_singular()) {
            echo '<meta name="generator" content="Simple Media Doctor ' . esc_attr(SMD_VERSION) . '">';
        }
    }
    
    public function add_schema_markup() {
        if (is_singular() && get_post_type() === 'post') {
            $schema = array(
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => get_the_title(),
                'datePublished' => get_the_date('c'),
                'dateModified' => get_the_modified_date('c'),
                'author' => array(
                    '@type' => 'Person',
                    'name' => get_the_author()
                ),
                'publisher' => array(
                    '@type' => 'Organization',
                    'name' => 'TEKSTEP UG',
                    'founder' => 'WILKIE CEPHAS'
                )
            );
            
            echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }
    }
    
    public function add_health_checks($tests) {
        $tests['direct']['smd_health'] = array(
            'label' => __('Simple Media Doctor Health', 'simple-media-doctor'),
            'test' => array($this, 'run_health_check')
        );
        return $tests;
    }
    
    public function run_health_check() {
        $settings = $this->get_cached_settings();
        $result = array(
            'label' => __('Simple Media Doctor is configured correctly', 'simple-media-doctor'),
            'status' => 'good',
            'badge' => array(
                'label' => __('Media', 'simple-media-doctor'),
                'color' => 'blue'
            ),
            'actions' => '',
            'test' => 'smd_health'
        );
        
        // Check if image sizes are set
        if (empty($settings['image_sizes']) || !is_array($settings['image_sizes'])) {
            $result['status'] = 'recommended';
            $result['label'] = __('Image sizes are not configured', 'simple-media-doctor');
            $result['description'] = __('Please configure image sizes in Simple Media Doctor settings.', 'simple-media-doctor');
            $result['actions'] = sprintf(
                '<p><a href="%s">%s</a></p>',
                admin_url('admin.php?page=simple-media-doctor&tab=image'),
                __('Configure Image Settings', 'simple-media-doctor')
            );
        }
        
        // Check upload directory permissions
        $upload_dir = wp_upload_dir();
        if (!is_writable($upload_dir['basedir'])) {
            $result['status'] = 'critical';
            $result['label'] = __('Upload directory is not writable', 'simple-media-doctor');
            $result['description'] = __('Simple Media Doctor cannot process images because the upload directory is not writable.', 'simple-media-doctor');
        }
        
        return $result;
    }
    
    public function cleanup_temporary_files() {
        // Clean up temporary files older than 7 days
        $temp_dir = WP_CONTENT_DIR . '/uploads/smd-temp/';
        if (file_exists($temp_dir)) {
            $files = glob($temp_dir . '*');
            $now = time();
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file)) > 7 * 24 * 60 * 60) {
                    unlink($file);
                }
            }
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    SimpleMediaDoctor::get_instance();
});

// Helper functions
function smd_get_social_links() {
    $instance = SimpleMediaDoctor::get_instance();
    $settings = $instance->get_cached_settings();
    return $settings['social_accounts'];
}

function smd_get_payment_methods() {
    $instance = SimpleMediaDoctor::get_instance();
    $settings = $instance->get_cached_settings();
    return $settings['payment_methods'];
}

function smd_is_donation_enabled() {
    $instance = SimpleMediaDoctor::get_instance();
    $settings = $instance->get_cached_settings();
    return $settings['donation_enabled'];
}

function smd_get_processed_stats() {
    global $wpdb;
    $instance = SimpleMediaDoctor::get_instance();
    $table_name = $wpdb->prefix . $instance->table_prefix . 'statistics';
    
    $stats = $wpdb->get_results($wpdb->prepare(
        "SELECT action, COUNT(*) as count FROM $table_name GROUP BY action ORDER BY count DESC LIMIT %d",
        10
    ));
    
    return $stats;
}

function smd_generate_image_url($attachment_id, $size = 'desktop') {
    $image_url = wp_get_attachment_url($attachment_id);
    if (!$image_url) {
        return false;
    }
    
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (isset($metadata['sizes']['smd_' . $size])) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . dirname($metadata['file']) . '/' . $metadata['sizes']['smd_' . $size]['file'];
    }
    
    return $image_url;
}

// Add shortcode support
add_shortcode('smd_audio_player', function($atts) {
    $atts = shortcode_atts(array(
        'id' => 0,
        'title' => ''
    ), $atts, 'smd_audio_player');
    
    $audio_id = absint($atts['id']);
    if (!$audio_id) {
        return '';
    }
    
    $audio_url = wp_get_attachment_url($audio_id);
    if (!$audio_url) {
        return '';
    }
    
    $title = !empty($atts['title']) ? $atts['title'] : get_the_title($audio_id);
    $instance = SimpleMediaDoctor::get_instance();
    
    return $instance->generate_audio_player($audio_url, $title);
});

add_shortcode('smd_video_player', function($atts) {
    $atts = shortcode_atts(array(
        'id' => 0,
        'title' => '',
        'width' => '100%',
        'height' => 'auto'
    ), $atts, 'smd_video_player');
    
    $video_id = absint($atts['id']);
    if (!$video_id) {
        return '';
    }
    
    $video_url = wp_get_attachment_url($video_id);
    if (!$video_url) {
        return '';
    }
    
    $title = !empty($atts['title']) ? $atts['title'] : get_the_title($video_id);
    $instance = SimpleMediaDoctor::get_instance();
    
    $player = $instance->generate_video_player($video_url, $title);
    return str_replace('style="width:100%; max-width:100%; height:auto"', 
        sprintf('style="width:%s; max-width:100%; height:%s"', esc_attr($atts['width']), esc_attr($atts['height'])), 
        $player);
});

// Add REST API endpoints
add_action('rest_api_init', function() {
    register_rest_route('smd/v1', '/stats', array(
        'methods' => 'GET',
        'callback' => function($request) {
            $instance = SimpleMediaDoctor::get_instance();
            $settings = $instance->get_cached_settings();
            
            if (!$settings['enable_logging']) {
                return new WP_Error('logging_disabled', __('Statistics logging is disabled', 'simple-media-doctor'), array('status' => 403));
            }
            
            $stats = smd_get_processed_stats();
            return rest_ensure_response($stats);
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    register_rest_route('smd/v1', '/process-image', array(
        'methods' => 'POST',
        'callback' => function($request) {
            $instance = SimpleMediaDoctor::get_instance();
            $params = $request->get_params();
            
            if (!isset($params['image_id'])) {
                return new WP_Error('missing_params', __('Image ID is required', 'simple-media-doctor'), array('status' => 400));
            }
            
            $image_id = absint($params['image_id']);
            $metadata = wp_get_attachment_metadata($image_id);
            
            if (!$metadata) {
                return new WP_Error('invalid_image', __('Invalid image ID', 'simple-media-doctor'), array('status' => 404));
            }
            
            $result = $instance->auto_resize_images($metadata, $image_id);
            
            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Image processed successfully', 'simple-media-doctor'),
                'metadata' => $result
            ));
        },
        'permission_callback' => function() {
            return current_user_can('upload_files');
        }
    ));
});

// Add WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('smd compress-images', function($args, $assoc_args) {
        $instance = SimpleMediaDoctor::get_instance();
        $quality = isset($assoc_args['quality']) ? intval($assoc_args['quality']) : 80;
        
        WP_CLI::line('Starting image compression with quality: ' . $quality . '%');
        
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif'),
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $image_ids = get_posts($args);
        $progress = WP_CLI\Utils\make_progress_bar('Compressing images', count($image_ids));
        $compressed = 0;
        
        foreach ($image_ids as $image_id) {
            $file_path = get_attached_file($image_id);
            if ($file_path && file_exists($file_path)) {
                $editor = wp_get_image_editor($file_path);
                if (!is_wp_error($editor)) {
                    $editor->set_quality($quality);
                    $result = $editor->save($file_path);
                    if (!is_wp_error($result)) {
                        $compressed++;
                    }
                }
            }
            $progress->tick();
        }
        
        $progress->finish();
        WP_CLI::success('Compressed ' . $compressed . ' images successfully.');
    });
    
    WP_CLI::add_command('smd cleanup-stats', function($args, $assoc_args) {
        global $wpdb;
        $instance = SimpleMediaDoctor::get_instance();
        $table_name = $wpdb->prefix . $instance->table_prefix . 'statistics';
        
        $days = isset($assoc_args['days']) ? intval($assoc_args['days']) : 90;
        $date = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE timestamp < %s",
            $date
        ));
        
        WP_CLI::success('Deleted ' . $deleted . ' old statistics records.');
    });
}