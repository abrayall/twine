<?php
/**
 * Plugin Name: Twine
 * Plugin URI: https://github.com/yourusername/twine
 * Description: Create a link collection to display as buttons on your site. Perfect for social media bio pages.
 * Version: 0.1.0
 * Author: Brayall, LLC
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: twine
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read version from version.properties file
 */
function twine_get_version() {
    $version_file = plugin_dir_path(__FILE__) . 'version.properties';

    if (!file_exists($version_file)) {
        return '0.1.0'; // Fallback version
    }

    $properties = parse_ini_file($version_file);

    if ($properties === false || !isset($properties['major']) || !isset($properties['minor']) || !isset($properties['maintenance'])) {
        return '0.1.0'; // Fallback version
    }

    return $properties['major'] . '.' . $properties['minor'] . '.' . $properties['maintenance'];
}

// Define plugin constants
define('TWINE_VERSION', twine_get_version());
define('TWINE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TWINE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TWINE_DATA_DIR', WP_CONTENT_DIR . '/twine');
define('TWINE_LINKS_FILE', TWINE_DATA_DIR . '/settings.json');
define('TWINE_CUSTOM_THEMES_DIR', WP_CONTENT_DIR . '/uploads/twine/themes');
define('TWINE_CUSTOM_THEMES_URL', content_url('/uploads/twine/themes'));
define('TWINE_TEMP_THEMES_DIR', WP_CONTENT_DIR . '/uploads/twine/temp');

/**
 * Main Twine class
 */
class Twine {

    /**
     * Initialize the plugin
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'), 999);
        add_shortcode('twine', array($this, 'render_shortcode'));
        add_action('admin_post_twine_save_links', array($this, 'save_links'));
        add_action('admin_post_twine_save_custom_theme', array($this, 'save_custom_theme'));
        add_action('wp_ajax_twine_delete_theme', array($this, 'ajax_delete_theme'));
        add_action('wp_ajax_twine_set_active_theme', array($this, 'ajax_set_active_theme'));
        add_action('wp_ajax_twine_save_temp_theme', array($this, 'ajax_save_temp_theme'));
        add_action('init', array($this, 'add_rewrite_rules'));
        add_action('template_redirect', array($this, 'handle_twine_page'));
        add_action('template_redirect', array($this, 'handle_theme_preview'));
        add_action('admin_init', array($this, 'handle_theme_download'));

        // Ensure data directory exists
        $this->ensure_data_directory();
    }

    /**
     * Ensure the data directory exists
     */
    private function ensure_data_directory() {
        if (!file_exists(TWINE_DATA_DIR)) {
            wp_mkdir_p(TWINE_DATA_DIR);
        }
    }

    /**
     * Get MonsterInsights GA4 measurement ID if available
     */
    private function get_monsterinsights_ga_id() {
        if (function_exists('monsterinsights_get_ua')) {
            $ga_id = monsterinsights_get_ua();
            if (!empty($ga_id)) {
                return $ga_id;
            }
        }

        if (class_exists('MonsterInsights') && function_exists('monsterinsights')) {
            $mi = monsterinsights();
            if (method_exists($mi, 'get_tracking_id')) {
                $ga_id = $mi->get_tracking_id();
                if (!empty($ga_id)) {
                    return $ga_id;
                }
            }
        }

        return null;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Twine Links',
            'Twine',
            'manage_options',
            'twine',
            array($this, 'render_admin_page'),
            'dashicons-admin-links',
            30
        );

        // Rename the auto-generated submenu from "Twine" to "Settings"
        add_submenu_page(
            'twine',
            'Twine Settings',
            'Settings',
            'manage_options',
            'twine',
            array($this, 'render_admin_page')
        );

        add_submenu_page(
            'twine',
            'Theme Editor',
            'Themes',
            'manage_options',
            'twine-theme-editor',
            array($this, 'render_theme_editor_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'toplevel_page_twine') {
            wp_enqueue_media();
            wp_enqueue_style('twine-admin', TWINE_PLUGIN_URL . 'assets/admin.css', array(), TWINE_VERSION);
            wp_enqueue_script('jquery-ui-touch-punch', TWINE_PLUGIN_URL . 'assets/jquery.ui.touch-punch.min.js', array('jquery', 'jquery-ui-sortable'), TWINE_VERSION, true);
            wp_enqueue_script('twine-admin', TWINE_PLUGIN_URL . 'assets/admin.js', array('jquery', 'jquery-ui-sortable', 'jquery-ui-touch-punch'), TWINE_VERSION, true);
        }

        if ($hook === 'twine_page_twine-theme-editor') {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_enqueue_style('twine-admin', TWINE_PLUGIN_URL . 'assets/admin.css', array(), TWINE_VERSION);
            wp_enqueue_script('jquery-ui-touch-punch', TWINE_PLUGIN_URL . 'assets/jquery.ui.touch-punch.min.js', array('jquery', 'jquery-ui-sortable'), TWINE_VERSION, true);
            wp_enqueue_script('twine-admin', TWINE_PLUGIN_URL . 'assets/admin.js', array('jquery', 'jquery-ui-sortable', 'jquery-ui-touch-punch'), TWINE_VERSION, true);
            wp_localize_script('twine-admin', 'twineAdmin', array(
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
            wp_enqueue_script('twine-theme-editor', TWINE_PLUGIN_URL . 'assets/theme-editor.js', array('jquery', 'wp-color-picker'), TWINE_VERSION, true);
        }
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_style('twine-frontend', TWINE_PLUGIN_URL . 'assets/twine.css', array(), TWINE_VERSION);

        // Load theme CSS if selected
        $theme = $this->get_theme();
        if (!empty($theme)) {
            // Check custom themes first, then built-in themes
            $custom_theme_file = TWINE_CUSTOM_THEMES_DIR . '/' . $theme . '.css';
            $plugin_theme_file = TWINE_PLUGIN_DIR . 'themes/' . $theme . '.css';

            if (file_exists($custom_theme_file)) {
                wp_enqueue_style('twine-theme', TWINE_CUSTOM_THEMES_URL . '/' . $theme . '.css', array('twine-frontend'), TWINE_VERSION);
            } elseif (file_exists($plugin_theme_file)) {
                wp_enqueue_style('twine-theme', TWINE_PLUGIN_URL . 'themes/' . $theme . '.css', array('twine-frontend'), TWINE_VERSION);
            }
        }
    }

    /**
     * Get saved data from JSON file
     */
    public function get_data() {
        $defaults = array(
            'icon' => '',
            'name' => '',
            'description' => '',
            'links' => array(),
            'social' => array(),
            'theme' => '',
            'slug' => 'twine',
            'page_title' => '',
            'ga_id' => ''
        );

        if (!file_exists(TWINE_LINKS_FILE)) {
            return $defaults;
        }

        $json = file_get_contents(TWINE_LINKS_FILE);
        if ($json === false) {
            return $defaults;
        }

        $data = json_decode($json, true);

        // Handle old format (just array of links)
        if (is_array($data) && isset($data[0]) && isset($data[0]['text'])) {
            return array_merge($defaults, array('links' => $data));
        }

        // New format with icon, name, description and links
        return is_array($data) ? array_merge($defaults, $data) : $defaults;
    }

    /**
     * Get saved links (backward compatibility)
     */
    public function get_links() {
        $data = $this->get_data();
        return isset($data['links']) ? $data['links'] : array();
    }

    /**
     * Get saved icon
     */
    public function get_icon() {
        $data = $this->get_data();
        return isset($data['icon']) ? $data['icon'] : '';
    }

    /**
     * Get saved name
     */
    public function get_name() {
        $data = $this->get_data();
        return isset($data['name']) ? $data['name'] : '';
    }

    /**
     * Get saved description
     */
    public function get_description() {
        $data = $this->get_data();
        return isset($data['description']) ? $data['description'] : '';
    }

    /**
     * Get saved social media links
     */
    public function get_social() {
        $data = $this->get_data();
        $social = isset($data['social']) ? $data['social'] : array();

        // Check if old format (associative array with platform keys like 'facebook' => 'url')
        if (!empty($social) && !isset($social[0])) {
            $converted = array();
            foreach ($social as $platform => $url) {
                if (!empty($url)) {
                    $converted[] = array(
                        'name' => ucfirst($platform),
                        'icon' => $platform,
                        'url' => $url
                    );
                }
            }
            return $converted;
        }

        return $social;
    }

    /**
     * Get saved header links
     */
    public function get_header_links() {
        $data = $this->get_data();
        return isset($data['header']) ? $data['header'] : array();
    }

    /**
     * Get saved footer links
     */
    public function get_footer_links() {
        $data = $this->get_data();
        return isset($data['footer']) ? $data['footer'] : array();
    }

    /**
     * Get saved theme
     */
    public function get_theme() {
        $data = $this->get_data();
        return isset($data['theme']) ? $data['theme'] : '';
    }

    /**
     * Get saved slug
     */
    public function get_slug() {
        $data = $this->get_data();
        return isset($data['slug']) ? $data['slug'] : 'twine';
    }

    public function get_public_url() {
        $slug = $this->get_slug();
        $permalink_structure = get_option('permalink_structure');

        if (empty($permalink_structure)) {
            return home_url('?twine_page=1');
        }

        return home_url('/' . $slug);
    }

    /**
     * Get saved page title
     */
    public function get_page_title() {
        $data = $this->get_data();
        return isset($data['page_title']) ? $data['page_title'] : '';
    }

    /**
     * Get saved GA ID
     */
    public function get_ga_id() {
        $data = $this->get_data();
        return isset($data['ga_id']) ? $data['ga_id'] : '';
    }

    /**
     * Get available themes from both plugin and custom themes directories
     */
    public function get_available_themes() {
        $themes = array();

        // Scan plugin themes directory (built-in themes)
        $plugin_themes_dir = TWINE_PLUGIN_DIR . 'themes';
        if (is_dir($plugin_themes_dir)) {
            $files = scandir($plugin_themes_dir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'css') {
                    $theme_path = $plugin_themes_dir . '/' . $file;
                    $theme_slug = pathinfo($file, PATHINFO_FILENAME);

                    // Read theme metadata from file
                    $file_data = file_get_contents($theme_path);
                    preg_match('/Theme Name:\s*(.+)/', $file_data, $name_matches);
                    preg_match('/Description:\s*(.+)/', $file_data, $desc_matches);

                    $themes[$theme_slug] = array(
                        'name' => !empty($name_matches[1]) ? trim($name_matches[1]) : ucwords(str_replace('-', ' ', $theme_slug)),
                        'description' => !empty($desc_matches[1]) ? trim($desc_matches[1]) : '',
                        'file' => $file,
                        'custom' => false
                    );
                }
            }
        }

        // Scan custom themes directory (user-uploaded themes)
        if (is_dir(TWINE_CUSTOM_THEMES_DIR)) {
            $files = scandir(TWINE_CUSTOM_THEMES_DIR);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'css') {
                    $theme_path = TWINE_CUSTOM_THEMES_DIR . '/' . $file;
                    $theme_slug = pathinfo($file, PATHINFO_FILENAME);

                    // Read theme metadata from file
                    $file_data = file_get_contents($theme_path);
                    preg_match('/Theme Name:\s*(.+)/', $file_data, $name_matches);
                    preg_match('/Description:\s*(.+)/', $file_data, $desc_matches);

                    $themes[$theme_slug] = array(
                        'name' => !empty($name_matches[1]) ? trim($name_matches[1]) : ucwords(str_replace('-', ' ', $theme_slug)),
                        'description' => !empty($desc_matches[1]) ? trim($desc_matches[1]) : '',
                        'file' => $file,
                        'custom' => true
                    );
                }
            }
        }

        // Sort themes by name
        uasort($themes, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $themes;
    }

    /**
     * Save links and icon to JSON file
     */
    public function save_links() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }

        // Verify nonce
        if (!isset($_POST['twine_nonce']) || !wp_verify_nonce($_POST['twine_nonce'], 'twine_save_links')) {
            wp_die('Security check failed');
        }

        // Handle theme file upload (do this first, before saving other settings)
        // Theme upload form doesn't contain other settings, so we handle it separately
        if (isset($_FILES['twine_theme_upload']) && $_FILES['twine_theme_upload']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['twine_theme_upload'];

            // Validate file type
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file_ext !== 'css') {
                wp_die('Invalid file type. Only CSS files are allowed.');
            }

            // Validate file size (max 1MB)
            if ($file['size'] > 1048576) {
                wp_die('File is too large. Maximum size is 1MB.');
            }

            // Read file content
            $file_content = file_get_contents($file['tmp_name']);
            if ($file_content === false) {
                wp_die('Failed to read uploaded file.');
            }

            // Extract theme name from file content
            preg_match('/Theme Name:\s*(.+)/', $file_content, $name_matches);
            if (empty($name_matches[1])) {
                wp_die('Invalid theme file. Theme must have a "Theme Name" in the header comment.');
            }

            $theme_name = trim($name_matches[1]);
            $theme_slug = sanitize_title($theme_name);

            // Ensure custom themes directory exists
            if (!file_exists(TWINE_CUSTOM_THEMES_DIR)) {
                wp_mkdir_p(TWINE_CUSTOM_THEMES_DIR);
            }

            // Save theme file to custom themes directory
            $theme_path = TWINE_CUSTOM_THEMES_DIR . '/' . $theme_slug . '.css';
            $save_result = file_put_contents($theme_path, $file_content);

            if ($save_result === false) {
                wp_die('Failed to save theme file. Please check file permissions.');
            }

            // Determine redirect page
            $redirect_page = isset($_POST['redirect_to']) ? sanitize_text_field($_POST['redirect_to']) : 'twine';

            // Redirect with theme uploaded message
            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&saved=true&theme_uploaded=' . urlencode($theme_slug)));
            exit;
        }

        // Normal settings save (not a theme upload)
        $links = array();

        if (isset($_POST['link_text']) && is_array($_POST['link_text'])) {
            foreach ($_POST['link_text'] as $index => $text) {
                if (!empty($text) && !empty($_POST['link_url'][$index])) {
                    $link_data = array(
                        'text' => sanitize_text_field($text),
                        'url' => esc_url_raw($_POST['link_url'][$index])
                    );
                    if (!empty($_POST['link_image'][$index])) {
                        $link_data['image'] = esc_url_raw($_POST['link_image'][$index]);
                    }
                    $links[] = $link_data;
                }
            }
        }

        // Get icon, name, and description
        $icon = isset($_POST['twine_icon']) ? esc_url_raw($_POST['twine_icon']) : '';
        $name = isset($_POST['twine_name']) ? sanitize_text_field($_POST['twine_name']) : '';
        $description = isset($_POST['twine_description']) ? sanitize_textarea_field($_POST['twine_description']) : '';

        // Get social media links
        $social = array();
        if (isset($_POST['social_name']) && is_array($_POST['social_name'])) {
            $names = $_POST['social_name'];
            $icons = isset($_POST['social_icon']) ? $_POST['social_icon'] : array();
            $urls = isset($_POST['social_url']) ? $_POST['social_url'] : array();
            $custom_icons = isset($_POST['social_custom_icon']) ? $_POST['social_custom_icon'] : array();
            $count = count($names);
            for ($i = 0; $i < $count; $i++) {
                $url = isset($urls[$i]) ? esc_url_raw($urls[$i]) : '';
                if (!empty($url)) {
                    $item = array(
                        'name' => sanitize_text_field($names[$i]),
                        'icon' => isset($icons[$i]) ? sanitize_text_field($icons[$i]) : '',
                        'url' => $url
                    );
                    // Add custom icon URL if icon is set to 'custom'
                    if ($item['icon'] === 'custom' && !empty($custom_icons[$i])) {
                        $item['custom_icon'] = esc_url_raw($custom_icons[$i]);
                    }
                    $social[] = $item;
                }
            }
        }

        // Get header links
        $header = array();
        if (isset($_POST['header_icon']) && is_array($_POST['header_icon'])) {
            $h_icons = $_POST['header_icon'];
            $h_custom_icons = isset($_POST['header_custom_icon']) ? $_POST['header_custom_icon'] : array();
            $h_urls = isset($_POST['header_url']) ? $_POST['header_url'] : array();
            $h_aligns = isset($_POST['header_align']) ? $_POST['header_align'] : array();
            $h_count = count($h_icons);
            for ($i = 0; $i < $h_count; $i++) {
                $url = isset($h_urls[$i]) ? esc_url_raw($h_urls[$i]) : '';
                if (!empty($url)) {
                    $item = array(
                        'icon' => isset($h_icons[$i]) ? sanitize_text_field($h_icons[$i]) : '',
                        'url' => $url,
                        'align' => isset($h_aligns[$i]) ? sanitize_text_field($h_aligns[$i]) : 'center'
                    );
                    if ($item['icon'] === 'custom' && !empty($h_custom_icons[$i])) {
                        $item['custom_icon'] = esc_url_raw($h_custom_icons[$i]);
                    }
                    $header[] = $item;
                }
            }
        }

        // Get footer links
        $footer = array();
        if (isset($_POST['footer_text']) && is_array($_POST['footer_text'])) {
            $f_texts = $_POST['footer_text'];
            $f_urls = isset($_POST['footer_url']) ? $_POST['footer_url'] : array();
            $f_count = count($f_texts);
            for ($i = 0; $i < $f_count; $i++) {
                $text = isset($f_texts[$i]) ? sanitize_text_field($f_texts[$i]) : '';
                $url = isset($f_urls[$i]) ? esc_url_raw($f_urls[$i]) : '';
                if (!empty($text) && !empty($url)) {
                    $footer[] = array(
                        'text' => $text,
                        'url' => $url
                    );
                }
            }
        }

        // Get theme
        $theme = isset($_POST['twine_theme']) ? sanitize_text_field($_POST['twine_theme']) : '';

        // Get and validate slug
        $old_slug = $this->get_slug();
        $slug = isset($_POST['twine_slug']) ? sanitize_text_field($_POST['twine_slug']) : 'twine';

        // Validate slug: only lowercase letters, numbers, and hyphens
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);

        // Ensure slug is not empty
        if (empty($slug)) {
            $slug = 'twine';
        }

        // Check if slug has changed - if so, we need to flush rewrite rules
        $slug_changed = ($old_slug !== $slug);

        // Get page title and GA ID
        $page_title = isset($_POST['twine_page_title']) ? sanitize_text_field($_POST['twine_page_title']) : '';
        $ga_id = isset($_POST['twine_ga_id']) ? sanitize_text_field($_POST['twine_ga_id']) : '';

        // Prepare data structure
        $data = array(
            'icon' => $icon,
            'name' => $name,
            'description' => $description,
            'header' => $header,
            'links' => $links,
            'social' => $social,
            'footer' => $footer,
            'theme' => $theme,
            'slug' => $slug,
            'page_title' => $page_title,
            'ga_id' => $ga_id
        );

        // Ensure directory exists
        $this->ensure_data_directory();

        // Write to JSON file
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = file_put_contents(TWINE_LINKS_FILE, $json);

        if ($result === false) {
            wp_die('Failed to save links. Please check file permissions.');
        }

        // If slug changed, add new rewrite rule and flush
        if ($slug_changed) {
            add_rewrite_rule('^' . $slug . '/?$', 'index.php?twine_page=1', 'top');
            flush_rewrite_rules();
        }

        // Determine redirect page and tab
        $redirect_page = isset($_POST['redirect_to']) ? sanitize_text_field($_POST['redirect_to']) : 'twine';
        $active_tab = isset($_POST['twine_active_tab']) ? sanitize_text_field($_POST['twine_active_tab']) : '';

        // Redirect back to admin page with tab hash
        $redirect_url = admin_url('admin.php?page=' . $redirect_page . '&saved=true');
        if (!empty($active_tab)) {
            $redirect_url .= '#' . $active_tab;
        }
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        $links = $this->get_links();
        $icon = $this->get_icon();
        $name = $this->get_name();
        $description = $this->get_description();
        $social = $this->get_social();
        $header_links = $this->get_header_links();
        $footer_links = $this->get_footer_links();
        $theme = $this->get_theme();
        $slug = $this->get_slug();
        $public_url = $this->get_public_url();
        $available_themes = $this->get_available_themes();
        ?>
        <div class="wrap">
            <h1>Twine Settings</h1>

            <div class="twine-public-url-notice">
                <p>
                    <strong>Your Public Page:</strong>
                    <a href="<?php echo $public_url; ?>" target="_blank"><?php echo $public_url; ?></a>
                    <button type="button" class="button button-small" id="twine-copy-url-btn" data-url="<?php echo esc_attr($public_url); ?>">Copy Link</button>
                </p>
            </div>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Links saved successfully!</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['theme_uploaded'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Theme uploaded successfully! You can now select "<?php echo esc_html(ucwords(str_replace('-', ' ', $_GET['theme_uploaded']))); ?>" from the theme dropdown.</p>
                </div>
            <?php endif; ?>

            <div class="twine-admin-container">
                <div class="twine-admin-content">
                    <h2 class="nav-tab-wrapper">
                        <a href="#general" class="nav-tab nav-tab-active" data-tab="general">General</a>
                        <a href="#theme" class="nav-tab" data-tab="theme">Theme</a>
                        <a href="#links" class="nav-tab" data-tab="links">Links</a>
                        <a href="#header" class="nav-tab" data-tab="header">Header</a>
                        <a href="#footer" class="nav-tab" data-tab="footer">Footer</a>
                        <a href="#social" class="nav-tab" data-tab="social">Social</a>
                        <a href="#advanced" class="nav-tab" data-tab="advanced">Advanced</a>
                    </h2>

                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="twine-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="twine_save_links">
                        <input type="hidden" name="twine_active_tab" id="twine-active-tab" value="general">
                        <?php wp_nonce_field('twine_save_links', 'twine_nonce'); ?>

                        <div class="twine-tab-content" id="tab-general">
                            <div class="twine-profile-section">

                            <div class="twine-icon-upload">
                                <label>Icon</label>
                                <div class="twine-icon-preview">
                                    <?php if ($icon): ?>
                                        <img src="<?php echo esc_url($icon); ?>" alt="Icon" id="twine-icon-preview-img">
                                    <?php else: ?>
                                        <div class="twine-icon-placeholder" id="twine-icon-placeholder">
                                            <span class="dashicons dashicons-format-image"></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="twine_icon" id="twine-icon-url" value="<?php echo esc_attr($icon); ?>">
                                <div class="twine-icon-buttons">
                                    <button type="button" class="button" id="twine-upload-icon-btn">
                                        Change Icon
                                    </button>
                                    <?php if ($icon): ?>
                                        <button type="button" class="button" id="twine-remove-icon-btn">Remove Icon</button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="twine-profile-fields">
                                <div class="twine-field">
                                    <label for="twine-name">Name</label>
                                    <input type="text"
                                           name="twine_name"
                                           id="twine-name"
                                           value="<?php echo esc_attr($name); ?>"
                                           placeholder="Your Name"
                                           class="regular-text">
                                    <p class="description">Display name that appears under the icon</p>
                                </div>

                                <div class="twine-field">
                                    <label for="twine-description">Description</label>
                                    <textarea name="twine_description"
                                              id="twine-description"
                                              rows="3"
                                              placeholder="A short bio or description"
                                              class="large-text"><?php echo esc_textarea($description); ?></textarea>
                                    <p class="description">Brief description that appears under the name</p>
                                </div>
                            </div>
                        </div>
                        </div>

                        <div class="twine-tab-content" id="tab-theme" style="display: none;">
                        <div class="twine-theme-section">
                            <p class="description">Choose a visual theme for your Twine page. <a href="<?php echo admin_url('admin.php?page=twine-theme-editor'); ?>">Manage themes →</a></p>

                            <div class="twine-field">
                                <label for="twine-theme">Active Theme</label>
                                <select name="twine_theme" id="twine-theme" class="regular-text">
                                    <option value="" <?php selected($theme, ''); ?>>No Theme (Default)</option>
                                    <?php foreach ($available_themes as $slug => $theme_data): ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($theme, $slug); ?>>
                                            <?php echo esc_html($theme_data['name']); ?>
                                            <?php if ($theme_data['custom']): ?> [Custom]<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Visit the <a href="<?php echo admin_url('admin.php?page=twine-theme-editor'); ?>">Themes page</a> to preview, customize, or create themes.</p>
                            </div>
                        </div>
                        </div>

                        <div class="twine-tab-content" id="tab-header" style="display: none;">
                        <div class="twine-header-section">
                            <p class="description">Add icon links that appear at the top of your page. Drag to reorder.</p>

                            <div class="twine-header-container" id="twine-header-container">
                                <?php if (!empty($header_links)): ?>
                                    <?php foreach ($header_links as $index => $item): ?>
                                        <div class="twine-header-item">
                                            <span class="twine-drag-handle dashicons dashicons-menu"></span>
                                            <div class="twine-header-fields">
                                                <div class="twine-header-field twine-header-icon-field">
                                                    <label>Icon</label>
                                                    <select name="header_icon[]" class="twine-header-icon-select">
                                                        <optgroup label="General">
                                                            <option value="home" <?php selected($item['icon'], 'home'); ?>>Home</option>
                                                            <option value="search" <?php selected($item['icon'], 'search'); ?>>Search</option>
                                                            <option value="menu" <?php selected($item['icon'], 'menu'); ?>>Menu</option>
                                                            <option value="grid" <?php selected($item['icon'], 'grid'); ?>>Grid</option>
                                                            <option value="settings" <?php selected($item['icon'], 'settings'); ?>>Settings</option>
                                                            <option value="notification" <?php selected($item['icon'], 'notification'); ?>>Notification</option>
                                                            <option value="info" <?php selected($item['icon'], 'info'); ?>>Info</option>
                                                            <option value="help" <?php selected($item['icon'], 'help'); ?>>Help</option>
                                                            <option value="user" <?php selected($item['icon'], 'user'); ?>>User</option>
                                                            <option value="people" <?php selected($item['icon'], 'people'); ?>>People</option>
                                                            <option value="chat" <?php selected($item['icon'], 'chat'); ?>>Chat</option>
                                                            <option value="share" <?php selected($item['icon'], 'share'); ?>>Share</option>
                                                        </optgroup>
                                                        <optgroup label="Commerce & Business">
                                                            <option value="shop" <?php selected($item['icon'], 'shop'); ?>>Shop</option>
                                                            <option value="cart" <?php selected($item['icon'], 'cart'); ?>>Cart</option>
                                                            <option value="dollar" <?php selected($item['icon'], 'dollar'); ?>>Dollar</option>
                                                            <option value="bitcoin" <?php selected($item['icon'], 'bitcoin'); ?>>Bitcoin</option>
                                                            <option value="ticket" <?php selected($item['icon'], 'ticket'); ?>>Ticket</option>
                                                            <option value="gift" <?php selected($item['icon'], 'gift'); ?>>Gift</option>
                                                            <option value="work" <?php selected($item['icon'], 'work'); ?>>Work</option>
                                                        </optgroup>
                                                        <optgroup label="Media & Content">
                                                            <option value="camera" <?php selected($item['icon'], 'camera'); ?>>Camera</option>
                                                            <option value="video" <?php selected($item['icon'], 'video'); ?>>Video</option>
                                                            <option value="music" <?php selected($item['icon'], 'music'); ?>>Music</option>
                                                            <option value="mic" <?php selected($item['icon'], 'mic'); ?>>Microphone</option>
                                                            <option value="podcast" <?php selected($item['icon'], 'podcast'); ?>>Podcast</option>
                                                            <option value="book" <?php selected($item['icon'], 'book'); ?>>Book</option>
                                                            <option value="document" <?php selected($item['icon'], 'document'); ?>>Document</option>
                                                            <option value="rss" <?php selected($item['icon'], 'rss'); ?>>RSS</option>
                                                        </optgroup>
                                                        <optgroup label="Symbols & Actions">
                                                            <option value="star" <?php selected($item['icon'], 'star'); ?>>Star</option>
                                                            <option value="heart" <?php selected($item['icon'], 'heart'); ?>>Heart</option>
                                                            <option value="bookmark" <?php selected($item['icon'], 'bookmark'); ?>>Bookmark</option>
                                                            <option value="fire" <?php selected($item['icon'], 'fire'); ?>>Fire</option>
                                                            <option value="flash" <?php selected($item['icon'], 'flash'); ?>>Flash</option>
                                                            <option value="crown" <?php selected($item['icon'], 'crown'); ?>>Crown</option>
                                                            <option value="trophy" <?php selected($item['icon'], 'trophy'); ?>>Trophy</option>
                                                            <option value="verified" <?php selected($item['icon'], 'verified'); ?>>Verified</option>
                                                            <option value="lock" <?php selected($item['icon'], 'lock'); ?>>Lock</option>
                                                            <option value="download" <?php selected($item['icon'], 'download'); ?>>Download</option>
                                                        </optgroup>
                                                        <optgroup label="Places & Travel">
                                                            <option value="location" <?php selected($item['icon'], 'location'); ?>>Location</option>
                                                            <option value="map" <?php selected($item['icon'], 'map'); ?>>Map</option>
                                                            <option value="globe" <?php selected($item['icon'], 'globe'); ?>>Globe</option>
                                                            <option value="airplane" <?php selected($item['icon'], 'airplane'); ?>>Airplane</option>
                                                            <option value="car" <?php selected($item['icon'], 'car'); ?>>Car</option>
                                                        </optgroup>
                                                        <optgroup label="Lifestyle">
                                                            <option value="restaurant" <?php selected($item['icon'], 'restaurant'); ?>>Restaurant</option>
                                                            <option value="coffee" <?php selected($item['icon'], 'coffee'); ?>>Coffee</option>
                                                            <option value="fitness" <?php selected($item['icon'], 'fitness'); ?>>Fitness</option>
                                                            <option value="pet" <?php selected($item['icon'], 'pet'); ?>>Pet</option>
                                                            <option value="calendar" <?php selected($item['icon'], 'calendar'); ?>>Calendar</option>
                                                            <option value="sun" <?php selected($item['icon'], 'sun'); ?>>Sun</option>
                                                            <option value="moon" <?php selected($item['icon'], 'moon'); ?>>Moon</option>
                                                            <option value="cloud" <?php selected($item['icon'], 'cloud'); ?>>Cloud</option>
                                                        </optgroup>
                                                        <optgroup label="Creative & Tech">
                                                            <option value="palette" <?php selected($item['icon'], 'palette'); ?>>Palette</option>
                                                            <option value="brush" <?php selected($item['icon'], 'brush'); ?>>Brush</option>
                                                            <option value="code" <?php selected($item['icon'], 'code'); ?>>Code</option>
                                                            <option value="terminal" <?php selected($item['icon'], 'terminal'); ?>>Terminal</option>
                                                            <option value="school" <?php selected($item['icon'], 'school'); ?>>School</option>
                                                        </optgroup>
                                                        <optgroup label="Social Networks">
                                                            <option value="facebook" <?php selected($item['icon'], 'facebook'); ?>>Facebook</option>
                                                            <option value="google" <?php selected($item['icon'], 'google'); ?>>Google</option>
                                                            <option value="instagram" <?php selected($item['icon'], 'instagram'); ?>>Instagram</option>
                                                            <option value="x" <?php selected($item['icon'], 'x'); ?>>X</option>
                                                            <option value="twitter" <?php selected($item['icon'], 'twitter'); ?>>Twitter</option>
                                                            <option value="tiktok" <?php selected($item['icon'], 'tiktok'); ?>>TikTok</option>
                                                            <option value="youtube" <?php selected($item['icon'], 'youtube'); ?>>YouTube</option>
                                                            <option value="linkedin" <?php selected($item['icon'], 'linkedin'); ?>>LinkedIn</option>
                                                            <option value="snapchat" <?php selected($item['icon'], 'snapchat'); ?>>Snapchat</option>
                                                            <option value="pinterest" <?php selected($item['icon'], 'pinterest'); ?>>Pinterest</option>
                                                            <option value="reddit" <?php selected($item['icon'], 'reddit'); ?>>Reddit</option>
                                                            <option value="threads" <?php selected($item['icon'], 'threads'); ?>>Threads</option>
                                                            <option value="bluesky" <?php selected($item['icon'], 'bluesky'); ?>>Bluesky</option>
                                                            <option value="mastodon" <?php selected($item['icon'], 'mastodon'); ?>>Mastodon</option>
                                                        </optgroup>
                                                        <optgroup label="Messaging">
                                                            <option value="discord" <?php selected($item['icon'], 'discord'); ?>>Discord</option>
                                                            <option value="telegram" <?php selected($item['icon'], 'telegram'); ?>>Telegram</option>
                                                            <option value="whatsapp" <?php selected($item['icon'], 'whatsapp'); ?>>WhatsApp</option>
                                                        </optgroup>
                                                        <optgroup label="Streaming & Music">
                                                            <option value="twitch" <?php selected($item['icon'], 'twitch'); ?>>Twitch</option>
                                                            <option value="spotify" <?php selected($item['icon'], 'spotify'); ?>>Spotify</option>
                                                            <option value="apple-music" <?php selected($item['icon'], 'apple-music'); ?>>Apple Music</option>
                                                            <option value="soundcloud" <?php selected($item['icon'], 'soundcloud'); ?>>SoundCloud</option>
                                                            <option value="vimeo" <?php selected($item['icon'], 'vimeo'); ?>>Vimeo</option>
                                                        </optgroup>
                                                        <optgroup label="Developer">
                                                            <option value="github" <?php selected($item['icon'], 'github'); ?>>GitHub</option>
                                                            <option value="stackoverflow" <?php selected($item['icon'], 'stackoverflow'); ?>>Stack Overflow</option>
                                                            <option value="dribbble" <?php selected($item['icon'], 'dribbble'); ?>>Dribbble</option>
                                                            <option value="behance" <?php selected($item['icon'], 'behance'); ?>>Behance</option>
                                                        </optgroup>
                                                        <optgroup label="Writing & Content">
                                                            <option value="medium" <?php selected($item['icon'], 'medium'); ?>>Medium</option>
                                                            <option value="substack" <?php selected($item['icon'], 'substack'); ?>>Substack</option>
                                                        </optgroup>
                                                        <optgroup label="Support & Donations">
                                                            <option value="patreon" <?php selected($item['icon'], 'patreon'); ?>>Patreon</option>
                                                            <option value="ko-fi" <?php selected($item['icon'], 'ko-fi'); ?>>Ko-fi</option>
                                                            <option value="buymeacoffee" <?php selected($item['icon'], 'buymeacoffee'); ?>>Buy Me a Coffee</option>
                                                        </optgroup>
                                                        <optgroup label="Contact & Other">
                                                            <option value="email" <?php selected($item['icon'], 'email'); ?>>Email</option>
                                                            <option value="phone" <?php selected($item['icon'], 'phone'); ?>>Phone</option>
                                                            <option value="website" <?php selected($item['icon'], 'website'); ?>>Website</option>
                                                            <option value="link" <?php selected($item['icon'], 'link'); ?>>Link</option>
                                                            <option value="custom" <?php selected($item['icon'], 'custom'); ?>>Custom Icon...</option>
                                                        </optgroup>
                                                    </select>
                                                </div>
                                                <div class="twine-header-field twine-header-custom-icon-field" style="<?php echo $item['icon'] !== 'custom' ? 'display: none;' : ''; ?>">
                                                    <label>Icon URL</label>
                                                    <input type="url"
                                                           name="header_custom_icon[]"
                                                           value="<?php echo isset($item['custom_icon']) ? esc_url($item['custom_icon']) : ''; ?>"
                                                           placeholder="https://example.com/icon.png"
                                                           class="twine-header-custom-icon">
                                                </div>
                                                <div class="twine-header-field twine-header-url-field">
                                                    <label>URL</label>
                                                    <input type="url"
                                                           name="header_url[]"
                                                           value="<?php echo esc_url($item['url']); ?>"
                                                           placeholder="https://example.com"
                                                           class="twine-header-url"
                                                           required>
                                                </div>
                                                <div class="twine-header-field twine-header-align-field">
                                                    <label>Align</label>
                                                    <select name="header_align[]" class="twine-header-align-select">
                                                        <option value="left" <?php selected(isset($item['align']) ? $item['align'] : 'center', 'left'); ?>>Left</option>
                                                        <option value="center" <?php selected(isset($item['align']) ? $item['align'] : 'center', 'center'); ?>>Center</option>
                                                        <option value="right" <?php selected(isset($item['align']) ? $item['align'] : 'center', 'right'); ?>>Right</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button type="button" class="button twine-remove-header">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <p>
                                <select id="twine-add-header" class="twine-add-social-select">
                                    <option value="">+ Add Header Icon...</option>
                                    <optgroup label="General">
                                        <option value="home">Home</option>
                                        <option value="search">Search</option>
                                        <option value="menu">Menu</option>
                                        <option value="grid">Grid</option>
                                        <option value="settings">Settings</option>
                                        <option value="notification">Notification</option>
                                        <option value="info">Info</option>
                                        <option value="help">Help</option>
                                        <option value="user">User</option>
                                        <option value="people">People</option>
                                        <option value="chat">Chat</option>
                                        <option value="share">Share</option>
                                    </optgroup>
                                    <optgroup label="Commerce & Business">
                                        <option value="shop">Shop</option>
                                        <option value="cart">Cart</option>
                                        <option value="dollar">Dollar</option>
                                        <option value="bitcoin">Bitcoin</option>
                                        <option value="ticket">Ticket</option>
                                        <option value="gift">Gift</option>
                                        <option value="work">Work</option>
                                    </optgroup>
                                    <optgroup label="Media & Content">
                                        <option value="camera">Camera</option>
                                        <option value="video">Video</option>
                                        <option value="music">Music</option>
                                        <option value="mic">Microphone</option>
                                        <option value="podcast">Podcast</option>
                                        <option value="book">Book</option>
                                        <option value="document">Document</option>
                                        <option value="rss">RSS</option>
                                    </optgroup>
                                    <optgroup label="Symbols & Actions">
                                        <option value="star">Star</option>
                                        <option value="heart">Heart</option>
                                        <option value="bookmark">Bookmark</option>
                                        <option value="fire">Fire</option>
                                        <option value="flash">Flash</option>
                                        <option value="crown">Crown</option>
                                        <option value="trophy">Trophy</option>
                                        <option value="verified">Verified</option>
                                        <option value="lock">Lock</option>
                                        <option value="download">Download</option>
                                    </optgroup>
                                    <optgroup label="Places & Travel">
                                        <option value="location">Location</option>
                                        <option value="map">Map</option>
                                        <option value="globe">Globe</option>
                                        <option value="airplane">Airplane</option>
                                        <option value="car">Car</option>
                                    </optgroup>
                                    <optgroup label="Lifestyle">
                                        <option value="restaurant">Restaurant</option>
                                        <option value="coffee">Coffee</option>
                                        <option value="fitness">Fitness</option>
                                        <option value="pet">Pet</option>
                                        <option value="calendar">Calendar</option>
                                        <option value="sun">Sun</option>
                                        <option value="moon">Moon</option>
                                        <option value="cloud">Cloud</option>
                                    </optgroup>
                                    <optgroup label="Creative & Tech">
                                        <option value="palette">Palette</option>
                                        <option value="brush">Brush</option>
                                        <option value="code">Code</option>
                                        <option value="terminal">Terminal</option>
                                        <option value="school">School</option>
                                    </optgroup>
                                    <optgroup label="Social Networks">
                                        <option value="facebook">Facebook</option>
                                        <option value="google">Google</option>
                                        <option value="instagram">Instagram</option>
                                        <option value="x">X</option>
                                        <option value="twitter">Twitter</option>
                                        <option value="tiktok">TikTok</option>
                                        <option value="youtube">YouTube</option>
                                        <option value="linkedin">LinkedIn</option>
                                        <option value="snapchat">Snapchat</option>
                                        <option value="pinterest">Pinterest</option>
                                        <option value="reddit">Reddit</option>
                                        <option value="threads">Threads</option>
                                        <option value="bluesky">Bluesky</option>
                                        <option value="mastodon">Mastodon</option>
                                    </optgroup>
                                    <optgroup label="Messaging">
                                        <option value="discord">Discord</option>
                                        <option value="telegram">Telegram</option>
                                        <option value="whatsapp">WhatsApp</option>
                                    </optgroup>
                                    <optgroup label="Streaming & Music">
                                        <option value="twitch">Twitch</option>
                                        <option value="spotify">Spotify</option>
                                        <option value="apple-music">Apple Music</option>
                                        <option value="soundcloud">SoundCloud</option>
                                        <option value="vimeo">Vimeo</option>
                                    </optgroup>
                                    <optgroup label="Developer">
                                        <option value="github">GitHub</option>
                                        <option value="stackoverflow">Stack Overflow</option>
                                        <option value="dribbble">Dribbble</option>
                                        <option value="behance">Behance</option>
                                    </optgroup>
                                    <optgroup label="Writing & Content">
                                        <option value="medium">Medium</option>
                                        <option value="substack">Substack</option>
                                    </optgroup>
                                    <optgroup label="Support & Donations">
                                        <option value="patreon">Patreon</option>
                                        <option value="ko-fi">Ko-fi</option>
                                        <option value="buymeacoffee">Buy Me a Coffee</option>
                                    </optgroup>
                                    <optgroup label="Contact & Other">
                                        <option value="email">Email</option>
                                        <option value="phone">Phone</option>
                                        <option value="website">Website</option>
                                        <option value="link">Link</option>
                                        <option value="custom">Custom...</option>
                                    </optgroup>
                                </select>
                            </p>
                        </div>
                        </div>

                        <div class="twine-tab-content" id="tab-links" style="display: none;">
                        <div class="twine-links-section">
                            <p class="description">Add links to display on your page. Drag to reorder.</p>

                            <div class="twine-links-container" id="twine-links-container">
                            <?php if (!empty($links)): ?>
                                <?php foreach ($links as $index => $link): ?>
                                    <div class="twine-link-item">
                                        <span class="twine-drag-handle dashicons dashicons-menu"></span>
                                        <div class="twine-link-fields">
                                            <div class="twine-link-image-field">
                                                <div class="twine-link-image-wrapper twine-link-image-picker">
                                                    <?php if (!empty($link['image'])): ?>
                                                        <img src="<?php echo esc_url($link['image']); ?>" class="twine-link-image-preview">
                                                        <span class="twine-link-image-remove">&times;</span>
                                                    <?php else: ?>
                                                        <span class="twine-link-image-placeholder dashicons dashicons-format-image"></span>
                                                    <?php endif; ?>
                                                </div>
                                                <input type="hidden" name="link_image[]" value="<?php echo esc_url($link['image'] ?? ''); ?>" class="twine-link-image-url">
                                            </div>
                                            <div class="twine-link-field">
                                                <label>Label</label>
                                                <input type="text"
                                                       name="link_text[]"
                                                       value="<?php echo esc_attr($link['text']); ?>"
                                                       placeholder="Link Text"
                                                       class="twine-link-text"
                                                       required>
                                            </div>
                                            <div class="twine-link-field">
                                                <label>URL</label>
                                                <input type="url"
                                                       name="link_url[]"
                                                       value="<?php echo esc_url($link['url']); ?>"
                                                       placeholder="https://example.com"
                                                       class="twine-link-url"
                                                       required>
                                            </div>
                                        </div>
                                        <button type="button" class="button twine-remove-link">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </div>

                            <p>
                                <button type="button" class="button" id="twine-add-link">
                                    <span class="dashicons dashicons-plus-alt"></span> Add Link
                                </button>
                            </p>
                        </div>
                        </div>

                        <div class="twine-tab-content" id="tab-social" style="display: none;">
                        <div class="twine-social-section">
                            <p class="description">Add links to your social media profiles. Drag to reorder.</p>

                            <div class="twine-social-container" id="twine-social-container">
                                <?php if (!empty($social)): ?>
                                    <?php foreach ($social as $index => $item): ?>
                                        <div class="twine-social-item">
                                            <span class="twine-drag-handle dashicons dashicons-menu"></span>
                                            <div class="twine-social-fields">
                                                <div class="twine-social-field twine-social-icon-field">
                                                    <label>Icon</label>
                                                    <select name="social_icon[]" class="twine-social-icon-select">
                                                        <optgroup label="Social Networks">
                                                            <option value="facebook" <?php selected($item['icon'], 'facebook'); ?>>Facebook</option>
                                                            <option value="google" <?php selected($item['icon'], 'google'); ?>>Google</option>
                                                            <option value="instagram" <?php selected($item['icon'], 'instagram'); ?>>Instagram</option>
                                                            <option value="x" <?php selected($item['icon'], 'x'); ?>>X</option>
                                                            <option value="twitter" <?php selected($item['icon'], 'twitter'); ?>>Twitter</option>
                                                            <option value="tiktok" <?php selected($item['icon'], 'tiktok'); ?>>TikTok</option>
                                                            <option value="youtube" <?php selected($item['icon'], 'youtube'); ?>>YouTube</option>
                                                            <option value="linkedin" <?php selected($item['icon'], 'linkedin'); ?>>LinkedIn</option>
                                                            <option value="snapchat" <?php selected($item['icon'], 'snapchat'); ?>>Snapchat</option>
                                                            <option value="pinterest" <?php selected($item['icon'], 'pinterest'); ?>>Pinterest</option>
                                                            <option value="reddit" <?php selected($item['icon'], 'reddit'); ?>>Reddit</option>
                                                            <option value="threads" <?php selected($item['icon'], 'threads'); ?>>Threads</option>
                                                            <option value="bluesky" <?php selected($item['icon'], 'bluesky'); ?>>Bluesky</option>
                                                            <option value="mastodon" <?php selected($item['icon'], 'mastodon'); ?>>Mastodon</option>
                                                        </optgroup>
                                                        <optgroup label="Messaging">
                                                            <option value="discord" <?php selected($item['icon'], 'discord'); ?>>Discord</option>
                                                            <option value="telegram" <?php selected($item['icon'], 'telegram'); ?>>Telegram</option>
                                                            <option value="whatsapp" <?php selected($item['icon'], 'whatsapp'); ?>>WhatsApp</option>
                                                        </optgroup>
                                                        <optgroup label="Streaming & Music">
                                                            <option value="twitch" <?php selected($item['icon'], 'twitch'); ?>>Twitch</option>
                                                            <option value="spotify" <?php selected($item['icon'], 'spotify'); ?>>Spotify</option>
                                                            <option value="apple-music" <?php selected($item['icon'], 'apple-music'); ?>>Apple Music</option>
                                                            <option value="soundcloud" <?php selected($item['icon'], 'soundcloud'); ?>>SoundCloud</option>
                                                            <option value="vimeo" <?php selected($item['icon'], 'vimeo'); ?>>Vimeo</option>
                                                        </optgroup>
                                                        <optgroup label="Creative & Design">
                                                            <option value="dribbble" <?php selected($item['icon'], 'dribbble'); ?>>Dribbble</option>
                                                            <option value="behance" <?php selected($item['icon'], 'behance'); ?>>Behance</option>
                                                        </optgroup>
                                                        <optgroup label="Developer">
                                                            <option value="github" <?php selected($item['icon'], 'github'); ?>>GitHub</option>
                                                            <option value="stackoverflow" <?php selected($item['icon'], 'stackoverflow'); ?>>Stack Overflow</option>
                                                        </optgroup>
                                                        <optgroup label="Writing & Content">
                                                            <option value="medium" <?php selected($item['icon'], 'medium'); ?>>Medium</option>
                                                            <option value="substack" <?php selected($item['icon'], 'substack'); ?>>Substack</option>
                                                        </optgroup>
                                                        <optgroup label="Support & Donations">
                                                            <option value="patreon" <?php selected($item['icon'], 'patreon'); ?>>Patreon</option>
                                                            <option value="ko-fi" <?php selected($item['icon'], 'ko-fi'); ?>>Ko-fi</option>
                                                            <option value="buymeacoffee" <?php selected($item['icon'], 'buymeacoffee'); ?>>Buy Me a Coffee</option>
                                                        </optgroup>
                                                        <optgroup label="Contact & Other">
                                                            <option value="email" <?php selected($item['icon'], 'email'); ?>>Email</option>
                                                            <option value="phone" <?php selected($item['icon'], 'phone'); ?>>Phone</option>
                                                            <option value="website" <?php selected($item['icon'], 'website'); ?>>Website</option>
                                                            <option value="link" <?php selected($item['icon'], 'link'); ?>>Link</option>
                                                            <option value="custom" <?php selected($item['icon'], 'custom'); ?>>Custom Icon...</option>
                                                        </optgroup>
                                                    </select>
                                                </div>
                                                <div class="twine-social-field twine-social-custom-icon-field" style="<?php echo $item['icon'] !== 'custom' ? 'display: none;' : ''; ?>">
                                                    <label>Icon URL</label>
                                                    <input type="url"
                                                           name="social_custom_icon[]"
                                                           value="<?php echo isset($item['custom_icon']) ? esc_url($item['custom_icon']) : ''; ?>"
                                                           placeholder="https://example.com/icon.png"
                                                           class="twine-social-custom-icon">
                                                </div>
                                                <div class="twine-social-field twine-social-name-field">
                                                    <label>Name</label>
                                                    <input type="text"
                                                           name="social_name[]"
                                                           value="<?php echo esc_attr($item['name']); ?>"
                                                           placeholder="Social Network Name"
                                                           class="twine-social-name">
                                                </div>
                                                <div class="twine-social-field twine-social-url-field">
                                                    <label>URL</label>
                                                    <input type="url"
                                                           name="social_url[]"
                                                           value="<?php echo esc_url($item['url']); ?>"
                                                           placeholder="https://example.com/username"
                                                           class="twine-social-url"
                                                           required>
                                                </div>
                                            </div>
                                            <button type="button" class="button twine-remove-social">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <p>
                                <select id="twine-add-social" class="twine-add-social-select">
                                    <option value="">+ Add Social...</option>
                                    <optgroup label="Social Networks">
                                        <option value="facebook">Facebook</option>
                                        <option value="google">Google</option>
                                        <option value="instagram">Instagram</option>
                                        <option value="x">X</option>
                                        <option value="twitter">Twitter</option>
                                        <option value="tiktok">TikTok</option>
                                        <option value="youtube">YouTube</option>
                                        <option value="linkedin">LinkedIn</option>
                                        <option value="snapchat">Snapchat</option>
                                        <option value="pinterest">Pinterest</option>
                                        <option value="reddit">Reddit</option>
                                        <option value="threads">Threads</option>
                                        <option value="bluesky">Bluesky</option>
                                        <option value="mastodon">Mastodon</option>
                                    </optgroup>
                                    <optgroup label="Messaging">
                                        <option value="discord">Discord</option>
                                        <option value="telegram">Telegram</option>
                                        <option value="whatsapp">WhatsApp</option>
                                    </optgroup>
                                    <optgroup label="Streaming & Music">
                                        <option value="twitch">Twitch</option>
                                        <option value="spotify">Spotify</option>
                                        <option value="apple-music">Apple Music</option>
                                        <option value="soundcloud">SoundCloud</option>
                                        <option value="vimeo">Vimeo</option>
                                    </optgroup>
                                    <optgroup label="Creative & Design">
                                        <option value="dribbble">Dribbble</option>
                                        <option value="behance">Behance</option>
                                    </optgroup>
                                    <optgroup label="Developer">
                                        <option value="github">GitHub</option>
                                        <option value="stackoverflow">Stack Overflow</option>
                                    </optgroup>
                                    <optgroup label="Writing & Content">
                                        <option value="medium">Medium</option>
                                        <option value="substack">Substack</option>
                                    </optgroup>
                                    <optgroup label="Support & Donations">
                                        <option value="patreon">Patreon</option>
                                        <option value="ko-fi">Ko-fi</option>
                                        <option value="buymeacoffee">Buy Me a Coffee</option>
                                    </optgroup>
                                    <optgroup label="Contact & Other">
                                        <option value="email">Email</option>
                                        <option value="phone">Phone</option>
                                        <option value="website">Website</option>
                                        <option value="link">Link</option>
                                        <option value="custom">Custom...</option>
                                    </optgroup>
                                </select>
                            </p>
                        </div>
                        </div>

                        <div class="twine-tab-content" id="tab-footer" style="display: none;">
                        <div class="twine-footer-section">
                            <p class="description">Add small text links that appear at the very bottom of your page (e.g. Privacy, Terms). Drag to reorder.</p>

                            <div class="twine-footer-container" id="twine-footer-container">
                                <?php if (!empty($footer_links)): ?>
                                    <?php foreach ($footer_links as $index => $item): ?>
                                        <div class="twine-footer-item">
                                            <span class="twine-drag-handle dashicons dashicons-menu"></span>
                                            <div class="twine-footer-fields">
                                                <div class="twine-footer-field twine-footer-text-field">
                                                    <label>Label</label>
                                                    <input type="text"
                                                           name="footer_text[]"
                                                           value="<?php echo esc_attr($item['text']); ?>"
                                                           placeholder="Link Text"
                                                           class="twine-footer-text"
                                                           required>
                                                </div>
                                                <div class="twine-footer-field twine-footer-url-field">
                                                    <label>URL</label>
                                                    <input type="url"
                                                           name="footer_url[]"
                                                           value="<?php echo esc_url($item['url']); ?>"
                                                           placeholder="https://example.com"
                                                           class="twine-footer-url"
                                                           required>
                                                </div>
                                            </div>
                                            <button type="button" class="button twine-remove-footer">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <p>
                                <button type="button" class="button" id="twine-add-footer">
                                    <span class="dashicons dashicons-plus-alt"></span> Add Footer Link
                                </button>
                            </p>
                        </div>
                        </div>

                        <div class="twine-tab-content" id="tab-advanced" style="display: none;">
                            <div class="twine-advanced-section">
                                <h3>Advanced Settings</h3>
                                <p class="description">Configure advanced options for your Twine page.</p>

                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="twine-slug">Page Slug</label>
                                        </th>
                                        <td>
                                            <input type="text"
                                                   name="twine_slug"
                                                   id="twine-slug"
                                                   value="<?php echo esc_attr($this->get_slug()); ?>"
                                                   class="regular-text"
                                                   pattern="[a-z0-9-]+"
                                                   placeholder="twine">
                                            <p class="description">
                                                The URL path for your Twine page. Only lowercase letters, numbers, and hyphens are allowed.<br>
                                                <strong>Current URL:</strong> <code id="twine-slug-preview"><?php echo home_url('/' . $this->get_slug()); ?></code><br>
                                                <em>Warning: Changing this will break any existing links to your Twine page.</em>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="twine-page-title">Page Title</label>
                                        </th>
                                        <td>
                                            <input type="text"
                                                   name="twine_page_title"
                                                   id="twine-page-title"
                                                   value="<?php echo esc_attr($this->get_page_title()); ?>"
                                                   class="regular-text"
                                                   placeholder="Links">
                                            <p class="description">
                                                The browser title for your Twine page. Leave blank to use your profile name or "Links" as default.
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="twine-ga-id">Google Analytics ID</label>
                                        </th>
                                        <td>
                                            <input type="text"
                                                   name="twine_ga_id"
                                                   id="twine-ga-id"
                                                   value="<?php echo esc_attr($this->get_ga_id()); ?>"
                                                   class="regular-text"
                                                   placeholder="G-XXXXXXXXXX">
                                            <p class="description">
                                                Your GA4 Measurement ID (e.g., G-XXXXXXXXXX). If MonsterInsights is installed, this will override its tracking ID for the Twine page.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <p class="submit">
                            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save">
                        </p>
                    </form>
                </div>

                <div class="twine-admin-sidebar">
                    <div class="twine-preview-container">
                        <iframe src="<?php echo add_query_arg(array('twine_preview' => $theme, 'mode' => 'live'), home_url('/')); ?>"
                                scrolling="yes"
                                class="twine-admin-preview-iframe"></iframe>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render theme editor page
     */
    public function render_theme_editor_page() {
        $available_themes = $this->get_available_themes();
        $active_theme = $this->get_theme();

        // Sort themes alphabetically by name
        uasort($available_themes, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        // If there's an active theme, move it to the front
        if (!empty($active_theme) && isset($available_themes[$active_theme])) {
            $active_theme_data = $available_themes[$active_theme];
            unset($available_themes[$active_theme]);
            $available_themes = array($active_theme => $active_theme_data) + $available_themes;
        }

        // Check if we're in editor mode (creating new or editing existing)
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $editing_theme = isset($_GET['theme']) ? sanitize_text_field($_GET['theme']) : '';
        $show_editor = ($action === 'new' || !empty($editing_theme));
        $is_editing = !empty($editing_theme) && isset($available_themes[$editing_theme]);

        if (!$show_editor) {
            // Show theme gallery
            ?>
            <div class="wrap">
                <h1>Twine Themes</h1>
                <div class="twine-theme-header">
                    <p class="description">Browse, preview, and manage your Twine themes</p>
                    <div class="twine-theme-actions">
                        <a href="<?php echo admin_url('admin.php?page=twine-theme-editor&action=new'); ?>" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt"></span> Add Theme
                        </a>
                        <button type="button" class="button" id="twine-upload-theme-btn-main">
                            <span class="dashicons dashicons-upload"></span> Upload Theme
                        </button>
                    </div>
                </div>

                <?php if (isset($_GET['created']) || isset($_GET['updated'])): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php echo isset($_GET['updated']) ? 'Theme updated successfully!' : 'Theme created successfully!'; ?></p>
                    </div>
                <?php endif; ?>

                <?php wp_nonce_field('twine_save_links', 'twine_nonce'); ?>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="twine-theme-upload-form" enctype="multipart/form-data" style="display: none;">
                    <input type="hidden" name="action" value="twine_save_links">
                    <input type="hidden" name="redirect_to" value="twine-theme-editor">
                    <?php wp_nonce_field('twine_save_links', 'twine_nonce'); ?>
                    <input type="file" name="twine_theme_upload" id="twine-theme-upload-main" accept=".css">
                </form>

                <div class="twine-themes-grid">
                    <?php if (empty($active_theme)): ?>
                    <!-- Default Theme Card (Active) -->
                    <div class="twine-theme-card active" data-theme="">
                        <div class="twine-theme-preview">
                            <iframe src="<?php echo add_query_arg(array('twine_preview' => ''), home_url('/')); ?>"
                                    scrolling="no"
                                    class="twine-theme-preview-iframe"></iframe>
                        </div>
                        <div class="twine-theme-info">
                            <h3>Default Theme</h3>
                            <p>Clean and simple design</p>
                        </div>
                        <div class="twine-theme-card-actions">
                            <a href="<?php echo add_query_arg(array('twine_preview' => ''), home_url('/')); ?>"
                               target="_blank"
                               class="button">Preview</a>
                            <a href="<?php echo admin_url('admin.php?page=twine-theme-editor&action=new'); ?>"
                               class="button">Customize</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Theme Cards -->
                    <?php
                    $first_shown = false;
                    foreach ($available_themes as $slug => $theme_data):
                        // Show active theme first
                        ?>
                        <div class="twine-theme-card <?php echo $active_theme === $slug ? 'active' : ''; ?>" data-theme="<?php echo esc_attr($slug); ?>">
                            <div class="twine-theme-preview">
                                <iframe src="<?php echo add_query_arg(array('twine_preview' => $slug), home_url('/')); ?>"
                                        scrolling="no"
                                        class="twine-theme-preview-iframe"></iframe>
                            </div>
                            <div class="twine-theme-info">
                                <h3>
                                    <?php echo esc_html($theme_data['name']); ?>
                                    <?php if ($theme_data['custom']): ?>
                                        <span class="twine-custom-badge">Custom</span>
                                    <?php endif; ?>
                                </h3>
                                <?php if ($theme_data['description']): ?>
                                    <p><?php echo esc_html($theme_data['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="twine-theme-card-actions">
                                <?php if ($active_theme !== $slug): ?>
                                    <button type="button" class="button twine-select-theme-btn-gallery" data-theme="<?php echo esc_attr($slug); ?>">Use</button>
                                <?php endif; ?>
                                <a href="<?php echo add_query_arg(array('twine_preview' => $slug), home_url('/')); ?>"
                                   target="_blank"
                                   class="button">Preview</a>
                                <?php if ($theme_data['custom']): ?>
                                    <a href="<?php echo admin_url('admin.php?page=twine-theme-editor&action=edit&theme=' . urlencode($slug)); ?>"
                                       class="button">Edit</a>
                                <?php else: ?>
                                    <a href="<?php echo admin_url('admin.php?page=twine-theme-editor&action=new&base-theme=' . urlencode($slug)); ?>"
                                       class="button">Customize</a>
                                <?php endif; ?>
                                <?php if ($theme_data['custom']): ?>
                                    <button type="button"
                                            class="button twine-delete-theme-btn"
                                            data-theme="<?php echo esc_attr($slug); ?>"
                                            data-name="<?php echo esc_attr($theme_data['name']); ?>">Delete</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                        // After showing the first theme (active), show Default if not active
                        if (!$first_shown && !empty($active_theme)):
                            $first_shown = true;
                        ?>
                        <!-- Default Theme Card (Not Active) -->
                        <div class="twine-theme-card" data-theme="">
                            <div class="twine-theme-preview">
                                <iframe src="<?php echo add_query_arg(array('twine_preview' => ''), home_url('/')); ?>"
                                        scrolling="no"
                                        class="twine-theme-preview-iframe"></iframe>
                            </div>
                            <div class="twine-theme-info">
                                <h3>Default Theme</h3>
                                <p>Clean and simple design</p>
                            </div>
                            <div class="twine-theme-card-actions">
                                <button type="button" class="button twine-select-theme-btn-gallery" data-theme="">Use</button>
                                <a href="<?php echo add_query_arg(array('twine_preview' => ''), home_url('/')); ?>"
                                   target="_blank"
                                   class="button">Preview</a>
                                <a href="<?php echo admin_url('admin.php?page=twine-theme-editor&action=new'); ?>"
                                   class="button">Customize</a>
                            </div>
                        </div>
                        <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            </div>
            <?php
            return;
        }

        // Show editor mode
        $defaults = array(
            'theme_name' => '',
            'base_theme' => '',
            'background_color' => '#ffffff',
            'button_bg' => '#0073aa',
            'button_text' => '#ffffff',
            'button_hover_bg' => '#005177',
            'name_color' => '#000000',
            'description_color' => '#666666',
            'social_icon_color' => '#000000',
            'icon_border_color' => '#0073aa',
            'button_radius' => '12',
            'name_font_size' => '52',
            'name_font_weight' => '700',
            'description_font_size' => '20',
            'button_font_size' => '16',
            'button_font_weight' => '500'
        );

        // Check if customizing from an existing theme
        $base_theme = isset($_GET['base-theme']) ? sanitize_text_field($_GET['base-theme']) : '';
        $theme_to_load = $is_editing ? $editing_theme : $base_theme;

        // Load theme values if editing or customizing from existing
        if (!empty($theme_to_load) && isset($available_themes[$theme_to_load])) {
            $theme_data = $available_themes[$theme_to_load];

            // Set theme name: if customizing from a built-in theme, add "Customized" suffix
            // If editing or customizing from a custom theme, keep the original name
            if (!empty($base_theme) && !$theme_data['custom']) {
                $defaults['theme_name'] = $theme_data['name'] . ' Customized';
            } else {
                $defaults['theme_name'] = $theme_data['name'];
            }

            $theme_path = $theme_data['custom']
                ? TWINE_CUSTOM_THEMES_DIR . '/' . $theme_to_load . '.css'
                : TWINE_PLUGIN_DIR . 'themes/' . $theme_to_load . '.css';

            if (file_exists($theme_path)) {
                $css_content = file_get_contents($theme_path);

                if (preg_match('/\.twine-container\s*\{[^}]*background:\s*([^;]+);/s', $css_content, $matches)) {
                    if (preg_match('/#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}/', trim($matches[1]), $color_match)) {
                        $defaults['background_color'] = $color_match[0];
                    }
                }
                if (preg_match('/\.twine-link-button\s*\{[^}]*background:\s*([^;]+);/s', $css_content, $matches)) {
                    if (preg_match('/#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}/', trim($matches[1]), $color_match)) {
                        $defaults['button_bg'] = $color_match[0];
                    }
                }
                if (preg_match('/\.twine-link-button\s*\{[^}]*color:\s*([^;]+);/s', $css_content, $matches)) {
                    if (preg_match('/#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}/', $matches[1], $color_match)) {
                        $defaults['button_text'] = $color_match[0];
                    }
                }
                if (preg_match('/\.twine-name\s*\{[^}]*color:\s*([^;]+);/s', $css_content, $matches)) {
                    if (preg_match('/#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}/', $matches[1], $color_match)) {
                        $defaults['name_color'] = $color_match[0];
                    }
                }
                if (preg_match('/\.twine-description\s*\{[^}]*color:\s*([^;]+);/s', $css_content, $matches)) {
                    if (preg_match('/#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}/', $matches[1], $color_match)) {
                        $defaults['description_color'] = $color_match[0];
                    }
                }
                if (preg_match('/\.twine-social-icon\s*\{[^}]*color:\s*([^;]+);/s', $css_content, $matches)) {
                    if (preg_match('/#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}/', $matches[1], $color_match)) {
                        $defaults['social_icon_color'] = $color_match[0];
                    }
                }
                if (preg_match('/\.twine-icon\s*\{[^}]*border[^:]*:\s*[^#]*([#0-9a-fA-F]{6}|[#0-9a-fA-F]{3})/s', $css_content, $matches)) {
                    $defaults['icon_border_color'] = $matches[1];
                }
                if (preg_match('/\.twine-link-button\s*\{[^}]*border-radius:\s*(\d+)px/s', $css_content, $matches)) {
                    $defaults['button_radius'] = $matches[1];
                }

                // Parse font properties
                if (preg_match('/\.twine-name\s*\{[^}]*font-size:\s*(\d+)px/s', $css_content, $matches)) {
                    $defaults['name_font_size'] = $matches[1];
                }
                if (preg_match('/\.twine-name\s*\{[^}]*font-weight:\s*(\d+)/s', $css_content, $matches)) {
                    $defaults['name_font_weight'] = $matches[1];
                }
                if (preg_match('/\.twine-description\s*\{[^}]*font-size:\s*(\d+)px/s', $css_content, $matches)) {
                    $defaults['description_font_size'] = $matches[1];
                }
                if (preg_match('/\.twine-link-button\s*\{[^}]*font-size:\s*(\d+)px/s', $css_content, $matches)) {
                    $defaults['button_font_size'] = $matches[1];
                }
                if (preg_match('/\.twine-link-button\s*\{[^}]*font-weight:\s*(\d+)/s', $css_content, $matches)) {
                    $defaults['button_font_weight'] = $matches[1];
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo admin_url('admin.php?page=twine-theme-editor'); ?>" class="page-title-action" style="text-decoration: none;">← Back to Themes</a>
                Theme Editor
            </h1>

            <div class="twine-admin-container">
                <div class="twine-admin-content">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="twine-theme-editor-form">
                <input type="hidden" name="action" value="twine_save_custom_theme">
                <?php wp_nonce_field('twine_save_custom_theme', 'twine_theme_editor_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="theme-name">Theme Name</label></th>
                        <td>
                            <input type="text"
                                   name="theme_name"
                                   id="theme-name"
                                   class="regular-text"
                                   value="<?php echo esc_attr($defaults['theme_name']); ?>"
                                   required>
                            <p class="description">Give your custom theme a unique name</p>
                        </td>
                    </tr>

                    <!-- Background -->
                    <tr>
                        <th scope="row"><label for="background-color">Background Color</label></th>
                        <td>
                            <input type="text"
                                   name="background_color"
                                   id="background-color"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['background_color']); ?>">
                            <p class="description">Container background color</p>
                        </td>
                    </tr>

                    <!-- Name -->
                    <tr>
                        <th scope="row"><label for="name-color">Name Color</label></th>
                        <td>
                            <input type="text"
                                   name="name_color"
                                   id="name-color"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['name_color']); ?>">
                            <p class="description">Profile name text color</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="name-font-size">Name Font Size</label></th>
                        <td>
                            <input type="number"
                                   name="name_font_size"
                                   id="name-font-size"
                                   class="small-text"
                                   value="<?php echo esc_attr($defaults['name_font_size']); ?>"
                                   min="12"
                                   max="100"> px
                            <p class="description">Font size for profile name</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="name-font-weight">Name Font Weight</label></th>
                        <td>
                            <select name="name_font_weight" id="name-font-weight">
                                <option value="400" <?php selected($defaults['name_font_weight'], '400'); ?>>Normal (400)</option>
                                <option value="500" <?php selected($defaults['name_font_weight'], '500'); ?>>Medium (500)</option>
                                <option value="600" <?php selected($defaults['name_font_weight'], '600'); ?>>Semi-Bold (600)</option>
                                <option value="700" <?php selected($defaults['name_font_weight'], '700'); ?>>Bold (700)</option>
                                <option value="800" <?php selected($defaults['name_font_weight'], '800'); ?>>Extra Bold (800)</option>
                            </select>
                            <p class="description">Font weight for profile name</p>
                        </td>
                    </tr>

                    <!-- Description -->
                    <tr>
                        <th scope="row"><label for="description-color">Description Color</label></th>
                        <td>
                            <input type="text"
                                   name="description_color"
                                   id="description-color"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['description_color']); ?>">
                            <p class="description">Profile description text color</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="description-font-size">Description Font Size</label></th>
                        <td>
                            <input type="number"
                                   name="description_font_size"
                                   id="description-font-size"
                                   class="small-text"
                                   value="<?php echo esc_attr($defaults['description_font_size']); ?>"
                                   min="12"
                                   max="32"> px
                            <p class="description">Font size for profile description</p>
                        </td>
                    </tr>

                    <!-- Buttons -->
                    <tr>
                        <th scope="row"><label for="button-bg">Button Background</label></th>
                        <td>
                            <input type="text"
                                   name="button_bg"
                                   id="button-bg"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['button_bg']); ?>">
                            <p class="description">Link button background color</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="button-text">Button Text Color</label></th>
                        <td>
                            <input type="text"
                                   name="button_text"
                                   id="button-text"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['button_text']); ?>">
                            <p class="description">Link button text color</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="button-hover-bg">Button Hover Background</label></th>
                        <td>
                            <input type="text"
                                   name="button_hover_bg"
                                   id="button-hover-bg"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['button_hover_bg']); ?>">
                            <p class="description">Button background when hovering</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="button-radius">Button Border Radius</label></th>
                        <td>
                            <input type="number"
                                   name="button_radius"
                                   id="button-radius"
                                   class="small-text"
                                   value="<?php echo esc_attr($defaults['button_radius']); ?>"
                                   min="0"
                                   max="50"> px
                            <p class="description">Roundness of button corners (0 = square, higher = more rounded)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="button-font-size">Button Font Size</label></th>
                        <td>
                            <input type="number"
                                   name="button_font_size"
                                   id="button-font-size"
                                   class="small-text"
                                   value="<?php echo esc_attr($defaults['button_font_size']); ?>"
                                   min="12"
                                   max="24"> px
                            <p class="description">Font size for link buttons</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="button-font-weight">Button Font Weight</label></th>
                        <td>
                            <select name="button_font_weight" id="button-font-weight">
                                <option value="400" <?php selected($defaults['button_font_weight'], '400'); ?>>Normal (400)</option>
                                <option value="500" <?php selected($defaults['button_font_weight'], '500'); ?>>Medium (500)</option>
                                <option value="600" <?php selected($defaults['button_font_weight'], '600'); ?>>Semi-Bold (600)</option>
                                <option value="700" <?php selected($defaults['button_font_weight'], '700'); ?>>Bold (700)</option>
                            </select>
                            <p class="description">Font weight for link buttons</p>
                        </td>
                    </tr>

                    <!-- Icon -->
                    <tr>
                        <th scope="row"><label for="icon-border-color">Icon Border Color</label></th>
                        <td>
                            <input type="text"
                                   name="icon_border_color"
                                   id="icon-border-color"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['icon_border_color']); ?>">
                            <p class="description">Profile icon border color</p>
                        </td>
                    </tr>

                    <!-- Social -->
                    <tr>
                        <th scope="row"><label for="social-icon-color">Social Icon Color</label></th>
                        <td>
                            <input type="text"
                                   name="social_icon_color"
                                   id="social-icon-color"
                                   class="color-picker"
                                   value="<?php echo esc_attr($defaults['social_icon_color']); ?>">
                            <p class="description">Social media icon color</p>
                        </td>
                    </tr>
                </table>

                        <p class="submit">
                            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save">
                        </p>
                    </form>
                </div>

                <div class="twine-admin-sidebar">
                    <div class="twine-preview-container">
                        <?php
                        // Show preview of theme being edited or customized from
                        $preview_theme = $is_editing ? $editing_theme : (!empty($base_theme) ? $base_theme : '');
                        ?>
                        <iframe src="<?php echo add_query_arg(array('twine_preview' => $preview_theme), home_url('/' . $this->get_slug())); ?>"
                                scrolling="yes"
                                class="twine-admin-preview-iframe"></iframe>
                    </div>
                    <?php if ($is_editing && $available_themes[$editing_theme]['custom']): ?>
                        <p style="text-align: center; margin-top: 15px;">
                            <a href="<?php echo admin_url('admin.php?twine_download_theme=' . $editing_theme); ?>"
                               class="button">
                                <span class="dashicons dashicons-download" style="margin-top: 4px;"></span> Download Theme
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    /**
     * Save custom theme from theme editor
     */
    public function save_custom_theme() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }

        // Verify nonce
        if (!isset($_POST['twine_theme_editor_nonce']) || !wp_verify_nonce($_POST['twine_theme_editor_nonce'], 'twine_save_custom_theme')) {
            wp_die('Security check failed');
        }

        // Get form values
        $theme_name = isset($_POST['theme_name']) ? sanitize_text_field($_POST['theme_name']) : '';
        if (empty($theme_name)) {
            wp_die('Theme name is required.');
        }

        $theme_slug = sanitize_title($theme_name);

        // Get color values
        $background_color = isset($_POST['background_color']) ? sanitize_hex_color($_POST['background_color']) : '#ffffff';
        $button_bg = isset($_POST['button_bg']) ? sanitize_hex_color($_POST['button_bg']) : '#0073aa';
        $button_text = isset($_POST['button_text']) ? sanitize_hex_color($_POST['button_text']) : '#ffffff';
        $button_hover_bg = isset($_POST['button_hover_bg']) ? sanitize_hex_color($_POST['button_hover_bg']) : '#005177';
        $name_color = isset($_POST['name_color']) ? sanitize_hex_color($_POST['name_color']) : '#000000';
        $description_color = isset($_POST['description_color']) ? sanitize_hex_color($_POST['description_color']) : '#666666';
        $social_icon_color = isset($_POST['social_icon_color']) ? sanitize_hex_color($_POST['social_icon_color']) : '#000000';
        $icon_border_color = isset($_POST['icon_border_color']) ? sanitize_hex_color($_POST['icon_border_color']) : '#0073aa';
        $button_radius = isset($_POST['button_radius']) ? absint($_POST['button_radius']) : 12;

        // Get font/size values
        $name_font_size = isset($_POST['name_font_size']) ? absint($_POST['name_font_size']) : 52;
        $name_font_weight = isset($_POST['name_font_weight']) ? sanitize_text_field($_POST['name_font_weight']) : '700';
        $description_font_size = isset($_POST['description_font_size']) ? absint($_POST['description_font_size']) : 20;
        $button_font_size = isset($_POST['button_font_size']) ? absint($_POST['button_font_size']) : 16;
        $button_font_weight = isset($_POST['button_font_weight']) ? sanitize_text_field($_POST['button_font_weight']) : '500';

        // Generate CSS
        $css = "/**\n";
        $css .= " * Theme Name: " . $theme_name . "\n";
        $css .= " * Description: Custom theme created with theme editor\n";
        $css .= " * Version: 1.0.0\n";
        $css .= " * Author: Custom\n";
        $css .= " */\n\n";

        $css .= ".twine-container {\n";
        $css .= "    background: " . $background_color . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-icon {\n";
        $css .= "    border: 3px solid " . $icon_border_color . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-name {\n";
        $css .= "    color: " . $name_color . ";\n";
        $css .= "    font-size: " . $name_font_size . "px;\n";
        $css .= "    font-weight: " . $name_font_weight . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-description {\n";
        $css .= "    color: " . $description_color . ";\n";
        $css .= "    font-size: " . $description_font_size . "px;\n";
        $css .= "}\n\n";

        $css .= ".twine-link-button {\n";
        $css .= "    background: " . $button_bg . ";\n";
        $css .= "    color: " . $button_text . ";\n";
        $css .= "    border-radius: " . $button_radius . "px;\n";
        $css .= "    font-size: " . $button_font_size . "px;\n";
        $css .= "    font-weight: " . $button_font_weight . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-link-button:hover {\n";
        $css .= "    background: " . $button_hover_bg . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-social-icon {\n";
        $css .= "    color: " . $social_icon_color . ";\n";
        $css .= "}\n";

        // Check if this is a temporary preview save (key parameter present)
        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';

        if (!empty($key)) {
            // Save to temporary file for preview
            if (!file_exists(TWINE_TEMP_THEMES_DIR)) {
                wp_mkdir_p(TWINE_TEMP_THEMES_DIR);
            }

            $temp_theme_path = TWINE_TEMP_THEMES_DIR . '/theme-' . $key . '.css';
            $result = file_put_contents($temp_theme_path, $css);

            if ($result === false) {
                wp_send_json_error('Failed to save temporary theme file');
            }

            wp_send_json_success(array(
                'message' => 'Temporary theme saved',
                'key' => $key
            ));
        }

        // Regular save - ensure custom themes directory exists
        if (!file_exists(TWINE_CUSTOM_THEMES_DIR)) {
            wp_mkdir_p(TWINE_CUSTOM_THEMES_DIR);
        }

        // Save theme file
        $theme_path = TWINE_CUSTOM_THEMES_DIR . '/' . $theme_slug . '.css';
        $result = file_put_contents($theme_path, $css);

        if ($result === false) {
            wp_die('Failed to save theme file. Please check file permissions.');
        }

        // Clean up all temporary theme files
        if (file_exists(TWINE_TEMP_THEMES_DIR)) {
            $temp_files = glob(TWINE_TEMP_THEMES_DIR . '/theme-*.css');
            foreach ($temp_files as $temp_file) {
                @unlink($temp_file);
            }
        }

        // Redirect back to themes gallery
        wp_redirect(admin_url('admin.php?page=twine-theme-editor&created=1'));
        exit;
    }

    /**
     * Get social media icon SVG
     */
    private function render_header_html($header_links) {
        if (empty($header_links)) {
            return '';
        }

        $left = array();
        $center = array();
        $right = array();
        foreach ($header_links as $item) {
            $align = isset($item['align']) ? $item['align'] : 'center';
            if ($align === 'left') {
                $left[] = $item;
            } elseif ($align === 'right') {
                $right[] = $item;
            } else {
                $center[] = $item;
            }
        }

        $html = '<div class="twine-header">';

        $html .= '<div class="twine-header-group twine-header-left">';
        foreach ($left as $item) {
            $html .= $this->render_header_icon($item);
        }
        $html .= '</div>';

        $html .= '<div class="twine-header-group twine-header-center">';
        foreach ($center as $item) {
            $html .= $this->render_header_icon($item);
        }
        $html .= '</div>';

        $html .= '<div class="twine-header-group twine-header-right">';
        foreach ($right as $item) {
            $html .= $this->render_header_icon($item);
        }
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    private function render_header_icon($item) {
        $icon_html = '';
        if ($item['icon'] === 'custom' && !empty($item['custom_icon'])) {
            $icon_html = '<img src="' . esc_url($item['custom_icon']) . '" alt="" class="twine-custom-icon">';
        } else {
            $icon_html = $this->get_social_icon($item['icon']);
        }
        return '<a href="' . esc_url($item['url']) . '" class="twine-header-icon" target="_blank" rel="noopener noreferrer">' . $icon_html . '</a>';
    }

    private function render_footer_html($footer_links) {
        if (empty($footer_links)) {
            return '';
        }

        $html = '<div class="twine-footer">';
        foreach ($footer_links as $index => $item) {
            if ($index > 0) {
                $html .= '<span class="twine-footer-separator">&bull;</span>';
            }
            $html .= '<a href="' . esc_url($item['url']) . '" class="twine-footer-link" target="_blank" rel="noopener noreferrer">' . esc_html($item['text']) . '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    private function get_social_icon($platform) {
        $icons = array(
            'facebook' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'google' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z"/></svg>',
            'instagram' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>',
            'x' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            'twitter' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>',
            'tiktok' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/></svg>',
            'youtube' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
            'linkedin' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
            'pinterest' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg>',
            'reddit' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-7.004 4.87-3.874 0-7.004-2.176-7.004-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/></svg>',
            'discord' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/></svg>',
            'twitch' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z"/></svg>',
            'spotify' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>',
            'apple-music' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.994 6.124a9.23 9.23 0 00-.24-2.19c-.317-1.31-1.062-2.31-2.18-3.043a5.022 5.022 0 00-1.877-.726 10.496 10.496 0 00-1.564-.15c-.04-.003-.083-.01-.124-.013H5.986c-.152.01-.303.017-.455.026-.747.043-1.49.123-2.193.401-1.336.53-2.3 1.452-2.865 2.78-.192.448-.292.925-.363 1.408-.056.392-.088.785-.1 1.18 0 .032-.007.062-.01.093v12.223c.01.14.017.283.027.424.05.815.154 1.624.497 2.373.65 1.42 1.738 2.353 3.234 2.801.42.127.856.187 1.293.228.555.053 1.11.06 1.667.06h11.03a12.5 12.5 0 001.57-.1c.822-.106 1.596-.35 2.295-.81a5.046 5.046 0 001.88-2.207c.186-.42.293-.87.37-1.324.113-.675.138-1.358.137-2.04-.002-3.8 0-7.595-.003-11.393zm-6.423 3.99v5.712c0 .417-.058.827-.244 1.206-.29.59-.76.962-1.388 1.14-.35.1-.706.157-1.07.173-.95.042-1.785-.49-2.166-1.373-.39-.896-.163-1.98.266-2.59.39-.56.93-.88 1.584-1.002.664-.124 1.332-.09 2.001-.09V9.934c0-.18.01-.18-.18-.143l-6.347 1.2c-.03.006-.062.013-.09.03-.012.008-.024.04-.024.064-.003.63-.003 6.86-.004 7.49 0 .397-.058.79-.24 1.156-.283.57-.745.937-1.36 1.12-.344.104-.7.163-1.062.18-.93.046-1.754-.452-2.15-1.306-.443-.948-.158-2.14.565-2.833.39-.373.872-.586 1.404-.678.674-.117 1.353-.084 2.03-.082.022 0 .042-.013.063-.02v-6.57c0-.292.087-.524.32-.7.174-.13.37-.2.58-.24l7.467-1.412c.084-.016.17-.027.255-.027.32 0 .59.223.59.564v4.38z"/></svg>',
            'soundcloud' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M1.175 12.225c-.051 0-.094.046-.101.1l-.233 2.154.233 2.105c.007.058.05.098.101.098.05 0 .09-.04.099-.098l.255-2.105-.27-2.154c-.009-.06-.052-.1-.1-.1m-.899.828c-.06 0-.091.037-.104.094L0 14.479l.165 1.308c.014.057.045.094.09.094s.089-.037.099-.094l.19-1.308-.19-1.334c-.01-.057-.044-.09-.09-.09m1.83-1.229c-.061 0-.12.045-.12.104l-.21 2.563.225 2.458c0 .06.045.104.106.104.061 0 .12-.044.12-.104l.24-2.458-.24-2.563c0-.06-.059-.104-.12-.104m.945-.089c-.075 0-.135.06-.15.135l-.193 2.64.21 2.544c.016.077.075.138.149.138.075 0 .135-.061.15-.138l.225-2.544-.225-2.64c-.015-.075-.06-.135-.135-.135m.93-.132c-.09 0-.149.075-.164.164l-.18 2.79.195 2.595c.015.09.075.149.165.149s.149-.06.164-.149l.21-2.595-.21-2.79c-.015-.09-.075-.164-.18-.164m1.005-.166c-.104 0-.179.09-.194.194l-.18 2.97.18 2.655c.015.09.09.18.194.18.104 0 .18-.09.18-.18l.195-2.655-.195-2.97c0-.104-.076-.194-.18-.194m.944-.089c-.12 0-.209.104-.224.224l-.165 3.074.165 2.655c.015.12.105.21.225.21.119 0 .209-.09.224-.21l.18-2.655-.18-3.074c-.015-.12-.105-.224-.225-.224m1.005-.165c-.135 0-.239.119-.254.254l-.15 3.27.165 2.685c.015.12.119.239.254.239.12 0 .239-.119.254-.239l.165-2.685-.165-3.27c-.015-.135-.119-.254-.269-.254m.989-.075c-.149 0-.269.135-.284.284l-.134 3.36.149 2.685c.015.135.12.27.284.27.15 0 .27-.135.284-.27l.165-2.685-.165-3.36c-.015-.149-.135-.284-.299-.284m1.096.045c-.164 0-.299.15-.314.314l-.135 3.33.15 2.685c.015.15.149.3.314.3.149 0 .299-.15.314-.3l.165-2.685-.165-3.33c-.015-.165-.165-.314-.329-.314m1.11-.135c-.18 0-.329.165-.344.344l-.12 3.48.135 2.67c.015.165.165.33.344.33.165 0 .33-.165.345-.33l.149-2.67-.149-3.48c-.015-.18-.165-.344-.36-.344m1.065.225c-.195 0-.344.18-.359.359l-.105 3.285.119 2.67c.016.18.165.36.36.36.18 0 .344-.18.359-.36l.12-2.67-.12-3.285c-.015-.18-.164-.359-.374-.359m1.14-.329c-.21 0-.374.18-.39.39l-.104 3.6.12 2.655c.014.195.179.375.389.375.196 0 .375-.18.391-.375l.119-2.655-.12-3.6c-.015-.21-.18-.39-.405-.39m1.124-.028c-.209 0-.389.194-.404.404l-.09 3.614.104 2.655c.016.21.196.39.405.39.21 0 .39-.18.405-.39l.12-2.655-.12-3.614c-.016-.21-.196-.404-.42-.404m1.215-.404c-.225 0-.405.21-.42.42l-.09 4.005.105 2.655c.015.225.195.42.42.42.209 0 .405-.195.42-.42l.105-2.655-.105-4.005c-.015-.21-.21-.42-.435-.42m1.125.375c-.24 0-.42.225-.435.45l-.074 3.614.089 2.64c.016.24.195.435.435.435.225 0 .42-.195.435-.435l.105-2.64-.105-3.614c-.015-.24-.21-.45-.45-.45m1.17-.614c-.254 0-.449.24-.464.479l-.075 4.26.09 2.624c.015.255.21.48.465.48.24 0 .449-.225.464-.48l.105-2.624-.105-4.26c-.015-.239-.225-.479-.48-.479m1.065.614c-.27 0-.465.255-.48.51l-.06 3.63.075 2.625c.015.255.21.495.48.495.255 0 .465-.24.48-.495l.09-2.625-.09-3.63c-.015-.255-.225-.51-.495-.51m1.155-.404c-.285 0-.495.27-.51.54l-.06 4.065.075 2.61c.015.27.225.51.51.51.27 0 .495-.24.51-.51l.09-2.61-.09-4.065c-.015-.27-.24-.54-.525-.54m1.11.449c-.3 0-.51.284-.525.569l-.045 3.6.06 2.61c.015.285.225.54.525.54.285 0 .51-.255.525-.54l.075-2.61-.075-3.6c-.015-.285-.24-.57-.54-.57m1.185-.584c-.314 0-.539.3-.554.585l-.045 4.185.06 2.595c.015.3.24.57.555.57.3 0 .54-.27.555-.57l.075-2.595-.075-4.185c-.015-.285-.255-.585-.57-.585m1.065.449c-.045 0-.075-.015-.105-.015-.3 0-.524.285-.539.585l-.03 3.75.045 2.58c.015.315.24.585.555.585.03 0 .06 0 .09-.015.255-.03.45-.27.465-.555l.06-2.595-.06-3.75c-.015-.3-.24-.57-.48-.57m2.61-.135a3.58 3.58 0 00-1.53.345c-.285-3.285-3.015-5.864-6.359-5.864-1.68 0-3.21.66-4.215 1.545-.42.36-.465.66-.465.99v10.77c.015.345.255.645.585.705.06.015 7.83.015 11.925.015 1.95 0 3.54-1.59 3.54-3.555 0-1.965-1.575-3.555-3.54-3.555"/></svg>',
            'telegram' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
            'whatsapp' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>',
            'threads' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.59 12c.025 3.086.718 5.496 2.057 7.164 1.432 1.781 3.632 2.695 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.96-.065-1.182.408-2.256 1.33-3.022.898-.745 2.176-1.18 3.792-1.29.492-.034.964-.045 1.414-.032-.087-.467-.264-.883-.554-1.207-.467-.523-1.205-.798-2.196-.817l-.076-.002c-.752 0-1.69.212-2.376.726l-1.148-1.696c.858-.608 2.108-1.074 3.476-1.15l.18-.005c1.594 0 2.895.533 3.763 1.542.651.756 1.05 1.727 1.196 2.878.504.103.977.238 1.419.408 2.14.825 3.386 2.396 3.508 4.428.073 1.214-.274 2.43-1.012 3.54-1.09 1.64-2.937 2.726-5.353 3.147-1.056.183-2.146.245-3.086.112-1.042-.147-1.976-.478-2.724-.953l1.06-1.788c1.005.552 2.371.801 3.85.574 1.725-.266 3.056-1.06 3.837-2.292.485-.764.69-1.564.637-2.304-.097-1.355-1.115-2.176-2.322-2.622a7.79 7.79 0 00-.595-.192c-.049.807-.174 1.55-.377 2.224-.352 1.171-.938 2.15-1.746 2.91-1.138 1.071-2.636 1.633-4.337 1.633-.3 0-.608-.015-.918-.048-1.44-.15-2.717-.682-3.593-1.5-.843-.789-1.313-1.811-1.358-2.959-.045-1.13.347-2.191 1.137-3.074.88-.983 2.233-1.624 3.918-1.858.462-.064.951-.1 1.467-.108a15.03 15.03 0 012.016.086c.074-.428.105-.878.096-1.344l-.006-.208h2.136l.014.316c.025.585-.008 1.144-.1 1.68.337.074.67.163.994.266 1.395.442 2.465 1.205 3.105 2.208.757 1.19 1.01 2.593.735 4.072-.454 2.447-2.247 4.142-5.049 4.772-1.006.226-2.135.333-3.298.314zm-1.21-8.03c-1.07.066-1.892.35-2.435.838-.38.34-.568.742-.549 1.163.022.41.233.78.629 1.067.509.37 1.247.576 2.08.576.025 0 .05 0 .074-.001 1.124-.057 1.99-.455 2.575-1.182.47-.585.763-1.387.877-2.393-.922-.094-2.06-.13-3.25-.068z"/></svg>',
            'bluesky' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.039.415-.056-.138.022-.276.04-.415.056-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 01-.415-.056c.14.017.279.036.415.056 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.478 0-.69-.139-1.861-.902-2.206-.659-.298-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8z"/></svg>',
            'mastodon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 00.023-.043v-1.809a.052.052 0 00-.02-.041.053.053 0 00-.046-.01 20.282 20.282 0 01-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 01-.319-1.433.053.053 0 01.066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.67 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z"/></svg>',
            'patreon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M0 .48v23.04h4.22V.48zm15.385 0c-4.764 0-8.641 3.88-8.641 8.65 0 4.755 3.877 8.623 8.641 8.623 4.75 0 8.615-3.868 8.615-8.623C24 4.36 20.136.48 15.385.48z"/></svg>',
            'ko-fi' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.881 8.948c-.773-4.085-4.859-4.593-4.859-4.593H.723c-.604 0-.679.798-.679.798s-.082 7.324-.022 11.822c.164 2.424 2.586 2.672 2.586 2.672s8.267-.023 11.966-.049c2.438-.426 2.683-2.566 2.658-3.734 4.352.24 7.422-2.831 6.649-6.916zm-11.062 3.511c-1.246 1.453-4.011 3.976-4.011 3.976s-.121.119-.31.023c-.076-.057-.108-.09-.108-.09-.443-.441-3.368-3.049-4.034-3.954-.709-.965-1.041-2.7-.091-3.71.951-1.01 3.005-1.086 4.363.407 0 0 1.565-1.782 3.468-.963 1.904.82 1.832 3.011.723 4.311zm6.173.478c-.928.116-1.682.028-1.682.028V7.284h1.77s1.971.551 1.971 2.638c0 1.913-.985 2.667-2.059 3.015z"/></svg>',
            'buymeacoffee' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.216 6.415l-.132-.666c-.119-.598-.388-1.163-1.001-1.379-.197-.069-.42-.098-.57-.241-.152-.143-.196-.366-.231-.572-.065-.378-.125-.756-.192-1.133-.057-.325-.102-.69-.25-.987-.195-.4-.597-.634-.996-.788a5.723 5.723 0 00-.626-.194c-1-.263-2.05-.36-3.077-.416a25.834 25.834 0 00-3.7.062c-.915.083-1.88.184-2.75.5-.318.116-.646.256-.888.501-.297.302-.393.77-.177 1.146.154.267.415.456.692.58.36.162.737.284 1.123.366 1.075.238 2.189.331 3.287.37 1.218.05 2.437.01 3.65-.118.299-.033.598-.073.896-.119.352-.054.578-.513.474-.834-.124-.383-.457-.531-.834-.473-.466.074-.96.108-1.382.146-1.177.08-2.358.082-3.536.006a22.228 22.228 0 01-1.157-.107c-.086-.01-.18-.025-.258-.036-.243-.036-.484-.08-.724-.13-.111-.027-.111-.185 0-.212h.005c.277-.06.557-.108.838-.147h.002c.131-.009.263-.032.394-.048a25.076 25.076 0 013.426-.12c.674.019 1.347.067 2.017.144l.228.031c.267.04.533.088.798.145.392.085.895.113 1.07.542.055.137.08.288.111.431l.319 1.484a.237.237 0 01-.199.284h-.003c-.037.006-.075.01-.112.015a36.704 36.704 0 01-4.743.295 37.059 37.059 0 01-4.699-.304c-.14-.017-.293-.042-.417-.06-.326-.048-.649-.108-.973-.161-.393-.065-.768-.032-1.123.161-.29.16-.527.404-.675.701-.154.316-.199.66-.267 1-.069.34-.176.707-.135 1.056.087.753.613 1.365 1.37 1.502a39.69 39.69 0 0011.343.376.483.483 0 01.535.53l-.071.697-1.018 9.907c-.041.41-.047.832-.125 1.237-.122.637-.553 1.028-1.182 1.171-.577.131-1.165.2-1.756.205-.656.004-1.31-.025-1.966-.022-.699.004-1.556-.06-2.095-.58-.475-.458-.54-1.174-.605-1.793l-.731-7.013-.322-3.094c-.037-.351-.286-.695-.678-.678-.336.015-.718.3-.678.679l.228 2.185.949 9.112c.147 1.344 1.174 2.068 2.446 2.272.742.12 1.503.144 2.257.156.966.016 1.942.053 2.892-.122 1.408-.258 2.465-1.198 2.616-2.657.34-3.332.683-6.663 1.024-9.995l.215-2.087a.484.484 0 01.39-.426c.402-.078.787-.212 1.074-.518.455-.488.546-1.124.385-1.766zm-1.478.772c-.145.137-.363.201-.578.233-2.416.359-4.866.54-7.308.46-1.748-.06-3.477-.254-5.207-.498-.17-.024-.353-.055-.47-.18-.22-.236-.111-.71-.054-.995.052-.26.152-.609.463-.646.484-.057 1.046.148 1.526.22.577.088 1.156.159 1.737.212 2.48.226 5.002.19 7.472-.14.45-.06.899-.13 1.345-.21.399-.072.84-.206 1.08.206.166.281.188.657.162.974a.544.544 0 01-.169.364zm-6.159 3.9c-.862.37-1.84.788-3.109.788a5.884 5.884 0 01-1.569-.217l.877 9.004c.065.78.717 1.38 1.5 1.38 0 0 1.243.065 1.658.065.447 0 1.786-.065 1.786-.065.783 0 1.434-.6 1.499-1.38l.94-9.95a3.996 3.996 0 00-1.322-.238c-.826 0-1.491.284-2.26.613z"/></svg>',
            'github' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>',
            'snapchat' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12.206.793c.99 0 4.347.276 5.93 3.821.529 1.193.403 3.219.299 4.847l-.003.06c-.012.18-.022.345-.03.51.075.045.203.09.401.09.3-.016.659-.12 1.033-.301a.32.32 0 01.114-.023.44.44 0 01.323.137c.095.1.138.234.12.369-.035.27-.215.51-.51.642-.09.045-.195.075-.315.105-.03.015-.09.03-.165.045-.18.045-.42.105-.57.195-.16.1-.255.21-.285.36-.015.105-.015.21.015.315.09.36.255.645.39.9.33.555.675 1.125.975 1.755.49.645 1.095 1.065 1.635 1.32.165.075.33.135.45.18.135.45.255.09.315.105l.045.015c.69.21 1.02.555 1.02.87 0 .24-.135.465-.45.66-.39.24-.96.39-1.74.465-.12.015-.195.06-.24.105-.045.06-.06.12-.075.195-.015.045-.03.105-.06.165-.04.1-.065.175-.085.245-.02.07-.038.148-.063.24-.025.092-.06.18-.09.27-.045.105-.15.195-.315.195a2.3 2.3 0 01-.345-.03 7.003 7.003 0 00-1.07-.063c-.33 0-.69.03-1.08.09-.255.045-.525.15-.855.3-.555.21-1.065.435-1.62.435h-.075c-.555 0-1.08-.225-1.635-.435-.315-.15-.585-.255-.855-.3-.39-.06-.75-.09-1.08-.09-.39 0-.72.03-1.05.063a2.3 2.3 0 01-.36.03c-.15 0-.27-.09-.315-.195-.045-.12-.075-.24-.12-.36-.03-.075-.045-.135-.06-.165-.03-.06-.045-.12-.075-.195-.045-.045-.12-.09-.24-.105-.78-.075-1.35-.225-1.74-.465-.315-.195-.45-.42-.45-.66 0-.315.33-.66 1.02-.87l.045-.015c.06-.015.18-.06.315-.105a3.44 3.44 0 00.465-.18c.525-.24 1.11-.675 1.62-1.305.315-.615.66-1.2.975-1.755.135-.255.3-.54.39-.9.03-.105.03-.21.015-.315-.03-.15-.135-.255-.285-.36-.135-.09-.39-.15-.57-.195-.075-.015-.135-.03-.165-.045-.12-.03-.225-.06-.315-.105-.295-.132-.475-.372-.51-.643-.02-.135.025-.27.12-.37a.45.45 0 01.323-.137.32.32 0 01.114.023c.36.18.72.3 1.035.301.195 0 .315-.045.39-.09l-.03-.51-.003-.06c-.105-1.63-.23-3.655.3-4.848C7.84 1.069 11.2.793 12.2.793z"/></svg>',
            'vimeo' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.977 6.416c-.105 2.338-1.739 5.543-4.894 9.609-3.268 4.247-6.026 6.37-8.29 6.37-1.409 0-2.578-1.294-3.553-3.881L5.322 11.4C4.603 8.816 3.834 7.522 3.01 7.522c-.179 0-.806.378-1.881 1.132L0 7.197c1.185-1.044 2.351-2.084 3.501-3.128C5.08 2.701 6.266 1.984 7.055 1.91c1.867-.18 3.016 1.1 3.447 3.838.465 2.953.789 4.789.971 5.507.539 2.45 1.131 3.674 1.776 3.674.502 0 1.256-.796 2.265-2.385 1.004-1.589 1.54-2.797 1.612-3.628.144-1.371-.395-2.061-1.614-2.061-.574 0-1.167.121-1.777.391 1.186-3.868 3.434-5.757 6.762-5.637 2.473.06 3.628 1.664 3.493 4.797l-.013.01z"/></svg>',
            'dribbble' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 24C5.385 24 0 18.615 0 12S5.385 0 12 0s12 5.385 12 12-5.385 12-12 12zm10.12-10.358c-.35-.11-3.17-.953-6.384-.438 1.34 3.684 1.887 6.684 1.992 7.308 2.3-1.555 3.936-4.02 4.395-6.87zm-6.115 7.808c-.153-.9-.75-4.032-2.19-7.77l-.066.02c-5.79 2.015-7.86 6.025-8.04 6.4 1.73 1.358 3.92 2.166 6.29 2.166 1.42 0 2.77-.29 4-.814zm-11.62-2.58c.232-.4 3.045-5.055 8.332-6.765.135-.045.27-.084.405-.12-.26-.585-.54-1.167-.832-1.74C7.17 11.775 2.206 11.71 1.756 11.7l-.004.312c0 2.633.998 5.037 2.634 6.855zm-2.42-8.955c.46.008 4.683.026 9.477-1.248-1.698-3.018-3.53-5.558-3.8-5.928-2.868 1.35-5.01 3.99-5.676 7.17zM9.6 2.052c.282.38 2.145 2.914 3.822 6 3.645-1.365 5.19-3.44 5.373-3.702-1.81-1.61-4.19-2.586-6.795-2.586-.825 0-1.63.1-2.4.285zm10.335 3.483c-.218.29-1.935 2.493-5.724 4.04.24.49.47.985.68 1.486.08.18.15.36.22.53 3.41-.43 6.8.26 7.14.33-.02-2.42-.88-4.64-2.31-6.38z"/></svg>',
            'behance' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M6.938 4.503c.702 0 1.34.06 1.92.188.577.13 1.07.33 1.485.61.41.28.733.65.96 1.12.225.47.34 1.05.34 1.73 0 .74-.17 1.36-.507 1.86-.338.5-.837.9-1.502 1.22.906.26 1.576.72 2.022 1.37.448.66.665 1.45.665 2.36 0 .75-.13 1.39-.41 1.93-.28.55-.67 1-1.16 1.35-.48.348-1.05.6-1.67.767-.61.165-1.252.254-1.91.254H0V4.51h6.938v-.007zM6.545 9.8c.568 0 1.053-.13 1.45-.41.395-.27.59-.72.59-1.34 0-.35-.06-.64-.19-.87-.13-.23-.3-.42-.52-.55-.21-.13-.47-.22-.76-.27-.29-.05-.6-.08-.94-.08H3.484V9.8h3.06zm.25 5.88c.39 0 .77-.04 1.12-.13.36-.09.67-.24.94-.44.27-.2.48-.46.64-.79.16-.33.24-.74.24-1.22 0-.97-.27-1.67-.81-2.09-.54-.42-1.26-.63-2.15-.63H3.48v5.3h3.32zm8.595-7.19h5.61v1.3h-5.61v-1.3zm3.03 8.15c.41.47.98.71 1.72.71.52 0 .97-.13 1.35-.39.38-.26.62-.55.73-.87h2.42c-.38 1.2-.97 2.06-1.76 2.59-.79.54-1.74.8-2.86.8-.78 0-1.48-.13-2.1-.39-.63-.26-1.16-.62-1.59-1.08-.43-.46-.76-1-.99-1.65-.23-.64-.34-1.35-.34-2.12 0-.75.12-1.44.35-2.08.23-.64.56-1.19 1-1.66.43-.47.96-.84 1.57-1.1.61-.26 1.29-.39 2.05-.39.85 0 1.59.15 2.22.46.63.3 1.15.72 1.55 1.27.4.54.7 1.17.89 1.9.19.71.26 1.49.22 2.31h-7.23c.04.91.31 1.63.72 2.12v.01zm3.35-5.09c-.34-.4-.86-.61-1.55-.61-.45 0-.84.08-1.15.24-.31.16-.56.36-.75.59-.19.23-.33.48-.41.76-.08.28-.14.55-.16.81h4.69c-.1-.74-.35-1.38-.68-1.79h.01z"/></svg>',
            'medium' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M13.54 12a6.8 6.8 0 01-6.77 6.82A6.8 6.8 0 010 12a6.8 6.8 0 016.77-6.82A6.8 6.8 0 0113.54 12zM20.96 12c0 3.54-1.51 6.42-3.38 6.42-1.87 0-3.39-2.88-3.39-6.42s1.52-6.42 3.39-6.42 3.38 2.88 3.38 6.42M24 12c0 3.17-.53 5.75-1.19 5.75-.66 0-1.19-2.58-1.19-5.75s.53-5.75 1.19-5.75C23.47 6.25 24 8.83 24 12z"/></svg>',
            'substack' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M22.539 8.242H1.46V5.406h21.08v2.836zM1.46 10.812V24L12 18.11 22.54 24V10.812H1.46zM22.54 0H1.46v2.836h21.08V0z"/></svg>',
            'stackoverflow' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M15.725 0l-1.72 1.277 6.39 8.588 1.72-1.277L15.725 0zm-3.94 3.418l-1.369 1.644 8.225 6.85 1.369-1.644-8.225-6.85zm-3.15 4.465l-.905 1.94 9.702 4.517.904-1.94-9.701-4.517zm-1.85 4.86l-.44 2.093 10.473 2.201.44-2.092-10.473-2.203zM1.89 15.47V24h19.19v-8.53h-2.133v6.397H4.021v-6.396H1.89zm4.265 2.133v2.13h10.66v-2.13H6.154Z"/></svg>',
            'email' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M24 5.457v13.909c0 .904-.732 1.636-1.636 1.636h-3.819V11.73L12 16.64l-6.545-4.91v9.273H1.636A1.636 1.636 0 0 1 0 19.366V5.457c0-2.023 2.309-3.178 3.927-1.964L5.455 4.64 12 9.548l6.545-4.91 1.528-1.145C21.69 2.28 24 3.434 24 5.457z"/></svg>',
            'phone' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>',
            'website' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm9.5 12c0 1.657-.425 3.214-1.173 4.576l-1.025-.396c-.494-.191-.842-.586-1.025-1.071l-.399-1.057c-.084-.224-.136-.458-.157-.696l-.073-.848a2.118 2.118 0 0 0-.589-1.318l-1.05-1.05a2.113 2.113 0 0 0-1.497-.62h-.9a2.118 2.118 0 0 0-1.5.621l-.9.9c-.4.4-.621.943-.621 1.5v1.2c0 .233.038.465.114.686l.457 1.371c.115.345.173.708.173 1.074v.729a2.117 2.117 0 0 0 1.06 1.833l.64.366c.212.121.451.184.693.184h1.5c.829 0 1.5-.671 1.5-1.5v-1.2c0-.398.158-.779.439-1.061l.9-.9a2.113 2.113 0 0 1 1.497-.62h.364c.133 0 .266.013.396.038a9.511 9.511 0 0 1-8.139 6.673v-.711c0-.828-.672-1.5-1.5-1.5h-1.2a2.113 2.113 0 0 0-2.121 2.121c0 .233.038.465.114.686l.057.171c.115.345.173.708.173 1.074v.193A9.484 9.484 0 0 1 2.5 12C2.5 6.752 6.701 2.5 12 2.5c5.299 0 9.5 4.252 9.5 9.5z"/></svg>',
            'link' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>',
            'home' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>',
            'search' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
            'shop' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>',
            'cart' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>',
            'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/></svg>',
            'location' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
            'map' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>',
            'star' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>',
            'heart' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>',
            'bookmark' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>',
            'camera' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="3.2"/><path d="M9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/></svg>',
            'music' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>',
            'video' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>',
            'mic' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"/></svg>',
            'podcast' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1c-4.97 0-9 4.03-9 9v7c0 1.66 1.34 3 3 3h3v-8H5v-2c0-3.87 3.13-7 7-7s7 3.13 7 7v2h-4v8h3c1.66 0 3-1.34 3-3v-7c0-4.97-4.03-9-9-9z"/></svg>',
            'book' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 4h5v8l-2.5-1.5L6 12V4z"/></svg>',
            'document' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>',
            'download' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
            'share' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>',
            'gift' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20 6h-2.18c.11-.31.18-.65.18-1 0-1.66-1.34-3-3-3-1.05 0-1.96.54-2.5 1.35l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zM9 4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm11 15H4v-2h16v2zm0-5H4V8h5.08L7 10.83 8.62 12 11 8.76l1-1.36 1 1.36L15.38 12 17 10.83 14.92 8H20v6z"/></svg>',
            'ticket' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M22 10V6c0-1.11-.9-2-2-2H4c-1.1 0-1.99.89-1.99 2v4c1.1 0 1.99.9 1.99 2s-.89 2-2 2v4c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2v-4c-1.1 0-2-.9-2-2s.9-2 2-2zm-2-1.46c-1.19.69-2 1.99-2 3.46s.81 2.77 2 3.46V18H4v-2.54c1.19-.69 2-1.99 2-3.46 0-1.48-.81-2.77-2-3.46V6h16v2.54z"/></svg>',
            'restaurant' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4z"/></svg>',
            'coffee' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20 3H4v10c0 2.21 1.79 4 4 4h6c2.21 0 4-1.79 4-4v-3h2c1.11 0 2-.89 2-2V5c0-1.11-.89-2-2-2zm0 5h-2V5h2v3zM2 21h18v-2H2v2z"/></svg>',
            'fitness' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.57 14.86L22 13.43 20.57 12 17 15.57 8.43 7 12 3.43 10.57 2 9.14 3.43 7.71 2 5.57 4.14 4.14 2.71 2.71 4.14l1.43 1.43L2 7.71l1.43 1.43L2 10.57 3.43 12 7 8.43 15.57 17 12 20.57 13.43 22l1.43-1.43L16.29 22l2.14-2.14 1.43 1.43 1.43-1.43-1.43-1.43L22 16.29z"/></svg>',
            'flash' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M7 2v11h3v9l7-12h-4l4-8z"/></svg>',
            'fire' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M13.5.67s.74 2.65.74 4.8c0 2.06-1.35 3.73-3.41 3.73-2.07 0-3.63-1.67-3.63-3.73l.03-.36C5.21 7.51 4 10.62 4 14c0 4.42 3.58 8 8 8s8-3.58 8-8C20 8.61 17.41 3.8 13.5.67zM11.71 19c-1.78 0-3.22-1.4-3.22-3.14 0-1.62 1.05-2.76 2.81-3.12 1.77-.36 3.6-1.21 4.62-2.58.39 1.29.59 2.65.59 4.04 0 2.65-2.15 4.8-4.8 4.8z"/></svg>',
            'crown' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M2 19h20v3H2zM2 5l5 5 5-7 5 7 5-5v12H2z"/></svg>',
            'trophy' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V19H7v2h10v-2h-4v-3.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/></svg>',
            'palette' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10c1.38 0 2.5-1.12 2.5-2.5 0-.61-.23-1.2-.64-1.67-.08-.1-.13-.21-.13-.33 0-.28.22-.5.5-.5H16c3.31 0 6-2.69 6-6 0-4.96-4.49-9-10-9zm-5.5 9c-.83 0-1.5-.67-1.5-1.5S5.67 8 6.5 8 8 8.67 8 9.5 7.33 11 6.5 11zm3-4C8.67 7 8 6.33 8 5.5S8.67 4 9.5 4s1.5.67 1.5 1.5S10.33 7 9.5 7zm5 0c-.83 0-1.5-.67-1.5-1.5S13.67 4 14.5 4s1.5.67 1.5 1.5S15.33 7 14.5 7zm3 4c-.83 0-1.5-.67-1.5-1.5S16.67 8 17.5 8s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>',
            'brush' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M7 14c-1.66 0-3 1.34-3 3 0 1.31-1.16 2-2 2 .92 1.22 2.49 2 4 2 2.21 0 4-1.79 4-4 0-1.66-1.34-3-3-3zm13.71-9.37l-1.34-1.34c-.39-.39-1.02-.39-1.41 0L9 12.25 11.75 15l8.96-8.96c.39-.39.39-1.02 0-1.41z"/></svg>',
            'school' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>',
            'work' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg>',
            'code' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>',
            'terminal' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V8h16v10zm-2-1h-6v-2h6v2zM7.5 17l-1.41-1.41L8.67 13l-2.59-2.59L7.5 9l4 4-4 4z"/></svg>',
            'globe' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zm6.93 6h-2.95c-.32-1.25-.78-2.45-1.38-3.56 1.84.63 3.37 1.91 4.33 3.56zM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96zM4.26 14C4.1 13.36 4 12.69 4 12s.1-1.36.26-2h3.38c-.08.66-.14 1.32-.14 2 0 .68.06 1.34.14 2H4.26zm.82 2h2.95c.32 1.25.78 2.45 1.38 3.56-1.84-.63-3.37-1.9-4.33-3.56zm2.95-8H5.08c.96-1.66 2.49-2.93 4.33-3.56C8.81 5.55 8.35 6.75 8.03 8zM12 19.96c-.83-1.2-1.48-2.53-1.91-3.96h3.82c-.43 1.43-1.08 2.76-1.91 3.96zM14.34 14H9.66c-.09-.66-.16-1.32-.16-2 0-.68.07-1.35.16-2h4.68c.09.65.16 1.32.16 2 0 .68-.07 1.34-.16 2zm.25 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95c-.96 1.65-2.49 2.93-4.33 3.56zM16.36 14c.08-.66.14-1.32.14-2 0-.68-.06-1.34-.14-2h3.38c.16.64.26 1.31.26 2s-.1 1.36-.26 2h-3.38z"/></svg>',
            'rss' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="6.18" cy="17.82" r="2.18"/><path d="M4 4.44v2.83c7.03 0 12.73 5.7 12.73 12.73h2.83c0-8.59-6.97-15.56-15.56-15.56zm0 5.66v2.83c3.9 0 7.07 3.17 7.07 7.07h2.83c0-5.47-4.43-9.9-9.9-9.9z"/></svg>',
            'lock' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>',
            'verified' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>',
            'notification' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>',
            'chat' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/></svg>',
            'people' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
            'user' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
            'settings' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>',
            'info' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>',
            'help' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>',
            'menu' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>',
            'grid' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M4 8h4V4H4v4zm6 12h4v-4h-4v4zm-6 0h4v-4H4v4zm0-6h4v-4H4v4zm6 0h4v-4h-4v4zm6-10v4h4V4h-4zm-6 4h4V4h-4v4zm6 6h4v-4h-4v4zm0 6h4v-4h-4v4z"/></svg>',
            'sun' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M6.76 4.84l-1.8-1.79-1.41 1.41 1.79 1.79 1.42-1.41zM4 10.5H1v2h3v-2zm9-9.95h-2V3.5h2V.55zm7.45 3.91l-1.41-1.41-1.79 1.79 1.41 1.41 1.79-1.79zm-3.21 13.7l1.79 1.8 1.41-1.41-1.8-1.79-1.4 1.4zM20 10.5v2h3v-2h-3zm-8-5c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6-2.69-6-6-6zm-1 16.95h2V19.5h-2v2.95zm-7.45-3.91l1.41 1.41 1.79-1.8-1.41-1.41-1.79 1.8z"/></svg>',
            'moon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-2.98 0-5.4-2.42-5.4-5.4 0-1.81.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>',
            'cloud' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/></svg>',
            'dollar' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>',
            'bitcoin' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M14.24 10.56C13.93 8.7 12.26 8.04 10.14 7.85V5.73h-1.41v2.08c-.37 0-.75.01-1.13.02V5.73H6.19v2.15c-.31.01-.61.01-.92.01v-.01H3.49l.29 1.61s1.04-.02 1.02 0c.57 0 .76.33.81.62v2.64c.04 0 .09.01.14.02h-.14v3.71c-.02.18-.12.47-.51.47.02.02-1.02 0-1.02 0L4 18.54h1.71c.32 0 .63.01.94.01v2.15h1.41V18.6c.39.01.77.01 1.14.01v2.09h1.41v-2.16c2.37-.14 4.03-.73 4.24-2.95.17-1.79-.67-2.59-2.01-2.91.82-.37 1.34-1.05 1.4-2.12zm-2.38 4.72c0 1.75-3 1.55-3.96 1.55v-3.1c.96 0 3.96-.27 3.96 1.55zm-.49-4.02c0 1.59-2.5 1.41-3.29 1.41v-2.81c.79 0 3.29-.25 3.29 1.4z"/></svg>',
            'airplane' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>',
            'car' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>',
            'pet' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="4.5" cy="9.5" r="2.5"/><circle cx="9" cy="5.5" r="2.5"/><circle cx="15" cy="5.5" r="2.5"/><circle cx="19.5" cy="9.5" r="2.5"/><path d="M17.34 14.86c-.87-1.02-1.6-1.89-2.48-2.91-.46-.54-1.17-.86-1.95-.86h-1.82c-.78 0-1.49.32-1.95.86-.88 1.02-1.61 1.89-2.48 2.91-1.31 1.31-2.92 2.76-2.62 4.79.29 1.02 1.02 2.03 2.33 2.32.73.17 3.49.32 5.63.32s4.9-.15 5.63-.32c1.31-.29 2.04-1.31 2.33-2.32.31-2.04-1.3-3.49-2.62-4.79z"/></svg>'
        );

        return isset($icons[$platform]) ? $icons[$platform] : '';
    }

    /**
     * Render shortcode
     */
    public function render_shortcode($atts) {
        // Parse shortcode attributes
        $atts = shortcode_atts(array(
            'theme' => ''
        ), $atts, 'twine');

        $links = $this->get_links();
        $icon = $this->get_icon();
        $name = $this->get_name();
        $description = $this->get_description();
        $social = $this->get_social();
        $header_links = $this->get_header_links();
        $footer_links = $this->get_footer_links();

        // Use theme from shortcode parameter if provided, otherwise use saved theme
        $theme = !empty($atts['theme']) ? sanitize_text_field($atts['theme']) : $this->get_theme();

        // Enqueue theme CSS if specified
        if (!empty($theme)) {
            // Check custom themes first, then built-in themes
            $custom_theme_file = TWINE_CUSTOM_THEMES_DIR . '/' . $theme . '.css';
            $plugin_theme_file = TWINE_PLUGIN_DIR . 'themes/' . $theme . '.css';

            if (file_exists($custom_theme_file)) {
                wp_enqueue_style('twine-theme-override', TWINE_CUSTOM_THEMES_URL . '/' . $theme . '.css', array('twine-frontend'), TWINE_VERSION);
            } elseif (file_exists($plugin_theme_file)) {
                wp_enqueue_style('twine-theme-override', TWINE_PLUGIN_URL . 'themes/' . $theme . '.css', array('twine-frontend'), TWINE_VERSION);
            }
        }

        if (empty($links)) {
            return '<p class="twine-empty">No links have been added yet.</p>';
        }

        ob_start();
        ?>
        <div class="twine-container">
            <?php echo $this->render_header_html($header_links); ?>
            <?php if ($icon || $name || $description): ?>
                <div class="twine-profile">
                    <?php if ($icon): ?>
                        <div class="twine-icon">
                            <img src="<?php echo esc_url($icon); ?>" alt="<?php echo esc_attr($name); ?>">
                        </div>
                    <?php endif; ?>
                    <?php if ($name): ?>
                        <h1 class="twine-name"><?php echo esc_html($name); ?></h1>
                    <?php endif; ?>
                    <?php if ($description): ?>
                        <p class="twine-description"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="twine-links">
                <?php foreach ($links as $link): ?>
                    <a href="<?php echo esc_url($link['url']); ?>"
                       class="twine-link-button<?php echo !empty($link['image']) ? ' has-image' : ''; ?>"
                       target="_blank"
                       rel="noopener noreferrer">
                        <?php if (!empty($link['image'])): ?>
                            <img src="<?php echo esc_url($link['image']); ?>" alt="" class="twine-link-image">
                        <?php endif; ?>
                        <span class="twine-link-text"><?php echo esc_html($link['text']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($social)): ?>
                <?php
                // Detect duplicate icons to show labels (custom icons use URL as key)
                $icon_counts = array();
                foreach ($social as $item) {
                    if (!empty($item['url'])) {
                        $icon_key = ($item['icon'] === 'custom' && !empty($item['custom_icon'])) ? 'custom:' . $item['custom_icon'] : $item['icon'];
                        $icon_counts[$icon_key] = isset($icon_counts[$icon_key]) ? $icon_counts[$icon_key] + 1 : 1;
                    }
                }
                $duplicate_icons = array_filter($icon_counts, function($count) { return $count > 1; });

                // Separate into regular and labeled icons
                $regular_icons = array();
                $labeled_icons = array();
                foreach ($social as $item) {
                    if (!empty($item['url'])) {
                        $icon_key = ($item['icon'] === 'custom' && !empty($item['custom_icon'])) ? 'custom:' . $item['custom_icon'] : $item['icon'];
                        if (isset($duplicate_icons[$icon_key])) {
                            $labeled_icons[] = $item;
                        } else {
                            $regular_icons[] = $item;
                        }
                    }
                }
                ?>
                <?php if (!empty($regular_icons)): ?>
                <div class="twine-social">
                    <?php foreach ($regular_icons as $item): ?>
                        <a href="<?php echo esc_url($item['url']); ?>"
                           class="twine-social-icon"
                           target="_blank"
                           rel="noopener noreferrer"
                           aria-label="<?php echo esc_attr($item['name']); ?>">
                            <?php if ($item['icon'] === 'custom' && !empty($item['custom_icon'])): ?>
                                <img src="<?php echo esc_url($item['custom_icon']); ?>" alt="<?php echo esc_attr($item['name']); ?>" class="twine-custom-icon">
                            <?php else: ?>
                                <?php echo $this->get_social_icon($item['icon']); ?>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($labeled_icons)): ?>
                <?php
                // Group labeled icons by their icon type
                $grouped_icons = array();
                foreach ($labeled_icons as $item) {
                    $icon_key = ($item['icon'] === 'custom' && !empty($item['custom_icon'])) ? 'custom:' . $item['custom_icon'] : $item['icon'];
                    if (!isset($grouped_icons[$icon_key])) {
                        $grouped_icons[$icon_key] = array('icon' => $item['icon'], 'custom_icon' => isset($item['custom_icon']) ? $item['custom_icon'] : '', 'items' => array());
                    }
                    $grouped_icons[$icon_key]['items'][] = $item;
                }
                ?>
                <div class="twine-social twine-social-labeled">
                    <?php foreach ($grouped_icons as $group): ?>
                        <div class="twine-social-group">
                            <span class="twine-social-icon twine-social-icon-static">
                                <?php if ($group['icon'] === 'custom' && !empty($group['custom_icon'])): ?>
                                    <img src="<?php echo esc_url($group['custom_icon']); ?>" alt="" class="twine-custom-icon">
                                <?php else: ?>
                                    <?php echo $this->get_social_icon($group['icon']); ?>
                                <?php endif; ?>
                            </span>
                            <?php foreach ($group['items'] as $item): ?>
                                <a href="<?php echo esc_url($item['url']); ?>"
                                   class="twine-social-text-link"
                                   target="_blank"
                                   rel="noopener noreferrer">
                                    <?php echo esc_html($item['name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php echo $this->render_footer_html($footer_links); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for deleting themes
     */
    public function ajax_delete_theme() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'twine_save_links')) {
            wp_send_json_error('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $theme_slug = isset($_POST['theme_slug']) ? sanitize_text_field($_POST['theme_slug']) : '';
        if (empty($theme_slug)) {
            wp_send_json_error('Theme slug is required');
        }

        // Only allow deleting custom themes
        $theme_path = TWINE_CUSTOM_THEMES_DIR . '/' . $theme_slug . '.css';
        if (!file_exists($theme_path)) {
            wp_send_json_error('Theme not found or cannot be deleted');
        }

        // Delete the file
        if (unlink($theme_path)) {
            wp_send_json_success('Theme deleted successfully');
        } else {
            wp_send_json_error('Failed to delete theme file');
        }
    }

    /**
     * AJAX handler for setting active theme
     */
    public function ajax_set_active_theme() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'twine_save_links')) {
            wp_send_json_error('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $theme_slug = isset($_POST['theme_slug']) ? sanitize_text_field($_POST['theme_slug']) : '';

        // Load current settings
        $data = $this->get_data();
        $data['theme'] = $theme_slug;

        // Save settings
        if (file_put_contents(TWINE_LINKS_FILE, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
            wp_send_json_success('Theme activated successfully');
        } else {
            wp_send_json_error('Failed to save settings');
        }
    }

    /**
     * Add rewrite rules for dynamic slug endpoint
     */
    public function add_rewrite_rules() {
        $slug = $this->get_slug();
        add_rewrite_rule('^' . $slug . '/?$', 'index.php?twine_page=1', 'top');
        add_rewrite_tag('%twine_page%', '([^&]+)');

        // Flush rewrite rules on plugin activation (only once)
        if (get_option('twine_rewrite_flush_needed') === false) {
            flush_rewrite_rules();
            update_option('twine_rewrite_flush_needed', '1');
        }
    }

    /**
     * Handle /twine page requests
     */
    public function handle_twine_page() {
        $twine_page = get_query_var('twine_page');
        if (empty($twine_page) && isset($_GET['twine_page'])) {
            $twine_page = $_GET['twine_page'];
        }

        if ($twine_page !== '1') {
            return;
        }

        // Get the active theme
        $theme_slug = $this->get_theme();

        // Set mode to live and call the preview handler
        $_GET['twine_preview'] = $theme_slug;
        $_GET['mode'] = 'live';

        $this->handle_theme_preview();
    }

    /**
     * Handle theme preview mode
     */
    public function handle_theme_preview() {
        if (!isset($_GET['twine_preview'])) {
            return;
        }

        $theme_slug = sanitize_text_field($_GET['twine_preview']);
        $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : 'sample';

        // Load real data if in live mode
        if ($mode === 'live') {
            $links = $this->get_links();
            $icon = $this->get_icon();
            $name = $this->get_name();
            $description = $this->get_description();
            $social = $this->get_social();
            $header_links = $this->get_header_links();
            $footer_links = $this->get_footer_links();
        } else {
            // Sample data
            $links = array();
            $icon = '';
            $name = 'Sample Name';
            $description = 'This is a sample description to show how your theme looks.';
            $social = array();
            $header_links = array(
                array('icon' => 'instagram', 'url' => '#', 'align' => 'left'),
                array('icon' => 'tiktok', 'url' => '#', 'align' => 'left'),
                array('icon' => 'youtube', 'url' => '#', 'align' => 'right'),
            );
            $footer_links = array(
                array('text' => 'Privacy', 'url' => '#'),
                array('text' => 'Terms', 'url' => '#'),
                array('text' => 'Contact', 'url' => '#'),
            );
        }

        // Determine page title
        $html_title = $this->get_page_title();
        if (empty($html_title)) {
            $html_title = !empty($name) ? $name : 'Links';
        }

        // Render preview page
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($html_title); ?></title>
            <link rel="stylesheet" href="<?php echo TWINE_PLUGIN_URL . 'assets/twine.css?v=' . TWINE_VERSION; ?>">
            <?php
            // Check for temporary theme preview
            $temp_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
            if (!empty($temp_key)) {
                // Load temporary theme file
                $temp_theme_file = TWINE_TEMP_THEMES_DIR . '/theme-' . $temp_key . '.css';
                if (file_exists($temp_theme_file)) {
                    // Inline the CSS to avoid browser caching issues
                    echo '<style>' . file_get_contents($temp_theme_file) . '</style>';
                }
            } elseif (!empty($theme_slug)) {
                // Load regular theme CSS
                $custom_theme_file = TWINE_CUSTOM_THEMES_DIR . '/' . $theme_slug . '.css';
                $plugin_theme_file = TWINE_PLUGIN_DIR . 'themes/' . $theme_slug . '.css';

                if (file_exists($custom_theme_file)) {
                    echo '<link rel="stylesheet" href="' . TWINE_CUSTOM_THEMES_URL . '/' . $theme_slug . '.css?v=' . TWINE_VERSION . '">';
                } elseif (file_exists($plugin_theme_file)) {
                    echo '<link rel="stylesheet" href="' . TWINE_PLUGIN_URL . 'themes/' . $theme_slug . '.css?v=' . TWINE_VERSION . '">';
                }
            }

            $ga_id = $this->get_ga_id();
            if (empty($ga_id)) {
                $ga_id = $this->get_monsterinsights_ga_id();
            }
            if (!empty($ga_id) && $mode === 'live'):
                $page_slug = $this->get_slug();
            ?>
            <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($ga_id); ?>"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());
                gtag('config', '<?php echo esc_js($ga_id); ?>', {
                    'page_title': '<?php echo esc_js($html_title); ?>',
                    'page_path': '/<?php echo esc_js($page_slug); ?>'
                });
            </script>
            <?php endif; ?>
            <style>
                html { margin: 0; padding: 0; }
                body { margin: 0; padding: 0; overflow-x: hidden; }
            </style>
        </head>
        <body>
            <div class="twine-container">
                <?php echo $this->render_header_html($header_links); ?>
                <div class="twine-profile">
                    <?php if ($icon || $mode === 'sample'): ?>
                    <div class="twine-icon">
                        <?php if ($icon): ?>
                            <img src="<?php echo esc_url($icon); ?>" alt="<?php echo esc_attr($name); ?>">
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; background: #ddd; display: flex; align-items: center; justify-content: center; font-size: 36px;">👤</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($name): ?>
                        <h1 class="twine-name"><?php echo esc_html($name); ?></h1>
                    <?php endif; ?>
                    <?php if ($description): ?>
                        <p class="twine-description"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
                <div class="twine-links">
                    <?php if ($mode === 'live' && !empty($links)): ?>
                        <?php foreach ($links as $link): ?>
                            <a href="<?php echo esc_url($link['url']); ?>" class="twine-link-button<?php echo !empty($link['image']) ? ' has-image' : ''; ?>" target="_blank">
                                <?php if (!empty($link['image'])): ?>
                                    <img src="<?php echo esc_url($link['image']); ?>" alt="" class="twine-link-image">
                                <?php endif; ?>
                                <span class="twine-link-text"><?php echo esc_html($link['text']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <a href="#" class="twine-link-button" onclick="return false;">Sample Link 1</a>
                        <a href="#" class="twine-link-button" onclick="return false;">Sample Link 2</a>
                        <a href="#" class="twine-link-button" onclick="return false;">Sample Link 3</a>
                    <?php endif; ?>
                </div>
                <?php if ($mode === 'live' && !empty($social)): ?>
                    <?php
                    // Detect duplicate icons to show labels (custom icons use URL as key)
                    $icon_counts = array();
                    foreach ($social as $item) {
                        if (!empty($item['url'])) {
                            $icon_key = ($item['icon'] === 'custom' && !empty($item['custom_icon'])) ? 'custom:' . $item['custom_icon'] : $item['icon'];
                            $icon_counts[$icon_key] = isset($icon_counts[$icon_key]) ? $icon_counts[$icon_key] + 1 : 1;
                        }
                    }
                    $duplicate_icons = array_filter($icon_counts, function($count) { return $count > 1; });

                    // Separate into regular and labeled icons
                    $regular_icons = array();
                    $labeled_icons = array();
                    foreach ($social as $item) {
                        if (!empty($item['url'])) {
                            $icon_key = ($item['icon'] === 'custom' && !empty($item['custom_icon'])) ? 'custom:' . $item['custom_icon'] : $item['icon'];
                            if (isset($duplicate_icons[$icon_key])) {
                                $labeled_icons[] = $item;
                            } else {
                                $regular_icons[] = $item;
                            }
                        }
                    }
                    ?>
                    <?php if (!empty($regular_icons)): ?>
                    <div class="twine-social">
                        <?php foreach ($regular_icons as $item): ?>
                            <a href="<?php echo esc_url($item['url']); ?>" target="_blank" class="twine-social-icon" aria-label="<?php echo esc_attr($item['name']); ?>">
                                <?php if ($item['icon'] === 'custom' && !empty($item['custom_icon'])): ?>
                                    <img src="<?php echo esc_url($item['custom_icon']); ?>" alt="<?php echo esc_attr($item['name']); ?>" class="twine-custom-icon">
                                <?php else: ?>
                                    <?php echo $this->get_social_icon($item['icon']); ?>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($labeled_icons)): ?>
                    <?php
                    // Group labeled icons by their icon type
                    $grouped_icons = array();
                    foreach ($labeled_icons as $item) {
                        $icon_key = ($item['icon'] === 'custom' && !empty($item['custom_icon'])) ? 'custom:' . $item['custom_icon'] : $item['icon'];
                        if (!isset($grouped_icons[$icon_key])) {
                            $grouped_icons[$icon_key] = array('icon' => $item['icon'], 'custom_icon' => isset($item['custom_icon']) ? $item['custom_icon'] : '', 'items' => array());
                        }
                        $grouped_icons[$icon_key]['items'][] = $item;
                    }
                    ?>
                    <div class="twine-social twine-social-labeled">
                        <?php foreach ($grouped_icons as $group): ?>
                            <div class="twine-social-group">
                                <span class="twine-social-icon twine-social-icon-static">
                                    <?php if ($group['icon'] === 'custom' && !empty($group['custom_icon'])): ?>
                                        <img src="<?php echo esc_url($group['custom_icon']); ?>" alt="" class="twine-custom-icon">
                                    <?php else: ?>
                                        <?php echo $this->get_social_icon($group['icon']); ?>
                                    <?php endif; ?>
                                </span>
                                <?php foreach ($group['items'] as $item): ?>
                                    <a href="<?php echo esc_url($item['url']); ?>" target="_blank" class="twine-social-text-link">
                                        <?php echo esc_html($item['name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php elseif ($mode === 'sample'): ?>
                    <div class="twine-social">
                        <a href="#" class="twine-social-icon" onclick="return false;"><?php echo $this->get_social_icon('facebook'); ?></a>
                        <a href="#" class="twine-social-icon" onclick="return false;"><?php echo $this->get_social_icon('instagram'); ?></a>
                        <a href="#" class="twine-social-icon" onclick="return false;"><?php echo $this->get_social_icon('x'); ?></a>
                    </div>
                <?php endif; ?>
                <?php echo $this->render_footer_html($footer_links); ?>
            </div>
            <?php if (!empty($ga_id) && $mode === 'live'): ?>
            <script>
                document.querySelectorAll('.twine-link-button').forEach(function(link) {
                    link.addEventListener('click', function() {
                        gtag('event', 'click', {
                            'event_category': 'Links',
                            'event_label': this.textContent.trim(),
                            'transport_type': 'beacon'
                        });
                    });
                });
                document.querySelectorAll('.twine-social-icon').forEach(function(link) {
                    link.addEventListener('click', function() {
                        var platform = this.href.includes('facebook') ? 'Facebook' :
                                       this.href.includes('instagram') ? 'Instagram' :
                                       this.href.includes('twitter') || this.href.includes('x.com') ? 'X' :
                                       this.href.includes('youtube') ? 'YouTube' :
                                       this.href.includes('tiktok') ? 'TikTok' :
                                       this.href.includes('linkedin') ? 'LinkedIn' :
                                       this.href.includes('github') ? 'GitHub' :
                                       this.href.includes('twitch') ? 'Twitch' : 'Social';
                        gtag('event', 'click', {
                            'event_category': 'Social',
                            'event_label': platform,
                            'transport_type': 'beacon'
                        });
                    });
                });
            </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Handle theme download
     */
    public function handle_theme_download() {
        if (!isset($_GET['twine_download_theme'])) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $theme_slug = sanitize_text_field($_GET['twine_download_theme']);
        if (empty($theme_slug)) {
            wp_die('Theme slug is required');
        }

        // Find the theme file
        $custom_theme_file = TWINE_CUSTOM_THEMES_DIR . '/' . $theme_slug . '.css';
        $plugin_theme_file = TWINE_PLUGIN_DIR . 'themes/' . $theme_slug . '.css';

        if (file_exists($custom_theme_file)) {
            $theme_path = $custom_theme_file;
        } elseif (file_exists($plugin_theme_file)) {
            $theme_path = $plugin_theme_file;
        } else {
            wp_die('Theme not found');
        }

        // Set headers for download
        header('Content-Type: text/css');
        header('Content-Disposition: attachment; filename="' . $theme_slug . '.css"');
        header('Content-Length: ' . filesize($theme_path));

        // Output file
        readfile($theme_path);
        exit;
    }
}

// Initialize the plugin
new Twine();
