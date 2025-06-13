<?php
/**
 * Plugin Name: TikTok Tracking Pro
 * Plugin URI: https://ilcovodelnerd.com
 * Description: Sistema di tracking TikTok professionale e ottimizzato per WooCommerce. Include tracking completo degli eventi ecommerce con Events API.
 * Version: 1.0.0
 * Author: Adelmo Infante
 * Author URI: https://ilcovodelnerd.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tiktok-tracking-pro
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 *
 * @package TikTokTrackingPro
 * @author Adelmo Infante
 */

// Impedisce accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Definisce costanti del plugin
define('TTP_VERSION', '1.0.0');
define('TTP_PLUGIN_FILE', __FILE__);
define('TTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TTP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TTP_INCLUDES_DIR', TTP_PLUGIN_DIR . 'includes/');

/**
 * Classe principale del plugin
 */
class TikTokTrackingPro {
    
    /**
     * Istanza singleton
     */
    private static $instance = null;
    
    /**
     * Ottiene l'istanza singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Costruttore
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Inizializzazione del plugin
     */
    public function init() {
        // Verifica che WooCommerce sia attivo
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Carica le classi del plugin
        $this->load_includes();
        
        // Inizializza i componenti
        $this->init_components();
        
        // Hook per internazionalizzazione
        add_action('init', array($this, 'load_textdomain'));
    }
    
    /**
     * Carica i file delle classi
     */
    private function load_includes() {
        require_once TTP_INCLUDES_DIR . 'class-admin-settings.php';
        require_once TTP_INCLUDES_DIR . 'class-pixel-core.php';
        require_once TTP_INCLUDES_DIR . 'class-events-tracking.php';
        require_once TTP_INCLUDES_DIR . 'class-api-server.php';
    }
    
    /**
     * Inizializza i componenti del plugin
     */
    private function init_components() {
        // Solo se abbiamo le impostazioni necessarie
        $pixel_id = get_option('ttp_pixel_id');
        $access_token = get_option('ttp_access_token');
        
        if ($pixel_id && $access_token) {
            // Definisce le costanti per il tracking
            if (!defined('TTP_PIXEL_ID')) {
                define('TTP_PIXEL_ID', $pixel_id);
            }
            if (!defined('TTP_ACCESS_TOKEN')) {
                define('TTP_ACCESS_TOKEN', $access_token);
            }
            if (!defined('TTP_API_VERSION')) {
                define('TTP_API_VERSION', get_option('ttp_api_version', 'v1.3'));
            }
            if (!defined('TTP_TEST_EVENT_CODE')) {
                define('TTP_TEST_EVENT_CODE', get_option('ttp_test_event_code', ''));
            }
            
            // Inizializza il tracking solo se configurato
            new TTP_Pixel_Core();
            new TTP_Events_Tracking();
            new TTP_API_Server();
        }
        
        // Admin sempre attivo
        if (is_admin()) {
            new TTP_Admin_Settings();
        }
    }
    
    /**
     * Carica la traduzione
     */
    public function load_textdomain() {
        load_plugin_textdomain('tiktok-tracking-pro', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Attivazione del plugin
     */
    public function activate() {
        // Crea opzioni predefinite
        $default_options = array(
            'ttp_pixel_id' => '',
            'ttp_access_token' => '',
            'ttp_api_version' => 'v1.3',
            'ttp_test_event_code' => '',
            'ttp_enabled' => true
        );
        
        foreach ($default_options as $option => $value) {
            if (false === get_option($option)) {
                add_option($option, $value);
            }
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Disattivazione del plugin
     */
    public function deactivate() {
        // Non eliminiamo le opzioni in caso di riattivazione
        flush_rewrite_rules();
    }
    
    /**
     * Avviso WooCommerce mancante
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('TikTok Tracking Pro', 'tiktok-tracking-pro'); ?></strong> 
                <?php _e('richiede WooCommerce per funzionare. Per favore installa e attiva WooCommerce.', 'tiktok-tracking-pro'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Ottiene l'URL delle impostazioni
     */
    public static function get_settings_url() {
        return admin_url('admin.php?page=tiktok-tracking-pro');
    }
    
    /**
     * Verifica se il plugin è configurato
     */
    public static function is_configured() {
        $pixel_id = get_option('ttp_pixel_id');
        $access_token = get_option('ttp_access_token');
        return !empty($pixel_id) && !empty($access_token);
    }
}

/**
 * Funzione helper per ottenere l'istanza del plugin
 */
function tiktok_tracking_pro() {
    return TikTokTrackingPro::get_instance();
}

// Inizializza il plugin
tiktok_tracking_pro();

/**
 * Hook per aggiungere link nelle azioni del plugin
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . TikTokTrackingPro::get_settings_url() . '">' . __('Impostazioni', 'tiktok-tracking-pro') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

/**
 * Controllo compatibilità al caricamento
 */
add_action('admin_init', function() {
    // Verifica versione PHP
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>TikTok Tracking Pro</strong> richiede PHP 7.4 o superiore. Versione attuale: ' . PHP_VERSION . '</p></div>';
        });
        return;
    }
    
    // Verifica versione WordPress
    global $wp_version;
    if (version_compare($wp_version, '5.0', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>TikTok Tracking Pro</strong> richiede WordPress 5.0 o superiore. Versione attuale: ' . $GLOBALS['wp_version'] . '</p></div>';
        });
        return;
    }
});