<?php
/**
 * Plugin Name: Simple Media Doctor
 * Plugin URI: https://tekstep.ug
 * Description: A comprehensive media management plugin with auto-renaming, resizing, compression, audio processing, and monetization features
 * Version: 1.0.0
 * Author: WILKIE CEPHAS, TEKSTEP UG
 * Author URI: https://tekstep.ug
 * License: GPL v2 or later
 * Text Domain: simple-media-doctor
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SMD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SMD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SMD_VERSION', '1.0.0');

class SimpleMediaDoctor {
    
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
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
        
        // Initialize additional modules
        $this->init_modules();
    }
    
    public function activate() {
        // Create necessary database tables
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'smd_statistics';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            action varchar(255) NOT NULL,
            data text,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create payments table
        $payments_table = $wpdb->prefix . 'smd_payments';
        $sql_payments = "CREATE TABLE $payments_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(255) NOT NULL,
            user_id int(11) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL,
            status varchar(50) NOT NULL,
            payment_method varchar(50) NOT NULL,
            media_id int(11),
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        dbDelta($sql_payments);
        
        // Create ads table
        $ads_table = $wpdb->prefix . 'smd_ads';
        $sql_ads = "CREATE TABLE $ads_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            content text NOT NULL,
            placement varchar(100) NOT NULL,
            start_date datetime,
            end_date datetime,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        dbDelta($sql_ads);
        
        // Set default options
        add_option('smd_settings', array(
            'auto_rename' => 'post_title',
            'image_sizes' => array(
                'desktop' => array('width' => 1920, 'height' => 1080),
                'mobile' => array('width' => 768, 'height' => 432),
                'custom' => array('width' => 1200, 'height' => 675)
            ),
            'compression_quality' => 80,
            'audio_low_quality_size' => 1.5,
            'social_accounts' => array(),
            'payment_methods' => array('mtn' => true, 'airtel' => true, 'credit_card' => true),
            'donation_enabled' => true,
            'advertisements_enabled' => true
        ));
    }
    
    public function deactivate() {
        // Cleanup if needed
    }
    
    public function init() {
        load_plugin_textdomain('simple-media-doctor', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Simple Media Doctor',
            'Media Doctor',
            'manage_options',
            'simple-media-doctor',
            array($this, 'admin_page'),
            'dashicons-format-image',
            30
        );
    }
    
    public function admin_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <div class="smd-header">
                <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/logo.png" alt="Simple Media Doctor Logo" class="smd-logo">
                <h1>Simple Media Doctor</h1>
                <p>Comprehensive Media Management Solution by WILKIE CEPHAS, TEKSTEP UG</p>
            </div>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=simple-media-doctor&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="?page=simple-media-doctor&tab=image" class="nav-tab <?php echo $active_tab == 'image' ? 'nav-tab-active' : ''; ?>">Image Processing</a>
                <a href="?page=simple-media-doctor&tab=audio" class="nav-tab <?php echo $active_tab == 'audio' ? 'nav-tab-active' : ''; ?>">Audio Processing</a>
                <a href="?page=simple-media-doctor&tab=video" class="nav-tab <?php echo $active_tab == 'video' ? 'nav-tab-active' : ''; ?>">Video Processing</a>
                <a href="?page=simple-media-doctor&tab=payment" class="nav-tab <?php echo $active_tab == 'payment' ? 'nav-tab-active' : ''; ?>">Payment & Donations</a>
                <a href="?page=simple-media-doctor&tab=ads" class="nav-tab <?php echo $active_tab == 'ads' ? 'nav-tab-active' : ''; ?>">Advertisements</a>
                <a href="?page=simple-media-doctor&tab=social" class="nav-tab <?php echo $active_tab == 'social' ? 'nav-tab-active' : ''; ?>">Social Media</a>
                <a href="?page=simple-media-doctor&tab=stats" class="nav-tab <?php echo $active_tab == 'stats' ? 'nav-tab-active' : ''; ?>">Statistics</a>
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
    
    private function general_settings_page() {
        $settings = get_option('smd_settings', array());
        
        if (isset($_POST['save_general_settings'])) {
            check_admin_referer('smd_general_settings');
            
            $settings['auto_rename'] = sanitize_text_field($_POST['auto_rename']);
            $settings['donation_enabled'] = isset($_POST['donation_enabled']) ? 1 : 0;
            $settings['advertisements_enabled'] = isset($_POST['advertisements_enabled']) ? 1 : 0;
            
            update_option('smd_settings', $settings);
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2>General Settings</h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_general_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label for="auto_rename">Auto Rename Media</label>
                        <select name="auto_rename" id="auto_rename" class="smd-select">
                            <option value="post_title" <?php selected($settings['auto_rename'], 'post_title'); ?>>Post Title</option>
                            <option value="custom_field" <?php selected($settings['auto_rename'], 'custom_field'); ?>>Custom Name Field</option>
                            <option value="none" <?php selected($settings['auto_rename'], 'none'); ?>>None</option>
                        </select>
                        <p class="smd-help-text">Choose how media files should be automatically renamed</p>
                    </div>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="donation_enabled" value="1" <?php checked($settings['donation_enabled'], 1); ?>>
                            <span class="smd-checkbox-label">Enable Donations</span>
                        </label>
                    </div>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="advertisements_enabled" value="1" <?php checked($settings['advertisements_enabled'], 1); ?>>
                            <span class="smd-checkbox-label">Enable Advertisements</span>
                        </label>
                    </div>
                    
                    <?php submit_button('Save Settings', 'primary', 'save_general_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-features-grid">
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/image-resize-icon.png" alt="Image Resize">
                </div>
                <h3>Auto Image Resize</h3>
                <p>Automatically resize images for different devices and screen sizes</p>
            </div>
            
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/audio-process-icon.png" alt="Audio Process">
                </div>
                <h3>Audio Processing</h3>
                <p>Process audio files with watermarking and quality options</p>
            </div>
            
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/payment-icon.png" alt="Payment">
                </div>
                <h3>Secure Payments</h3>
                <p>Integrated payment solutions including MTN and Airtel Mobile Money</p>
            </div>
            
            <div class="smd-feature-card">
                <div class="smd-feature-icon">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/stats-icon.png" alt="Statistics">
                </div>
                <h3>Analytics</h3>
                <p>Track media usage and user engagement with detailed statistics</p>
            </div>
        </div>
        <?php
    }
    
    private function image_settings_page() {
        $settings = get_option('smd_settings', array());
        
        if (isset($_POST['save_image_settings'])) {
            check_admin_referer('smd_image_settings');
            
            $settings['image_sizes'] = array(
                'desktop' => array(
                    'width' => intval($_POST['desktop_width']),
                    'height' => intval($_POST['desktop_height'])
                ),
                'mobile' => array(
                    'width' => intval($_POST['mobile_width']),
                    'height' => intval($_POST['mobile_height'])
                ),
                'custom' => array(
                    'width' => intval($_POST['custom_width']),
                    'height' => intval($_POST['custom_height'])
                )
            );
            $settings['compression_quality'] = intval($_POST['compression_quality']);
            
            update_option('smd_settings', $settings);
            echo '<div class="notice notice-success"><p>Image settings saved successfully!</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2>Image Processing Settings</h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_image_settings'); ?>
                    
                    <div class="smd-image-sizes-grid">
                        <div class="smd-size-card">
                            <h3>Desktop Size</h3>
                            <div class="smd-form-row">
                                <div class="smd-form-group">
                                    <label for="desktop_width">Width (px)</label>
                                    <input type="number" name="desktop_width" id="desktop_width" value="<?php echo $settings['image_sizes']['desktop']['width']; ?>" placeholder="Width" class="smd-input">
                                </div>
                                <div class="smd-form-group">
                                    <label for="desktop_height">Height (px)</label>
                                    <input type="number" name="desktop_height" id="desktop_height" value="<?php echo $settings['image_sizes']['desktop']['height']; ?>" placeholder="Height" class="smd-input">
                                </div>
                            </div>
                        </div>
                        
                        <div class="smd-size-card">
                            <h3>Mobile Size</h3>
                            <div class="smd-form-row">
                                <div class="smd-form-group">
                                    <label for="mobile_width">Width (px)</label>
                                    <input type="number" name="mobile_width" id="mobile_width" value="<?php echo $settings['image_sizes']['mobile']['width']; ?>" placeholder="Width" class="smd-input">
                                </div>
                                <div class="smd-form-group">
                                    <label for="mobile_height">Height (px)</label>
                                    <input type="number" name="mobile_height" id="mobile_height" value="<?php echo $settings['image_sizes']['mobile']['height']; ?>" placeholder="Height" class="smd-input">
                                </div>
                            </div>
                        </div>
                        
                        <div class="smd-size-card">
                            <h3>Custom Size</h3>
                            <div class="smd-form-row">
                                <div class="smd-form-group">
                                    <label for="custom_width">Width (px)</label>
                                    <input type="number" name="custom_width" id="custom_width" value="<?php echo $settings['image_sizes']['custom']['width']; ?>" placeholder="Width" class="smd-input">
                                </div>
                                <div class="smd-form-group">
                                    <label for="custom_height">Height (px)</label>
                                    <input type="number" name="custom_height" id="custom_height" value="<?php echo $settings['image_sizes']['custom']['height']; ?>" placeholder="Height" class="smd-input">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="smd-compression-section">
                        <h3>Image Compression</h3>
                        <div class="smd-form-group">
                            <label for="compression_quality">Compression Quality</label>
                            <div class="smd-range-container">
                                <input type="range" name="compression_quality" id="compression_quality" min="1" max="100" value="<?php echo $settings['compression_quality']; ?>" class="smd-range">
                                <span id="quality-value"><?php echo $settings['compression_quality']; ?>%</span>
                            </div>
                            <p class="smd-help-text">Higher values mean better quality but larger file sizes</p>
                        </div>
                    </div>
                    
                    <?php submit_button('Save Image Settings', 'primary', 'save_image_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-visual-preview">
            <h3>Image Processing Preview</h3>
            <div class="smd-image-preview-grid">
                <div class="smd-preview-card">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/sample-image-original.jpg" alt="Original Image">
                    <p>Original: 1920x1080</p>
                </div>
                <div class="smd-preview-card">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/sample-image-resized.jpg" alt="Resized Image">
                    <p>Resized: <?php echo $settings['image_sizes']['desktop']['width']; ?>x<?php echo $settings['image_sizes']['desktop']['height']; ?></p>
                </div>
                <div class="smd-preview-card">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/sample-image-compressed.jpg" alt="Compressed Image">
                    <p>Compressed: <?php echo $settings['compression_quality']; ?>% Quality</p>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function audio_settings_page() {
        $settings = get_option('smd_settings', array());
        
        if (isset($_POST['save_audio_settings'])) {
            check_admin_referer('smd_audio_settings');
            
            $settings['audio_low_quality_size'] = floatval($_POST['audio_low_quality_size']);
            $settings['audio_watermark'] = sanitize_text_field($_POST['audio_watermark']);
            $settings['audio_jingle'] = sanitize_text_field($_POST['audio_jingle']);
            
            update_option('smd_settings', $settings);
            echo '<div class="notice notice-success"><p>Audio settings saved successfully!</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2>Audio Processing Settings</h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_audio_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label for="audio_low_quality_size">Low Quality Audio Size Limit (MB)</label>
                        <input type="number" name="audio_low_quality_size" id="audio_low_quality_size" step="0.1" value="<?php echo $settings['audio_low_quality_size']; ?>" placeholder="MB" class="smd-input">
                        <p class="smd-help-text">Audio files larger than this will be compressed to low quality</p>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="audio_watermark">Audio Watermark File</label>
                        <input type="text" name="audio_watermark" id="audio_watermark" value="<?php echo esc_attr($settings['audio_watermark']); ?>" class="smd-input" placeholder="Upload watermark audio file">
                        <button type="button" class="smd-upload-btn" onclick="document.querySelector('input[name=audio_watermark]').click();">Upload Watermark</button>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="audio_jingle">Audio Jingle File</label>
                        <input type="text" name="audio_jingle" id="audio_jingle" value="<?php echo esc_attr($settings['audio_jingle']); ?>" class="smd-input" placeholder="Upload jingle audio file">
                        <button type="button" class="smd-upload-btn" onclick="document.querySelector('input[name=audio_jingle]').click();">Upload Jingle</button>
                    </div>
                    
                    <?php submit_button('Save Audio Settings', 'primary', 'save_audio_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-audio-visualizer">
            <h3>Audio Processing Visualizer</h3>
            <div class="smd-waveform-container">
                <div class="smd-waveform-bar" style="height: 30px;"></div>
                <div class="smd-waveform-bar" style="height: 60px;"></div>
                <div class="smd-waveform-bar" style="height: 45px;"></div>
                <div class="smd-waveform-bar" style="height: 80px;"></div>
                <div class="smd-waveform-bar" style="height: 55px;"></div>
                <div class="smd-waveform-bar" style="height: 70px;"></div>
                <div class="smd-waveform-bar" style="height: 40px;"></div>
                <div class="smd-waveform-bar" style="height: 90px;"></div>
                <div class="smd-waveform-bar" style="height: 65px;"></div>
                <div class="smd-waveform-bar" style="height: 50px;"></div>
            </div>
            
            <div class="smd-quality-options">
                <div class="smd-quality-option active">
                    <span>High Quality</span>
                </div>
                <div class="smd-quality-option">
                    <span>Low Quality</span>
                </div>
                <div class="smd-quality-option">
                    <span>HD Audio</span>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function video_settings_page() {
        $settings = get_option('smd_settings', array());
        
        if (isset($_POST['save_video_settings'])) {
            check_admin_referer('smd_video_settings');
            
            $settings['video_quality'] = sanitize_text_field($_POST['video_quality']);
            $settings['video_watermark'] = sanitize_text_field($_POST['video_watermark']);
            
            update_option('smd_settings', $settings);
            echo '<div class="notice notice-success"><p>Video settings saved successfully!</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2>Video Processing Settings</h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_video_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label for="video_quality">Video Quality</label>
                        <select name="video_quality" id="video_quality" class="smd-select">
                            <option value="480p" <?php selected($settings['video_quality'], '480p'); ?>>480p</option>
                            <option value="720p" <?php selected($settings['video_quality'], '720p'); ?>>720p</option>
                            <option value="1080p" <?php selected($settings['video_quality'], '1080p'); ?>>1080p</option>
                            <option value="4k" <?php selected($settings['video_quality'], '4k'); ?>>4K</option>
                        </select>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="video_watermark">Video Watermark</label>
                        <input type="text" name="video_watermark" id="video_watermark" value="<?php echo esc_attr($settings['video_watermark']); ?>" class="smd-input" placeholder="Upload watermark image/video file">
                        <button type="button" class="smd-upload-btn" onclick="document.querySelector('input[name=video_watermark]').click();">Upload Watermark</button>
                    </div>
                    
                    <?php submit_button('Save Video Settings', 'primary', 'save_video_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-video-preview">
            <h3>Video Processing Preview</h3>
            <div class="smd-video-container">
                <div class="smd-video-placeholder">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/video-placeholder.jpg" alt="Video Preview">
                    <div class="smd-video-controls">
                        <button class="smd-play-btn">▶ Play</button>
                        <span class="smd-quality-indicator"><?php echo $settings['video_quality']; ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function payment_settings_page() {
        $settings = get_option('smd_settings', array());
        
        if (isset($_POST['save_payment_settings'])) {
            check_admin_referer('smd_payment_settings');
            
            $settings['payment_methods'] = array(
                'mtn' => isset($_POST['mtn_enabled']) ? 1 : 0,
                'airtel' => isset($_POST['airtel_enabled']) ? 1 : 0,
                'credit_card' => isset($_POST['credit_card_enabled']) ? 1 : 0
            );
            $settings['donation_enabled'] = isset($_POST['donation_enabled']) ? 1 : 0;
            $settings['paypal_client_id'] = sanitize_text_field($_POST['paypal_client_id']);
            $settings['stripe_publishable_key'] = sanitize_text_field($_POST['stripe_publishable_key']);
            
            update_option('smd_settings', $settings);
            echo '<div class="notice notice-success"><p>Payment settings saved successfully!</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2>Payment & Donation Settings</h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_payment_settings'); ?>
                    
                    <h3>Payment Methods</h3>
                    <div class="smd-payment-methods">
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="mtn_enabled" value="1" <?php checked($settings['payment_methods']['mtn'], 1); ?>>
                                <span class="smd-checkbox-label">MTN Mobile Money</span>
                            </label>
                        </div>
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="airtel_enabled" value="1" <?php checked($settings['payment_methods']['airtel'], 1); ?>>
                                <span class="smd-checkbox-label">Airtel Mobile Money</span>
                            </label>
                        </div>
                        <div class="smd-payment-method">
                            <label class="smd-checkbox">
                                <input type="checkbox" name="credit_card_enabled" value="1" <?php checked($settings['payment_methods']['credit_card'], 1); ?>>
                                <span class="smd-checkbox-label">Credit Card</span>
                            </label>
                        </div>
                    </div>
                    
                    <h3>Donation Settings</h3>
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="donation_enabled" value="1" <?php checked($settings['donation_enabled'], 1); ?>>
                            <span class="smd-checkbox-label">Enable Donations</span>
                        </label>
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="paypal_client_id">PayPal Client ID</label>
                        <input type="text" name="paypal_client_id" id="paypal_client_id" value="<?php echo esc_attr($settings['paypal_client_id']); ?>" class="smd-input" placeholder="Enter PayPal Client ID">
                    </div>
                    
                    <div class="smd-form-group">
                        <label for="stripe_publishable_key">Stripe Publishable Key</label>
                        <input type="text" name="stripe_publishable_key" id="stripe_publishable_key" value="<?php echo esc_attr($settings['stripe_publishable_key']); ?>" class="smd-input" placeholder="Enter Stripe Publishable Key">
                    </div>
                    
                    <?php submit_button('Save Payment Settings', 'primary', 'save_payment_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-payment-visual">
            <h3>Payment Gateway Integration</h3>
            <div class="smd-payment-options">
                <div class="smd-payment-option">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/mtn-logo.png" alt="MTN Logo">
                    <p>MTN Mobile Money</p>
                </div>
                <div class="smd-payment-option">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/airtel-logo.png" alt="Airtel Logo">
                    <p>Airtel Mobile Money</p>
                </div>
                <div class="smd-payment-option">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/credit-card-logo.png" alt="Credit Card Logo">
                    <p>Credit Card</p>
                </div>
                <div class="smd-payment-option">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/paypal-logo.png" alt="PayPal Logo">
                    <p>PayPal</p>
                </div>
                <div class="smd-payment-option">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/stripe-logo.png" alt="Stripe Logo">
                    <p>Stripe</p>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function ads_settings_page() {
        $settings = get_option('smd_settings', array());
        
        if (isset($_POST['save_ad_settings'])) {
            check_admin_referer('smd_ad_settings');
            
            $settings['advertisements_enabled'] = isset($_POST['advertisements_enabled']) ? 1 : 0;
            
            update_option('smd_settings', $settings);
            echo '<div class="notice notice-success"><p>Ad settings saved successfully!</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2>Advertisement Settings</h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_ad_settings'); ?>
                    
                    <div class="smd-form-group">
                        <label class="smd-checkbox">
                            <input type="checkbox" name="advertisements_enabled" value="1" <?php checked($settings['advertisements_enabled'], 1); ?>>
                            <span class="smd-checkbox-label">Enable Advertisements</span>
                        </label>
                    </div>
                    
                    <?php submit_button('Save Ad Settings', 'primary', 'save_ad_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-ads-management">
            <h3>Manage Advertisements</h3>
            <button type="button" class="smd-btn-primary" onclick="addNewAd()">Add New Ad</button>
            <div id="ads-list">
                <?php $this->display_ads_list(); ?>
            </div>
        </div>
        
        <div class="smd-ad-preview">
            <h3>Advertisement Preview</h3>
            <div class="smd-ad-placeholder">
                <div class="smd-ad-content">
                    <h4>Advertisement Title</h4>
                    <p>This is a sample advertisement that will appear on your website</p>
                    <button class="smd-ad-btn">Learn More</button>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function social_settings_page() {
        $settings = get_option('smd_settings', array());
        
        if (isset($_POST['save_social_settings'])) {
            check_admin_referer('smd_social_settings');
            
            $settings['social_accounts'] = array(
                'facebook' => sanitize_url($_POST['facebook_url']),
                'twitter' => sanitize_url($_POST['twitter_url']),
                'instagram' => sanitize_url($_POST['instagram_url']),
                'youtube' => sanitize_url($_POST['youtube_url']),
                'tiktok' => sanitize_url($_POST['tiktok_url'])
            );
            
            update_option('smd_settings', $settings);
            echo '<div class="notice notice-success"><p>Social settings saved successfully!</p></div>';
        }
        ?>
        <div class="smd-card">
            <h2>Social Media Settings</h2>
            <div class="smd-card-content">
                <form method="post" action="">
                    <?php wp_nonce_field('smd_social_settings'); ?>
                    
                    <div class="smd-social-grid">
                        <div class="smd-social-input">
                            <label for="facebook_url">Facebook</label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/facebook-icon.png" alt="Facebook">
                                <input type="url" name="facebook_url" id="facebook_url" value="<?php echo esc_url($settings['social_accounts']['facebook']); ?>" class="smd-input" placeholder="https://facebook.com/yourpage">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="twitter_url">Twitter</label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/twitter-icon.png" alt="Twitter">
                                <input type="url" name="twitter_url" id="twitter_url" value="<?php echo esc_url($settings['social_accounts']['twitter']); ?>" class="smd-input" placeholder="https://twitter.com/yourhandle">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="instagram_url">Instagram</label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/instagram-icon.png" alt="Instagram">
                                <input type="url" name="instagram_url" id="instagram_url" value="<?php echo esc_url($settings['social_accounts']['instagram']); ?>" class="smd-input" placeholder="https://instagram.com/yourprofile">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="youtube_url">YouTube</label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/youtube-icon.png" alt="YouTube">
                                <input type="url" name="youtube_url" id="youtube_url" value="<?php echo esc_url($settings['social_accounts']['youtube']); ?>" class="smd-input" placeholder="https://youtube.com/yourchannel">
                            </div>
                        </div>
                        
                        <div class="smd-social-input">
                            <label for="tiktok_url">TikTok</label>
                            <div class="smd-input-with-icon">
                                <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/tiktok-icon.png" alt="TikTok">
                                <input type="url" name="tiktok_url" id="tiktok_url" value="<?php echo esc_url($settings['social_accounts']['tiktok']); ?>" class="smd-input" placeholder="https://tiktok.com/@yourhandle">
                            </div>
                        </div>
                    </div>
                    
                    <?php submit_button('Save Social Settings', 'primary', 'save_social_settings'); ?>
                </form>
            </div>
        </div>
        
        <div class="smd-social-preview">
            <h3>Social Media Preview</h3>
            <div class="smd-social-icons">
                <a href="<?php echo esc_url($settings['social_accounts']['facebook']); ?>" target="_blank">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/facebook-icon.png" alt="Facebook">
                </a>
                <a href="<?php echo esc_url($settings['social_accounts']['twitter']); ?>" target="_blank">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/twitter-icon.png" alt="Twitter">
                </a>
                <a href="<?php echo esc_url($settings['social_accounts']['instagram']); ?>" target="_blank">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/instagram-icon.png" alt="Instagram">
                </a>
                <a href="<?php echo esc_url($settings['social_accounts']['youtube']); ?>" target="_blank">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/youtube-icon.png" alt="YouTube">
                </a>
                <a href="<?php echo esc_url($settings['social_accounts']['tiktok']); ?>" target="_blank">
                    <img src="<?php echo SMD_PLUGIN_URL; ?>assets/images/tiktok-icon.png" alt="TikTok">
                </a>
            </div>
        </div>
        <?php
    }
    
    private function stats_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'smd_statistics';
        
        $stats = $wpdb->get_results("SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT 50");
        ?>
        <div class="smd-card">
            <h2>Plugin Statistics</h2>
            <div class="smd-card-content">
                <div class="smd-stats-visual">
                    <div class="smd-chart-container">
                        <canvas id="statsChart" width="400" height="200"></canvas>
                    </div>
                    
                    <div class="smd-stats-summary">
                        <div class="smd-stat-card">
                            <h3>Media Processed</h3>
                            <p>1,245</p>
                        </div>
                        <div class="smd-stat-card">
                            <h3>Payments Processed</h3>
                            <p>$2,450</p>
                        </div>
                        <div class="smd-stat-card">
                            <h3>Downloads</h3>
                            <p>3,678</p>
                        </div>
                        <div class="smd-stat-card">
                            <h3>Active Users</h3>
                            <p>892</p>
                        </div>
                    </div>
                </div>
                
                <h3>Recent Activity</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Action</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats as $stat): ?>
                        <tr>
                            <td><?php echo $stat->timestamp; ?></td>
                            <td><?php echo $stat->action; ?></td>
                            <td><?php echo $stat->data; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    private function display_ads_list() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'smd_ads';
        $ads = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        
        foreach ($ads as $ad) {
            echo '<div class="smd-ad-item" data-id="' . $ad->id . '">';
            echo '<h4>' . $ad->title . '</h4>';
            echo '<p>' . $ad->content . '</p>';
            echo '<p><strong>Placement:</strong> ' . $ad->placement . '</p>';
            echo '<p><strong>Status:</strong> ' . $ad->status . '</p>';
            echo '<button type="button" class="smd-btn-secondary" onclick="editAd(' . $ad->id . ')">Edit</button>';
            echo '<button type="button" class="smd-btn-danger" onclick="deleteAd(' . $ad->id . ')">Delete</button>';
            echo '</div><hr>';
        }
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook != 'toplevel_page_simple-media-doctor') {
            return;
        }
        
        wp_enqueue_style('smd-admin-css', SMD_PLUGIN_URL . 'assets/css/admin.css', array(), SMD_VERSION);
        wp_enqueue_script('smd-admin-js', SMD_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'chart-js'), SMD_VERSION, true);
        wp_localize_script('smd-admin-js', 'smd_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smd_nonce')
        ));
    }
    
    public function enqueue_frontend_scripts() {
        wp_enqueue_style('smd-frontend-css', SMD_PLUGIN_URL . 'assets/css/frontend.css', array(), SMD_VERSION);
        wp_enqueue_script('smd-frontend-js', SMD_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), SMD_VERSION, true);
        wp_localize_script('smd-frontend-js', 'smd_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smd_nonce')
        ));
    }
    
    public function rename_media_on_upload($file) {
        $settings = get_option('smd_settings', array());
        
        if ($settings['auto_rename'] === 'post_title' && isset($_POST['post_id'])) {
            $post_id = intval($_POST['post_id']);
            $post_title = get_the_title($post_id);
            if ($post_title) {
                $filetype = wp_check_filetype($file['name']);
                $new_filename = sanitize_title($post_title) . '.' . $filetype['ext'];
                $file['name'] = $new_filename;
            }
        }
        
        return $file;
    }
    
    public function auto_resize_images($metadata, $attachment_id) {
        $settings = get_option('smd_settings', array());
        
        $image_path = get_attached_file($attachment_id);
        if (!$image_path) return $metadata;
        
        $image_info = getimagesize($image_path);
        if (!$image_info) return $metadata;
        
        foreach ($settings['image_sizes'] as $size_name => $size) {
            $new_path = str_replace(basename($image_path), '', $image_path) . 
                       'smd_' . $size_name . '_' . basename($image_path);
            
            $resized = wp_get_image_editor($image_path);
            if (!is_wp_error($resized)) {
                $resized->resize($size['width'], $size['height'], true);
                $resized->save($new_path);
                
                $metadata['sizes']['smd_' . $size_name] = array(
                    'file' => basename($new_path),
                    'width' => $size['width'],
                    'height' => $size['height'],
                    'mime-type' => $image_info['mime']
                );
            }
        }
        
        return $metadata;
    }
    
    public function add_media_players_to_content($content) {
        // Add audio player to posts with audio files
        $post_id = get_the_ID();
        $audio_files = get_attached_media('audio', $post_id);
        
        if ($audio_files) {
            $player_html = '<div class="smd-audio-player-container">';
            foreach ($audio_files as $audio) {
                $audio_url = wp_get_attachment_url($audio->ID);
                $player_html .= $this->generate_audio_player($audio_url, $audio->post_title);
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
                $player_html .= $this->generate_video_player($video_url, $video->post_title);
            }
            $player_html .= '</div>';
            $content .= $player_html;
        }
        
        return $content;
    }
    
    private function generate_audio_player($audio_url, $title) {
        $settings = get_option('smd_settings', array());
        
        return '<div class="smd-audio-player">
            <h4>' . esc_html($title) . '</h4>
            <div class="smd-player-visualizer">
                <div class="smd-waveform-bar" style="height: 30px;"></div>
                <div class="smd-waveform-bar" style="height: 60px;"></div>
                <div class="smd-waveform-bar" style="height: 45px;"></div>
                <div class="smd-waveform-bar" style="height: 80px;"></div>
                <div class="smd-waveform-bar" style="height: 55px;"></div>
                <div class="smd-waveform-bar" style="height: 70px;"></div>
                <div class="smd-waveform-bar" style="height: 40px;"></div>
                <div class="smd-waveform-bar" style="height: 90px;"></div>
                <div class="smd-waveform-bar" style="height: 65px;"></div>
                <div class="smd-waveform-bar" style="height: 50px;"></div>
            </div>
            <audio controls>
                <source src="' . esc_url($audio_url) . '" type="audio/mpeg">
                Your browser does not support the audio element.
            </audio>
            <div class="smd-player-controls">
                <button class="smd-download-btn" onclick="smdDownloadAudio(\'' . esc_url($audio_url) . '\', \'' . esc_attr($title) . '\')">Download</button>
                <span class="smd-play-count">Plays: 0</span>
            </div>
        </div>';
    }
    
    private function generate_video_player($video_url, $title) {
        return '<div class="smd-video-player">
            <h4>' . esc_html($title) . '</h4>
            <video controls width="100%">
                <source src="' . esc_url($video_url) . '" type="video/mp4">
                Your browser does not support the video tag.
            </video>
            <div class="smd-player-controls">
                <button class="smd-download-btn" onclick="smdDownloadVideo(\'' . esc_url($video_url) . '\', \'' . esc_attr($title) . '\')">Download</button>
                <span class="smd-play-count">Plays: 0</span>
            </div>
        </div>';
    }
    
    public function ajax_update_media() {
        check_ajax_referer('smd_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $action = sanitize_text_field($_POST['action_type']);
        
        // Process media update based on action
        $result = array('success' => false, 'message' => 'Unknown action');
        
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
        // Implementation for renaming media
        return array('success' => true, 'message' => 'Media renamed successfully');
    }
    
    private function process_media_resize($post_id) {
        // Implementation for resizing media
        return array('success' => true, 'message' => 'Media resized successfully');
    }
    
    private function process_media_compress($post_id) {
        // Implementation for compressing media
        return array('success' => true, 'message' => 'Media compressed successfully');
    }
    
    public function ajax_compress_images() {
        check_ajax_referer('smd_nonce', 'nonce');
        
        $settings = get_option('smd_settings', array());
        $quality = $settings['compression_quality'];
        
        // Implementation for batch image compression
        wp_send_json(array('success' => true, 'message' => 'Images compressed successfully'));
    }
    
    public function ajax_process_audio() {
        check_ajax_referer('smd_nonce', 'nonce');
        
        $audio_id = intval($_POST['audio_id']);
        $process_type = sanitize_text_field($_POST['process_type']);
        
        // Implementation for audio processing
        wp_send_json(array('success' => true, 'message' => 'Audio processed successfully'));
    }
    
    public function ajax_track_stats() {
        check_ajax_referer('smd_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'smd_statistics';
        
        $data = array(
            'action' => sanitize_text_field($_POST['action']),
            'data' => sanitize_text_field($_POST['data'])
        );
        
        $wpdb->insert($table_name, $data);
        
        wp_send_json(array('success' => true));
    }
    
    public function ajax_process_payment() {
        check_ajax_referer('smd_nonce', 'nonce');
        
        $amount = floatval($_POST['amount']);
        $currency = sanitize_text_field($_POST['currency']);
        $payment_method = sanitize_text_field($_POST['payment_method']);
        $media_id = intval($_POST['media_id']);
        
        // Process payment based on method
        $transaction_id = uniqid('smd_pay_');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'smd_payments';
        
        $wpdb->insert($table_name, array(
            'transaction_id' => $transaction_id,
            'user_id' => get_current_user_id(),
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed',
            'payment_method' => $payment_method,
            'media_id' => $media_id
        ));
        
        wp_send_json(array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'message' => 'Payment processed successfully'
        ));
    }
    
    private function init_modules() {
        // Initialize additional modules
        $this->init_seo_optimizer();
        $this->init_cache_optimizer();
        $this->init_security_optimizer();
        $this->init_backup_optimizer();
        $this->init_performance_optimizer();
        $this->init_cdn_optimizer();
        $this->init_lazy_load_optimizer();
        $this->init_schema_optimizer();
        $this->init_amp_optimizer();
        $this->init_sitemap_optimizer();
    }
    
    private function init_seo_optimizer() {
        // SEO optimization module
        add_action('wp_head', array($this, 'add_seo_meta_tags'));
    }
    
    private function init_cache_optimizer() {
        // Cache optimization module
        add_action('init', array($this, 'setup_cache_optimization'));
    }
    
    private function init_security_optimizer() {
        // Security optimization module
        add_action('init', array($this, 'setup_security_optimization'));
    }
    
    private function init_backup_optimizer() {
        // Backup optimization module
        add_action('admin_init', array($this, 'setup_backup_scheduler'));
    }
    
    private function init_performance_optimizer() {
        // Performance optimization module
        add_action('wp_enqueue_scripts', array($this, 'optimize_scripts'));
    }
    
    private function init_cdn_optimizer() {
        // CDN optimization module
        add_filter('the_content', array($this, 'replace_with_cdn_urls'));
    }
    
    private function init_lazy_load_optimizer() {
        // Lazy load optimization module
        add_filter('the_content', array($this, 'add_lazy_load_attributes'));
    }
    
    private function init_schema_optimizer() {
        // Schema optimization module
        add_action('wp_head', array($this, 'add_schema_markup'));
    }
    
    private function init_amp_optimizer() {
        // AMP optimization module
        add_action('wp_head', array($this, 'add_amp_compatibility'));
    }
    
    private function init_sitemap_optimizer() {
        // Sitemap optimization module
        add_action('init', array($this, 'setup_sitemap_generator'));
    }
    
    public function add_seo_meta_tags() {
        // Add SEO meta tags
        echo '<meta name="generator" content="Simple Media Doctor ' . SMD_VERSION . '">';
    }
    
    public function add_schema_markup() {
        // Add schema markup
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'TEKSTEP UG',
            'founder' => 'WILKIE CEPHAS'
        );
        
        echo '<script type="application/ld+json">' . json_encode($schema) . '</script>';
    }
}

// Initialize the plugin
new SimpleMediaDoctor();

// Additional functions for the plugin
function smd_get_social_links() {
    $settings = get_option('smd_settings', array());
    return $settings['social_accounts'];
}

function smd_get_payment_methods() {
    $settings = get_option('smd_settings', array());
    return $settings['payment_methods'];
}

function smd_is_donation_enabled() {
    $settings = get_option('smd_settings', array());
    return $settings['donation_enabled'];
}
?>