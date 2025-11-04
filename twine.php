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
        wp_enqueue_style('twine-frontend', TWINE_PLUGIN_URL . 'assets/frontend.css', array(), TWINE_VERSION);

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
            'social' => array(
                'facebook' => '',
                'instagram' => '',
                'x' => '',
                'tiktok' => '',
                'youtube' => '',
                'linkedin' => '',
                'snapchat' => '',
                'github' => '',
                'website' => ''
            ),
            'theme' => ''
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
        return isset($data['social']) ? $data['social'] : array();
    }

    /**
     * Get saved theme
     */
    public function get_theme() {
        $data = $this->get_data();
        return isset($data['theme']) ? $data['theme'] : '';
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

        $links = array();

        if (isset($_POST['link_text']) && is_array($_POST['link_text'])) {
            foreach ($_POST['link_text'] as $index => $text) {
                if (!empty($text) && !empty($_POST['link_url'][$index])) {
                    $links[] = array(
                        'text' => sanitize_text_field($text),
                        'url' => esc_url_raw($_POST['link_url'][$index])
                    );
                }
            }
        }

        // Get icon, name, and description
        $icon = isset($_POST['twine_icon']) ? esc_url_raw($_POST['twine_icon']) : '';
        $name = isset($_POST['twine_name']) ? sanitize_text_field($_POST['twine_name']) : '';
        $description = isset($_POST['twine_description']) ? sanitize_textarea_field($_POST['twine_description']) : '';

        // Get social media links
        $social = array(
            'facebook' => isset($_POST['twine_social_facebook']) ? esc_url_raw($_POST['twine_social_facebook']) : '',
            'instagram' => isset($_POST['twine_social_instagram']) ? esc_url_raw($_POST['twine_social_instagram']) : '',
            'x' => isset($_POST['twine_social_x']) ? esc_url_raw($_POST['twine_social_x']) : '',
            'tiktok' => isset($_POST['twine_social_tiktok']) ? esc_url_raw($_POST['twine_social_tiktok']) : '',
            'youtube' => isset($_POST['twine_social_youtube']) ? esc_url_raw($_POST['twine_social_youtube']) : '',
            'linkedin' => isset($_POST['twine_social_linkedin']) ? esc_url_raw($_POST['twine_social_linkedin']) : '',
            'snapchat' => isset($_POST['twine_social_snapchat']) ? esc_url_raw($_POST['twine_social_snapchat']) : '',
            'github' => isset($_POST['twine_social_github']) ? esc_url_raw($_POST['twine_social_github']) : '',
            'website' => isset($_POST['twine_social_website']) ? esc_url_raw($_POST['twine_social_website']) : ''
        );

        // Get theme
        $theme = isset($_POST['twine_theme']) ? sanitize_text_field($_POST['twine_theme']) : '';

        // Prepare data structure
        $data = array(
            'icon' => $icon,
            'name' => $name,
            'description' => $description,
            'links' => $links,
            'social' => $social,
            'theme' => $theme
        );

        // Ensure directory exists
        $this->ensure_data_directory();

        // Write to JSON file
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = file_put_contents(TWINE_LINKS_FILE, $json);

        if ($result === false) {
            wp_die('Failed to save links. Please check file permissions.');
        }

        // Handle theme file upload
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

        // Determine redirect page
        $redirect_page = isset($_POST['redirect_to']) ? sanitize_text_field($_POST['redirect_to']) : 'twine';

        // Redirect back to admin page
        wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&saved=true'));
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
        $theme = $this->get_theme();
        $available_themes = $this->get_available_themes();
        ?>
        <div class="wrap">
            <h1>Twine Settings</h1>

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

            <div class="notice notice-info">
                <p>
                    <strong>Your Twine Links Page:</strong>
                    <a href="<?php echo home_url('/twine'); ?>" target="_blank"><?php echo home_url('/twine'); ?></a>
                    <br>
                    <em>Share this link on your social media profiles, in your bio, or anywhere you want to direct people to all your links in one place.</em>
                </p>
            </div>

            <div class="twine-admin-container">
                <div class="twine-admin-content">
                    <h2 class="nav-tab-wrapper">
                        <a href="#general" class="nav-tab nav-tab-active" data-tab="general">General</a>
                        <a href="#theme" class="nav-tab" data-tab="theme">Theme</a>
                        <a href="#links" class="nav-tab" data-tab="links">Links</a>
                        <a href="#social" class="nav-tab" data-tab="social">Social</a>
                    </h2>

                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="twine-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="twine_save_links">
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
                                        <?php echo $icon ? 'Change Icon' : 'Upload Icon'; ?>
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

                        <div class="twine-tab-content" id="tab-links" style="display: none;">
                        <div class="twine-links-container" id="twine-links-container">
                            <?php if (!empty($links)): ?>
                                <?php foreach ($links as $index => $link): ?>
                                    <div class="twine-link-item">
                                        <span class="twine-drag-handle dashicons dashicons-menu"></span>
                                        <div class="twine-link-fields">
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

                        <div class="twine-tab-content" id="tab-social" style="display: none;">
                        <div class="twine-social-section">
                            <p class="description">Add links to your social media profiles. Only filled links will be displayed.</p>

                            <div class="twine-social-grid">
                                <div class="twine-social-field">
                                    <label for="twine-social-facebook">
                                        <span class="dashicons dashicons-facebook-alt"></span> Facebook
                                    </label>
                                    <input type="url"
                                           name="twine_social_facebook"
                                           id="twine-social-facebook"
                                           value="<?php echo esc_attr($social['facebook']); ?>"
                                           placeholder="https://facebook.com/yourpage"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-instagram">
                                        <span class="dashicons dashicons-instagram"></span> Instagram
                                    </label>
                                    <input type="url"
                                           name="twine_social_instagram"
                                           id="twine-social-instagram"
                                           value="<?php echo esc_attr($social['instagram']); ?>"
                                           placeholder="https://instagram.com/yourusername"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-x">
                                        <span class="dashicons dashicons-twitter"></span> X (Twitter)
                                    </label>
                                    <input type="url"
                                           name="twine_social_x"
                                           id="twine-social-x"
                                           value="<?php echo esc_attr($social['x']); ?>"
                                           placeholder="https://x.com/yourusername"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-tiktok">
                                        <span class="dashicons dashicons-video-alt3"></span> TikTok
                                    </label>
                                    <input type="url"
                                           name="twine_social_tiktok"
                                           id="twine-social-tiktok"
                                           value="<?php echo esc_attr($social['tiktok']); ?>"
                                           placeholder="https://tiktok.com/@yourusername"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-youtube">
                                        <span class="dashicons dashicons-video-alt2"></span> YouTube
                                    </label>
                                    <input type="url"
                                           name="twine_social_youtube"
                                           id="twine-social-youtube"
                                           value="<?php echo esc_attr($social['youtube']); ?>"
                                           placeholder="https://youtube.com/@yourusername"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-linkedin">
                                        <span class="dashicons dashicons-linkedin"></span> LinkedIn
                                    </label>
                                    <input type="url"
                                           name="twine_social_linkedin"
                                           id="twine-social-linkedin"
                                           value="<?php echo esc_attr($social['linkedin']); ?>"
                                           placeholder="https://linkedin.com/in/yourusername"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-snapchat">
                                        <span class="dashicons dashicons-camera"></span> Snapchat
                                    </label>
                                    <input type="url"
                                           name="twine_social_snapchat"
                                           id="twine-social-snapchat"
                                           value="<?php echo esc_attr($social['snapchat']); ?>"
                                           placeholder="https://snapchat.com/add/yourusername"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-github">
                                        <span class="dashicons dashicons-editor-code"></span> GitHub
                                    </label>
                                    <input type="url"
                                           name="twine_social_github"
                                           id="twine-social-github"
                                           value="<?php echo esc_attr($social['github']); ?>"
                                           placeholder="https://github.com/yourusername"
                                           class="regular-text">
                                </div>

                                <div class="twine-social-field">
                                    <label for="twine-social-website">
                                        <span class="dashicons dashicons-admin-site"></span> Website
                                    </label>
                                    <input type="url"
                                           name="twine_social_website"
                                           id="twine-social-website"
                                           value="<?php echo esc_attr($social['website']); ?>"
                                           placeholder="https://yourwebsite.com"
                                           class="regular-text">
                                </div>
                            </div>
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
                                <a href="<?php echo admin_url('admin.php?page=twine-theme-editor&theme=' . $slug); ?>"
                                   class="button">Customize</a>
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
            'button_radius' => '12'
        );

        // Load existing theme values if editing
        if ($is_editing) {
            $theme_data = $available_themes[$editing_theme];
            $defaults['theme_name'] = $theme_data['name'];

            $theme_path = $theme_data['custom']
                ? TWINE_CUSTOM_THEMES_DIR . '/' . $editing_theme . '.css'
                : TWINE_PLUGIN_DIR . 'themes/' . $editing_theme . '.css';

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
            }
        }
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo admin_url('admin.php?page=twine-theme-editor'); ?>" class="page-title-action" style="text-decoration: none;">← Back to Themes</a>
                <?php echo $is_editing ? 'Edit Theme: ' . esc_html($defaults['theme_name']) : 'Create New Theme'; ?>
            </h1>
            <p><?php echo $is_editing ? 'Modify the colors and styles for this theme.' : 'Create a custom theme by adjusting colors and styles below.'; ?></p>

            <?php if ($is_editing && $available_themes[$editing_theme]['custom']): ?>
                <p style="margin-bottom: 20px;">
                    <a href="<?php echo admin_url('admin.php?twine_download_theme=' . $editing_theme); ?>"
                       class="button">
                        <span class="dashicons dashicons-download" style="margin-top: 4px;"></span> Download CSS
                    </a>
                </p>
            <?php endif; ?>

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

                    <tr>
                        <th scope="row"><label for="base-theme">Start From Theme</label></th>
                        <td>
                            <select name="base_theme" id="base-theme" class="regular-text">
                                <option value="">Start from scratch</option>
                                <?php foreach ($available_themes as $slug => $theme_data): ?>
                                    <option value="<?php echo esc_attr($slug); ?>">
                                        <?php echo esc_html($theme_data['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Optional: Load values from an existing theme</p>
                        </td>
                    </tr>

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
                </table>

                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo $is_editing ? 'Update Theme' : 'Create Theme'; ?>">
                </p>
            </form>
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

        // Generate CSS
        $css = "/**\n";
        $css .= " * Theme Name: " . $theme_name . "\n";
        $css .= " * Description: Custom theme created with theme editor\n";
        $css .= " * Version: 1.0.0\n";
        $css .= " * Author: Custom\n";
        $css .= " */\n\n";

        $css .= ".twine-container {\n";
        $css .= "    background: " . $background_color . ";\n";
        $css .= "    padding: 40px 20px;\n";
        $css .= "}\n\n";

        $css .= ".twine-icon {\n";
        $css .= "    border: 3px solid " . $icon_border_color . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-name {\n";
        $css .= "    color: " . $name_color . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-description {\n";
        $css .= "    color: " . $description_color . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-link-button {\n";
        $css .= "    background: " . $button_bg . ";\n";
        $css .= "    color: " . $button_text . ";\n";
        $css .= "    border-radius: " . $button_radius . "px;\n";
        $css .= "}\n\n";

        $css .= ".twine-link-button:hover {\n";
        $css .= "    background: " . $button_hover_bg . ";\n";
        $css .= "}\n\n";

        $css .= ".twine-social-icon {\n";
        $css .= "    color: " . $social_icon_color . ";\n";
        $css .= "}\n";

        // Ensure custom themes directory exists
        if (!file_exists(TWINE_CUSTOM_THEMES_DIR)) {
            wp_mkdir_p(TWINE_CUSTOM_THEMES_DIR);
        }

        // Save theme file
        $theme_path = TWINE_CUSTOM_THEMES_DIR . '/' . $theme_slug . '.css';
        $result = file_put_contents($theme_path, $css);

        if ($result === false) {
            wp_die('Failed to save theme file. Please check file permissions.');
        }

        // Redirect back to themes gallery
        wp_redirect(admin_url('admin.php?page=twine-theme-editor&created=1'));
        exit;
    }

    /**
     * Get social media icon SVG
     */
    private function get_social_icon($platform) {
        $icons = array(
            'facebook' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'instagram' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>',
            'x' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            'tiktok' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/></svg>',
            'youtube' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
            'linkedin' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
            'snapchat' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12.166.005c-3.028.07-5.449 2.525-5.449 5.562 0 .203.012.405.037.606-.47.156-.973.326-1.451.488-.358.12-.678.246-.941.356-.236.099-.437.192-.606.27-.091.042-.174.084-.249.124-.124.066-.25.146-.337.224-.149.136-.249.29-.292.445-.064.231.011.49.214.73.191.227.48.43.84.603.097.046.201.092.312.139.073.031.147.064.226.099-.138.521-.321 1.213-.54 2.02-.261.966-.488 1.67-.701 2.164-.098.225-.197.421-.298.588-.075.123-.154.235-.238.34-.159.197-.365.383-.615.556-.37.255-.835.489-1.383.697-.166.063-.329.118-.483.166-.124.038-.241.07-.35.098-.141.037-.27.07-.387.1-.155.04-.296.078-.422.116-.185.056-.347.112-.486.167-.214.083-.395.166-.544.244-.223.117-.405.234-.543.35-.239.201-.38.399-.419.592-.05.243.031.492.227.704.182.197.44.36.765.485.327.125.701.207 1.107.242.072.006.145.012.218.017-.022.172-.043.35-.061.534-.038.359-.058.674-.061.935-.004.259.007.452.034.57.033.14.103.28.206.413.091.117.206.224.34.318.197.138.447.259.74.361.301.104.636.186.995.245.185.03.375.054.567.072.122.011.244.021.366.029-.007.095-.022.187-.044.278-.081.326-.259.618-.529.875-.345.329-.825.609-1.43.833-.219.081-.449.154-.688.221-.203.057-.409.109-.615.157-.278.064-.555.12-.826.165-.374.062-.737.105-1.081.131-.343.025-.668.032-.968.021-.271-.011-.523-.038-.752-.079-.24-.043-.458-.104-.651-.18-.241-.095-.449-.214-.621-.349-.223-.176-.39-.373-.496-.588-.089-.181-.132-.372-.127-.566.004-.161.033-.318.087-.469.069-.193.173-.373.312-.534.192-.222.445-.413.755-.568.123-.062.255-.117.393-.166.12-.042.245-.078.372-.111-.166-.274-.309-.587-.426-.933-.143-.425-.254-.9-.329-1.415-.038-.258-.067-.522-.086-.788-.015-.212-.024-.425-.026-.636-.01-.876.117-1.711.318-2.359.122-.392.266-.729.426-1.001.157-.267.335-.476.527-.623.287-.22.632-.365 1.022-.431.256-.044.529-.059.812-.045.231.012.47.042.713.091.308.062.633.152.966.268.253.088.511.19.771.304.194.085.389.175.583.269.118.057.236.115.353.173-.113-.473-.196-.963-.243-1.463-.027-.285-.043-.572-.045-.858-.009-.977.212-1.896.633-2.687.421-.792 1.048-1.447 1.826-1.909.779-.462 1.717-.725 2.735-.768.284-.012.572-.009.862.009.509.032 1.025.114 1.537.242.514.129.021.27 1.511.452.291.094.572.201.842.319.27.118.529.248.773.388.484.278.933.604 1.331.973.398.369.748.782 1.039 1.231.291.449.525.935.694 1.453.169.517.274 1.066.312 1.635.019.286.025.575.019.866-.013.58-.081 1.165-.201 1.738-.06.286-.132.569-.216.846.231-.116.467-.226.706-.329.309-.133.623-.248.937-.343.203-.061.405-.113.606-.156.162-.034.323-.062.481-.082.118-.016.234-.028.347-.036.084-.006.167-.009.247-.009.457-.002.866.063 1.217.195.351.132.645.329.878.589.176.196.313.421.409.671.079.207.124.427.135.656.014.292-.038.591-.155.882-.108.271-.269.53-.48.77-.243.275-.549.526-.916.75-.271.166-.574.315-.908.447-.195.077-.397.148-.605.214-.119.037-.239.073-.36.106.141.087.295.17.464.249.303.142.655.267 1.051.376.257.071.529.134.81.189.224.043.455.082.687.115.164.024.328.045.492.062.117.012.234.022.349.03.316.022.619.027.902.012.283-.015.549-.049.794-.103.246-.054.473-.127.679-.218.206-.091.391-.201.554-.329.163-.128.304-.273.424-.435.119-.161.217-.339.293-.531.115-.291.167-.612.155-.933-.011-.293-.076-.582-.191-.856-.115-.274-.279-.53-.49-.76-.211-.23-.468-.432-.768-.601-.3-.169-.639-.298-1.011-.385-.186-.043-.378-.077-.573-.103-.097-.013-.194-.024-.292-.033.009-.081.022-.161.038-.239.078-.382.216-.732.414-1.049.253-.406.604-.765 1.048-1.074.267-.186.567-.349.897-.488.243-.102.5-.19.766-.264.195-.054.393-.099.593-.136.14-.026.281-.048.422-.066.101-.013.202-.023.303-.031.351-.028.689-.02 1.008.023.319.043.62.118.898.224.278.106.536.243.771.411.235.168.447.367.633.595.186.228.346.487.478.773.132.286.235.601.307.941.071.34.11.705.114 1.091.004.386-.028.793-.096 1.218-.069.424-.175.864-.318 1.315-.143.451-.322.911-.536 1.373-.107.232-.223.462-.346.689-.062.113-.124.226-.188.338-.128.225-.263.447-.403.664-.28.435-.585.853-.912 1.245-.328.392-.678.757-1.049 1.093-.185.167-.374.326-.567.476-.145.113-.292.221-.441.325.263.017.537.027.82.028.546.003 1.084-.029 1.597-.098.513-.069 1-.176 1.456-.319.456-.143.879-.322 1.262-.535.383-.213.726-.463 1.024-.746.298-.283.548-.601.746-.95.197-.349.342-.731.428-1.141.086-.41.113-.848.08-1.312-.033-.463-.135-.949-.306-1.452-.063-.187-.138-.376-.223-.566-.044-.098-.091-.197-.14-.295.139-.011.282-.014.427-.009.349.013.704.063 1.058.148.354.085.703.206 1.043.364.341.157.669.349.979.574.31.224.601.483.868.772.267.289.508.608.716.953.208.344.378.713.508 1.103.13.39.218.798.262 1.221.044.422.044.856 0 1.298-.044.442-.14.89-.286 1.339-.146.449-.345.897-.594 1.337-.125.22-.263.436-.414.648-.113.16-.232.316-.357.468-.251.306-.531.598-.835.871-.609.547-1.329 1.032-2.151 1.449-.616.313-1.289.585-2.016.814-.435.137-.891.259-1.364.366-.354.08-.718.152-1.09.215-.262.044-.528.084-.798.119-.194.025-.389.048-.586.068-.279.028-.562.051-.846.069-.379.024-.762.041-1.148.049-.772.017-1.56.002-2.361-.047.056.141.098.29.127.445.057.315.066.655.023 1.017-.064.534-.247 1.104-.555 1.707-.386.755-.999 1.502-1.833 2.233-.667.585-1.465 1.138-2.383 1.654-.687.386-1.441.739-2.254 1.056-.611.238-1.253.455-1.923.649-.503.145-1.021.275-1.549.39-.374.082-.752.156-1.134.223-.273.048-.548.092-.825.132-.395.057-.792.107-1.191.149-.531.056-1.067.095-1.605.119-.539.024-1.08.032-1.624.025-.544-.007-1.09-.029-1.637-.067-.274-.019-.549-.042-.824-.069-.196-.019-.391-.04-.587-.063-.276-.033-.553-.069-.829-.109-.369-.053-.738-.113-1.107-.18-.738-.133-1.475-.292-2.208-.476-.733-.184-1.461-.395-2.183-.634-.722-.239-1.436-.506-2.139-.802-.703-.296-1.396-.622-2.074-.978-.678-.356-1.341-.745-1.984-1.165-.644-.42-1.268-.874-1.869-1.363-.6-.488-1.177-1.012-1.726-1.574-.274-.281-.539-.57-.793-.869-.19-.224-.375-.454-.552-.689-.355-.47-.681-.959-.975-1.468-.588-.92-1.076-1.906-1.459-2.951-.287-.784-.52-1.598-.698-2.437-.134-.63-.235-1.273-.302-1.927-.05-.49-.08-.984-.089-1.481-.01-.49.004-.981.039-1.472.035-.491.095-.98.179-1.465.084-.485.192-.966.324-1.44.132-.474.288-.941.469-1.4.181-.459.385-.908.614-1.346.229-.438.482-.864.759-1.277.277-.413.577-.811.901-1.193.325-.382.673-.747 1.044-1.093.37-.346.763-.672 1.177-.978.207-.153.419-.302.636-.445.163-.108.329-.213.497-.314.337-.202.683-.388 1.038-.559.709-.342 1.457-.627 2.239-.853.586-.17 1.193-.307 1.817-.413.468-.079.944-.14 1.427-.183.362-.032.727-.055 1.095-.068.276-.01.552-.015.829-.014.554.002 1.109.022 1.663.062.554.04 1.107.099 1.657.175.55.076 1.097.171 1.64.283.543.112 1.082.243 1.615.392.533.149 1.061.316 1.582.5.521.184 1.034.386 1.536.605.502.219.995.454 1.477.704.241.125.479.255.714.389.176.101.351.205.523.311.345.212.684.433 1.015.662.663.459 1.297.95 1.896 1.473.3.262.589.533.868.814.209.211.412.428.609.649.393.442.762.901 1.105 1.375.343.474.659.963.948 1.466.289.503.551 1.019.785 1.545.234.526.439 1.063.617 1.608.089.273.169.548.242.825.055.208.104.417.149.627.089.421.159.846.209 1.275.025.214.045.429.06.645.008.108.014.216.019.325v.326c0 .217-.007.433-.021.649-.014.216-.035.432-.062.648-.027.216-.06.431-.099.645-.039.214-.084.427-.135.639-.051.212-.108.423-.171.633-.063.21-.131.419-.206.627-.075.208-.155.414-.241.619-.086.205-.178.408-.275.609-.097.201-.199.4-.306.598-.107.198-.22.393-.337.587-.117.194-.239.386-.366.577-.127.191-.258.38-.395.567-.137.187-.278.372-.424.554-.146.182-.297.363-.452.541-.155.178-.314.354-.477.528-.163.174-.331.346-.502.515-.171.169-.346.336-.525.5-.179.164-.361.326-.547.484-.186.158-.375.314-.568.468-.193.154-.388.305-.587.453-.199.148-.401.293-.605.436-.204.143-.411.282-.62.419-.209.137-.42.272-.634.404-.214.132-.429.261-.647.387-.218.126-.438.249-.66.369-.222.12-.446.237-.671.352-.225.115-.452.226-.68.335-.228.109-.458.215-.689.317-.231.102-.463.201-.697.297-.234.096-.469.189-.706.279-.237.09-.475.177-.715.261-.24.084-.481.165-.723.242-.242.077-.485.152-.729.223-.244.071-.489.139-.735.204-.246.065-.493.126-.741.185-.248.059-.497.114-.747.167-.25.053-.501.103-.752.149-.251.046-.503.089-.755.129-.252.04-.505.076-.758.11-.253.034-.507.065-.762.092-.255.027-.51.051-.765.072-.255.021-.511.039-.767.053-.256.014-.512.025-.769.033-.257.008-.513.014-.77.017-.257.003-.514.003-.77 0-.257-.003-.513-.009-.77-.019-.257-.01-.513-.024-.769-.041-.513-.034-1.025-.082-1.536-.146-.511-.064-1.021-.142-1.53-.234-.509-.092-1.015-.199-1.52-.321-.505-.122-1.006-.259-1.505-.411-.499-.152-.995-.319-1.489-.501-.494-.182-.983-.379-1.469-.591-.486-.212-.968-.439-1.446-.681-.478-.242-.952-.499-1.422-.77-.47-.271-.935-.558-1.395-.859-.46-.301-.916-.617-1.365-.947-.449-.33-.894-.675-1.332-1.034-.438-.359-.87-.732-1.296-1.119-.426-.387-.845-.788-1.258-1.203-.413-.415-.818-.843-1.216-1.284-.398-.441-.788-.896-1.17-1.364-.382-.468-.755-.948-1.119-1.441-.364-.493-.718-1-.063-1.518-.345-.518-.68-1.047-1.005-1.587-.325-.54-.638-1.091-.941-1.652-.303-.561-.594-1.133-.873-1.714-.279-.581-.546-1.172-.799-1.771-.253-.599-.493-1.208-.718-1.824-.225-.616-.435-1.24-.631-1.871-.196-.631-.377-1.269-.543-1.913-.166-.644-.317-1.295-.452-1.95-.135-.655-.254-1.316-.358-1.981-.104-.665-.191-1.333-.263-2.005-.072-.672-.128-1.347-.168-2.024-.04-.677-.064-1.357-.072-2.038-.008-.681.001-1.364.027-2.048.026-.684.068-1.369.126-2.055.058-.686.132-1.373.221-2.059.089-.686.194-1.372.314-2.056.12-.684.256-1.367.407-2.047.151-.68.318-1.358.5-2.033.182-.675.379-1.347.592-2.016.213-.669.441-1.333.684-1.993.243-.66.501-1.316.774-1.967.273-.651.561-1.296.863-1.936.302-.64.619-1.273.95-1.9.331-.627.676-1.247 1.035-1.86.359-.613.732-1.218 1.118-1.816.386-.598.786-1.187 1.199-1.767.413-.58.839-1.149 1.278-1.708.439-.559.891-1.107 1.355-1.643.464-.536.941-1.06 1.429-1.572.488-.512.988-1.01 1.5-1.495.512-.485 1.035-.956 1.569-1.413.534-.457 1.079-.898 1.635-1.324.556-.426 1.122-.837 1.698-1.232.576-.395 1.162-.773 1.758-1.136.596-.363 1.201-.709 1.815-1.038.614-.329 1.237-.641 1.868-.936.631-.295 1.27-.572 1.917-.831.647-.259 1.301-.5 1.962-.724.661-.224 1.329-.429 2.003-.617.674-.188 1.353-.357 2.038-.509.685-.152 1.375-.286 2.07-.402.695-.116 1.394-.214 2.097-.294.703-.08 1.409-.142 2.119-.186.71-.044 1.422-.07 2.137-.079.715-.009 1.432-.001 2.15.025.718.026 1.438.069 2.158.13.36.03.721.065 1.081.105z"/></svg>',
            'github' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>',
            'website' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm9.5 12c0 1.657-.425 3.214-1.173 4.576l-1.025-.396c-.494-.191-.842-.586-1.025-1.071l-.399-1.057c-.084-.224-.136-.458-.157-.696l-.073-.848a2.118 2.118 0 0 0-.589-1.318l-1.05-1.05a2.113 2.113 0 0 0-1.497-.62h-.9a2.118 2.118 0 0 0-1.5.621l-.9.9c-.4.4-.621.943-.621 1.5v1.2c0 .233.038.465.114.686l.457 1.371c.115.345.173.708.173 1.074v.729a2.117 2.117 0 0 0 1.06 1.833l.64.366c.212.121.451.184.693.184h1.5c.829 0 1.5-.671 1.5-1.5v-1.2c0-.398.158-.779.439-1.061l.9-.9a2.113 2.113 0 0 1 1.497-.62h.364c.133 0 .266.013.396.038a9.511 9.511 0 0 1-8.139 6.673v-.711c0-.828-.672-1.5-1.5-1.5h-1.2a2.113 2.113 0 0 0-2.121 2.121c0 .233.038.465.114.686l.057.171c.115.345.173.708.173 1.074v.193A9.484 9.484 0 0 1 2.5 12C2.5 6.752 6.701 2.5 12 2.5c5.299 0 9.5 4.252 9.5 9.5z"/></svg>'
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
                       class="twine-link-button"
                       target="_blank"
                       rel="noopener noreferrer">
                        <?php echo esc_html($link['text']); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php
            // Display social media icons if any are set
            $has_social = false;
            foreach ($social as $url) {
                if (!empty($url)) {
                    $has_social = true;
                    break;
                }
            }

            if ($has_social):
            ?>
                <div class="twine-social">
                    <?php foreach ($social as $platform => $url): ?>
                        <?php if (!empty($url)): ?>
                            <a href="<?php echo esc_url($url); ?>"
                               class="twine-social-icon"
                               target="_blank"
                               rel="noopener noreferrer"
                               aria-label="<?php echo esc_attr(ucfirst($platform)); ?>">
                                <?php echo $this->get_social_icon($platform); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
     * Add rewrite rules for /twine endpoint
     */
    public function add_rewrite_rules() {
        add_rewrite_rule('^twine/?$', 'index.php?twine_page=1', 'top');
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
        if (get_query_var('twine_page') !== '1') {
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
        } else {
            // Sample data
            $links = array();
            $icon = '';
            $name = 'Sample Name';
            $description = 'This is a sample description to show how your theme looks.';
            $social = array();
        }

        // Render preview page
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Theme Preview</title>
            <link rel="stylesheet" href="<?php echo TWINE_PLUGIN_URL . 'assets/frontend.css?v=' . TWINE_VERSION; ?>">
            <?php
            // Load theme CSS
            if (!empty($theme_slug)) {
                $custom_theme_file = TWINE_CUSTOM_THEMES_DIR . '/' . $theme_slug . '.css';
                $plugin_theme_file = TWINE_PLUGIN_DIR . 'themes/' . $theme_slug . '.css';

                if (file_exists($custom_theme_file)) {
                    echo '<link rel="stylesheet" href="' . TWINE_CUSTOM_THEMES_URL . '/' . $theme_slug . '.css?v=' . TWINE_VERSION . '">';
                } elseif (file_exists($plugin_theme_file)) {
                    echo '<link rel="stylesheet" href="' . TWINE_PLUGIN_URL . 'themes/' . $theme_slug . '.css?v=' . TWINE_VERSION . '">';
                }
            }
            ?>
            <style>
                html { margin: 0; padding: 0; }
                body { margin: 0; padding: 0; overflow-x: hidden; }
            </style>
        </head>
        <body>
            <div class="twine-container">
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
                            <a href="<?php echo esc_url($link['url']); ?>" class="twine-link-button" target="_blank"><?php echo esc_html($link['text']); ?></a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <a href="#" class="twine-link-button" onclick="return false;">Sample Link 1</a>
                        <a href="#" class="twine-link-button" onclick="return false;">Sample Link 2</a>
                        <a href="#" class="twine-link-button" onclick="return false;">Sample Link 3</a>
                    <?php endif; ?>
                </div>
                <?php
                $has_social = false;
                if ($mode === 'live') {
                    $social_platforms = array('facebook', 'instagram', 'x', 'youtube', 'tiktok', 'linkedin', 'github', 'twitch');
                    foreach ($social_platforms as $platform) {
                        if (!empty($social[$platform])) {
                            $has_social = true;
                            break;
                        }
                    }
                }
                ?>
                <?php if ($mode === 'live' && $has_social): ?>
                    <div class="twine-social">
                        <?php foreach ($social_platforms as $platform): ?>
                            <?php if (!empty($social[$platform])): ?>
                                <a href="<?php echo esc_url($social[$platform]); ?>" target="_blank" class="twine-social-icon">
                                    <?php echo $this->get_social_icon($platform); ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($mode === 'sample'): ?>
                    <div class="twine-social">
                        <a href="#" class="twine-social-icon" onclick="return false;"><?php echo $this->get_social_icon('facebook'); ?></a>
                        <a href="#" class="twine-social-icon" onclick="return false;"><?php echo $this->get_social_icon('instagram'); ?></a>
                        <a href="#" class="twine-social-icon" onclick="return false;"><?php echo $this->get_social_icon('x'); ?></a>
                    </div>
                <?php endif; ?>
            </div>
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
