<?php
/**
 * Plugin Name: Rippledesk: Phone Calls & SMS
 * Plugin URI: https://rippledesk.com
 * Description: Business phone and messaging system for teams. Rippledesk allows you to make and receive calls from your WordPress site.
 * Version: 0.0.1
 * Author: Rippledesk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rippledesk
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Domain Path: /languages
 *
 * @package Rippledesk
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RIPPLEDESK_PLUGIN_VERSION', '0.0.1');
define('RIPPLEDESK_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('RIPPLEDESK_PLUGIN_FILE', __FILE__);
define("RIPPLEDESK_PLUGIN_SLUG", 'rippledesk');

$env_file = "env.production";

// Load environment variables
if (file_exists(RIPPLEDESK_PLUGIN_PATH . $env_file)) {
    $env_file = file_get_contents(RIPPLEDESK_PLUGIN_PATH . $env_file);
    $lines = explode("\n", $env_file);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '"\'');
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

/**
 * Main Rippledesk  class
 */
class Rippledesk
{

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Get environment variable with fallback
     */
    public static function get_env($key, $default = null)
    {
        // Check if constant is defined
        if (defined($key)) {
            return constant($key);
        }

        // Check environment variable
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {

        // Plugin activation hook
        register_activation_hook(RIPPLEDESK_PLUGIN_FILE, array($this, 'plugin_activation'));

        add_action('wp_enqueue_scripts', array($this, 'load_public_frontend_widget_script'));

        // Plugin deactivation hook
        register_deactivation_hook(RIPPLEDESK_PLUGIN_FILE, array($this, 'plugin_deactivation'));

        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Admin init
        add_action('admin_init', array($this, 'admin_init'));

        // Add settings link to plugin page
        add_filter('plugin_action_links_' . plugin_basename(RIPPLEDESK_PLUGIN_FILE), array($this, 'add_plugin_settings_link'));

        add_action('rest_api_init', function () {
            register_rest_route(RIPPLEDESK_PLUGIN_SLUG . '/v1', '/reauth', array(
                'methods' => 'GET',
                'callback' => array($this, 'reauth_token'),
                'permission_callback' => '__return_true'
            ));
        });

        add_action('admin_footer', function ($hook) {

            $rd_response_body = get_option("rd_response_body");
            if (!$rd_response_body || empty($rd_response_body) || $rd_response_body === null) {
                $this->login_auth();
            }

            printf(
                '<div rdAppVersion="%s" id="rd-app">%s</div>',
                esc_attr(RIPPLEDESK_PLUGIN_VERSION),
                esc_html__('Please wait...', 'rippledesk')
            );
        });


        add_action('admin_bar_menu', array($this, 'add_custom_admin_bar_button'), 999);
    }

    /**
     * Add custom call button to admin bar
     */
    public function add_custom_admin_bar_button($admin_bar)
    {
        // Only show for users who can manage options
        if (!current_user_can('manage_options')) {
            return;
        }

        $admin_bar->add_menu(array(
            'id' => 'rippledesk-call-button',
            'title' => '<span class="ab-icon dashicons-phone" style="margin-top: 2px;"></span> Make Call',
            'href' => '#',
            'meta' => array(
                'title' => __('Make a call with Rippledesk', 'rippledesk'),
                'class' => 'rippledesk-admin-call-button',
                'onclick' => 'rippledeskAdminCall(event); return false;',
            ),
        ));

        // Add inline script for the call functionality
        add_action('admin_footer', array($this, 'add_admin_bar_call_script'));
        add_action('wp_footer', array($this, 'add_admin_bar_call_script'));
    }

    /**
     * Add JavaScript for admin bar call button
     */
    public function add_admin_bar_call_script()
    {
        static $script_added = false;

        // Prevent adding the script multiple times
        if ($script_added) {
            return;
        }
        $script_added = true;

        ?>
        <script type="text/javascript">
            function rippledeskAdminCall(event) {
                event.preventDefault();
                const callButtonElement = document.getElementById("rippledesk-call-button")
                if (callButtonElement) {
                    callButtonElement.click()
                }
            }

            // Add some styling for the admin bar button
            document.addEventListener('DOMContentLoaded', function () {
                const style = document.createElement('style');
                style.textContent = `
                    .rippledesk-admin-call-button .ab-icon:before {
                        color: #00a32a !important;
                    }
                    .rippledesk-admin-call-button:hover .ab-icon:before {
                        color: #008a20 !important;
                    }
                    .rippledesk-admin-call-button:hover {
                        background-color: rgba(0, 163, 42, 0.1) !important;
                    }
                `;
                document.head.appendChild(style);
            });
        </script>
        <?php
    }


    public function reauth_token()
    {
        $this->login_auth();
        return new WP_REST_Response(array(), 200);
    }


    /**
     * Plugin activation callback
     */
    public function plugin_activation()
    {
        // Log activation
        logger('Rippledesk: Activation started');

        // Create user and workspace in Rippledesk on activation
        $this->login_auth();

        // Set activation flag
        update_option('rippledesk_activated', true);
        update_option('rippledesk_activation_date', current_time('mysql'));

        logger('Rippledesk: Activation completed');
    }

    /**
     * Plugin deactivation callback
     */
    public function plugin_deactivation()
    {
        // Clean up options
        delete_option('rippledesk_activated');
        delete_option('rippledesk_activation_date');
        delete_option('rippledesk_integration_last_sync');

        logger('Rippledesk Integration Plugin: Deactivated');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        // Add main menu page
        add_menu_page(
            'Rippledesk',           // Page title
            'Rippledesk',           // Menu title
            'manage_options',       // Capability
            'rippledesk-dashboard', // Menu slug
            array($this, 'render_react'), // Function
            $this->get_menu_icon("rdpo.svg"), // Custom icon
            30                      // Position
        );

        // Add Home submenu (this will replace the default submenu with same slug)
        add_submenu_page(
            'rippledesk-dashboard', // Parent slug
            'Home',                 // Page title
            'Home',                 // Menu title
            'manage_options',       // Capability
            'rippledesk-dashboard', // Menu slug (same as parent for home)
            array($this, 'render_react'),

        );

        // Add History submenu
        add_submenu_page(
            'rippledesk-dashboard',
            'Inbox',
            'Inbox',
            'manage_options',
            'rippledesk-inbox',
            array($this, 'render_react'),
        );

        // Add History submenu
        add_submenu_page(
            'rippledesk-dashboard', // Parent slug
            'Rippledesk History',   // Page title
            'History',              // Menu title
            'manage_options',       // Capability
            'rippledesk-history',   // Menu slug
            array($this, 'render_react') // Function
        );

        // Add Settings submenu
        add_submenu_page(
            'rippledesk-dashboard', // Parent slug
            'Rippledesk Settings',  // Page title
            'Settings',             // Menu title
            'manage_options',       // Capability
            'rippledesk-settings',  // Menu slug
            array($this, 'render_react') // Function
        );


        // Add Settings submenu
        add_submenu_page(
            'rippledesk-dashboard', // Parent slug
            'Rippledesk Plans',  // Page title
            'Plans',             // Menu title
            'manage_options',    // Capability
            'rippledesk-plans',  // Menu slug
            array($this, 'render_react') // Function
        );

    }

    /**
     * Admin init
     */
    public function admin_init()
    {
        logger("admin_init");
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_scripts($hook)
    {
        $is_inside_rd_app = strpos($hook, 'rippledesk') !== false;
        // Only load on Rippledesk admin pages
        $asset_file = RIPPLEDESK_PLUGIN_PATH . 'build/index.asset.php';
        if (!file_exists($asset_file)) {
            return;
        }

        $asset = include $asset_file;

        wp_enqueue_script(
            'rippledesk-admin',
            plugins_url('build/index.js', __FILE__),
            $asset['dependencies'],
            $asset['version'],
            true
        );

        $rd_response_body = get_option('rd_response_body');
        $parsed_data = json_decode($rd_response_body, true);

        wp_set_script_translations('rippledesk-admin', 'rippledesk', RIPPLEDESK_PLUGIN_PATH . 'languages');
        wp_localize_script('rippledesk-admin', 'RD_AUTHS', $parsed_data);
        wp_localize_script('rippledesk-admin', 'RD_LOAD_LOCATION', $is_inside_rd_app ? "RD_APP" : "OUTSIDE_RD_APP");


        logger("Enqueuing Rippledesk build script with dependencies: " . implode(', ', $asset['dependencies']));

        // importance to load @wordpress/components css
        wp_enqueue_style(
            'rippledesk-admin-css',
            plugins_url('build/index.css', __FILE__),
            array('wp-components'),
            $asset['version']
        );


    }

    /**
     * Get custom menu icon
     */
    private function get_menu_icon($path)
    {
        // Try to use SVG icon first
        $svg_icon_path = RIPPLEDESK_PLUGIN_PATH . $path;
        if (file_exists($svg_icon_path)) {
            $svg_content = file_get_contents($svg_icon_path);
            if ($svg_content) {
                // Encode SVG for data URI
                return 'data:image/svg+xml;base64,' . base64_encode($svg_content);
            }
        }

        // Final fallback to dashicon
        return 'dashicons-phone';
    }

    /**
     * Add settings link to plugin page
     */
    public function add_plugin_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=rippledesk-settings') . '">' . __('Settings', 'rippledesk') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }


    public function render_react()
    {
        $rd_response_body = get_option("rd_response_body");
        if (!$rd_response_body || empty($rd_response_body) || $rd_response_body === null) {
            $this->login_auth();
        }

        printf(
            '<div rdAppVersion="%s" id="rd-app">%s</div>',
            esc_attr(RIPPLEDESK_PLUGIN_VERSION),
            esc_html__('Please wait...', 'rippledesk')
        );
    }

    /**
     * Include or authenticate user
     */
    private function login_auth()
    {
        $current_user = wp_get_current_user();

        $payload = array(
            "email" => $current_user->user_email,
            "domain" => get_site_url(),
            "shopName" => get_bloginfo('name'),
            "noRedirect" => "true",
            "ownerName" => $current_user->display_name,
            "externalAppVersion" => RIPPLEDESK_PLUGIN_VERSION,
            "externalPlatformVersion" => get_bloginfo('version'),
            "hash" => hash('md5', get_site_url()),
        );

        // Use environment variables for API configuration
        $api_url = self::get_env('RD_INTEGRATION_URL');

        // Make GET request with payload as query parameters
        $query_string = http_build_query($payload);
        $full_url = "$api_url/auth/permissions/accepted/wordpress/callback" . '?' . $query_string;

        logger("Making GET request to: " . $full_url);

        $response = wp_remote_get($full_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' Rippledesk/' . RIPPLEDESK_PLUGIN_VERSION
            )
        ));

        if (is_wp_error($response)) {
            logger("Rippledesk API Error: " . $response->get_error_message());
            update_option('rippledesk_integration_last_error', $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);


        logger("Rippledesk API Response Code: " . $response_code);
        logger("Rippledesk API Response Body: " . $response_body);


        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            logger("Rippledesk API Error: " . $error_message);
            update_option('rippledesk_integration_last_error', $error_message);

            // Show admin notice to user for WP_Error
            add_action('admin_notices', function () use ($error_message) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>Rippledesk Installation Error:</strong> Failed to connect to Rippledesk services. ' . esc_html($error_message) . '</p>';
                echo '<p>Please check your internet connection and try again, or contact support if the problem persists.</p>';
                echo '</div>';
            });

            return false;
        }

        logger("Rippledesk installation successful " . $response_body);

        update_option("rd_response_body", $response_body);

        return true;
    }


    // WIDGET LOAD ON SITE //
    public function load_public_frontend_widget_script($hook)
    {
        $rd_response_body = get_option('rd_response_body');
        $parsed_data = json_decode($rd_response_body, true);
        $widget_token = $parsed_data["widgetToken"] ?? null;

        if (!isset($widget_token)) {
            logger("Rippledesk frontend widget script: Widget token not found.");
            return;
        }

        $data_to_pass = array(
            "api_url" => RD_WIDGET_BASE_URL,
            "token" => $widget_token
        );
        wp_enqueue_script("rippledesk-frontend", plugins_url('/widget/script.js', __FILE__), null, "1.0.0", true);
        wp_localize_script('rippledesk-frontend', 'rd_envs', $data_to_pass);
    }

}

// Initialize the plugin
new Rippledesk();



function logger($message)
{
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        // error_log($message);
    }
}