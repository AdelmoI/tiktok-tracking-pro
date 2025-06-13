<?php
/**
 * Classe per le impostazioni admin del plugin
 *
 * @package TikTokTrackingPro
 * @author Adelmo Infante
 */

// Impedisce accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class TTP_Admin_Settings {
    
    /**
     * Costruttore
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
    }
    
    /**
     * Aggiunge il menu admin
     */
    public function add_admin_menu() {
        add_options_page(
            __('TikTok Tracking Pro', 'tiktok-tracking-pro'),
            __('TikTok Tracking Pro', 'tiktok-tracking-pro'),
            'manage_options',
            'tiktok-tracking-pro',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Registra le impostazioni
     */
    public function register_settings() {
        // Gruppo di impostazioni
        register_setting('ttp_settings', 'ttp_pixel_id', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('ttp_settings', 'ttp_access_token', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('ttp_settings', 'ttp_api_version', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'v1.3'
        ));
        register_setting('ttp_settings', 'ttp_test_event_code', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('ttp_settings', 'ttp_enabled', array(
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => true
        ));
    }
    
    /**
     * Sanitizza checkbox
     */
    public function sanitize_checkbox($input) {
        return $input ? true : false;
    }
    
    /**
     * Carica script admin
     */
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_tiktok-tracking-pro' !== $hook) {
            return;
        }
        
        wp_enqueue_style('ttp-admin-style', TTP_PLUGIN_URL . 'assets/admin.css', array(), TTP_VERSION);
    }
    
    /**
     * Mostra avvisi admin
     */
    public function show_admin_notices() {
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_tiktok-tracking-pro') {
            return;
        }
        
        // Verifica configurazione
        if (!TikTokTrackingPro::is_configured()) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e('Configurazione incompleta', 'tiktok-tracking-pro'); ?></strong><br>
                    <?php _e('Inserisci Pixel ID e Access Token per attivare il tracking.', 'tiktok-tracking-pro'); ?>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Pagina delle impostazioni
     */
    public function admin_page() {
        // Verifica permessi
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.', 'tiktok-tracking-pro'));
        }
        
        // Test connessione se richiesto
        $test_result = null;
        if (isset($_GET['test_connection']) && wp_verify_nonce($_GET['_wpnonce'], 'ttp_test_connection')) {
            $test_result = $this->test_api_connection();
        }
        ?>
        <div class="wrap">
            <h1><?php _e('TikTok Tracking Pro - Impostazioni', 'tiktok-tracking-pro'); ?></h1>
            
            <div class="ttp-admin-container">
                
                <!-- Status Card -->
                <div class="ttp-status-card">
                    <h3><?php _e('Status Configurazione', 'tiktok-tracking-pro'); ?></h3>
                    <?php if (TikTokTrackingPro::is_configured()): ?>
                        <div class="ttp-status-ok">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php _e('Plugin configurato e attivo', 'tiktok-tracking-pro'); ?>
                        </div>
                    <?php else: ?>
                        <div class="ttp-status-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <?php _e('Configurazione incompleta', 'tiktok-tracking-pro'); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Form Impostazioni -->
                <form method="post" action="options.php">
                    <?php settings_fields('ttp_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ttp_enabled"><?php _e('Attiva Tracking', 'tiktok-tracking-pro'); ?></label>
                            </th>
                            <td>
                                <label class="ttp-switch">
                                    <input type="checkbox" id="ttp_enabled" name="ttp_enabled" value="1" 
                                           <?php checked(get_option('ttp_enabled', true)); ?>>
                                    <span class="ttp-slider"></span>
                                </label>
                                <p class="description"><?php _e('Attiva o disattiva il tracking TikTok.', 'tiktok-tracking-pro'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="ttp_pixel_id"><?php _e('Pixel ID', 'tiktok-tracking-pro'); ?> *</label>
                            </th>
                            <td>
                                <input type="text" id="ttp_pixel_id" name="ttp_pixel_id" 
                                       value="<?php echo esc_attr(get_option('ttp_pixel_id')); ?>" 
                                       class="regular-text" placeholder="CQ2MDBBC77U1207KJ2B0" required>
                                <p class="description">
                                    <?php _e('ID del tuo TikTok Pixel.', 'tiktok-tracking-pro'); ?>
                                    <a href="https://ads.tiktok.com/i18n/pixel" target="_blank">
                                        <?php _e('Trova il tuo Pixel ID', 'tiktok-tracking-pro'); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="ttp_access_token"><?php _e('Access Token', 'tiktok-tracking-pro'); ?> *</label>
                            </th>
                            <td>
                                <input type="password" id="ttp_access_token" name="ttp_access_token" 
                                       value="<?php echo esc_attr(get_option('ttp_access_token')); ?>" 
                                       class="regular-text" placeholder="b17ba38f180b958a4d406f10ae00e50a02cc32f9" required>
                                <button type="button" class="button ttp-show-password" onclick="ttpTogglePassword('ttp_access_token')">
                                    <?php _e('Mostra', 'tiktok-tracking-pro'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('Token per TikTok Events API (server-side tracking).', 'tiktok-tracking-pro'); ?>
                                    <a href="https://business-api.tiktok.com/portal/docs?id=1771101130925058" target="_blank">
                                        <?php _e('Genera Access Token', 'tiktok-tracking-pro'); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="ttp_api_version"><?php _e('Versione API', 'tiktok-tracking-pro'); ?></label>
                            </th>
                            <td>
                                <select id="ttp_api_version" name="ttp_api_version">
                                    <option value="v1.3" <?php selected(get_option('ttp_api_version', 'v1.3'), 'v1.3'); ?>>v1.3</option>
                                    <option value="v1.2" <?php selected(get_option('ttp_api_version'), 'v1.2'); ?>>v1.2</option>
                                    <option value="v1.1" <?php selected(get_option('ttp_api_version'), 'v1.1'); ?>>v1.1</option>
                                </select>
                                <p class="description"><?php _e('Versione TikTok Business API da utilizzare.', 'tiktok-tracking-pro'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="ttp_test_event_code"><?php _e('Test Event Code', 'tiktok-tracking-pro'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="ttp_test_event_code" name="ttp_test_event_code" 
                                       value="<?php echo esc_attr(get_option('ttp_test_event_code')); ?>" 
                                       class="regular-text" placeholder="TEST12345">
                                <p class="description">
                                    <?php _e('Codice per testare gli eventi (opzionale, solo durante sviluppo).', 'tiktok-tracking-pro'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Salva Impostazioni', 'tiktok-tracking-pro')); ?>
                </form>
                
                <!-- Test Connessione -->
                <?php if (TikTokTrackingPro::is_configured()): ?>
                <div class="ttp-test-section">
                    <h3><?php _e('Test Connessione', 'tiktok-tracking-pro'); ?></h3>
                    <p><?php _e('Verifica che la connessione con TikTok funzioni correttamente.', 'tiktok-tracking-pro'); ?></p>
                    
                    <?php if ($test_result !== null): ?>
                        <div class="notice notice-<?php echo $test_result['success'] ? 'success' : 'error'; ?> inline">
                            <p><?php echo esc_html($test_result['message']); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <a href="<?php echo wp_nonce_url(add_query_arg('test_connection', '1'), 'ttp_test_connection'); ?>" 
                       class="button button-secondary">
                        <?php _e('Testa Connessione', 'tiktok-tracking-pro'); ?>
                    </a>
                </div>
                <?php endif; ?>
                
                <!-- Info Plugin -->
                <div class="ttp-info-section">
                    <h3><?php _e('Informazioni Plugin', 'tiktok-tracking-pro'); ?></h3>
                    <table class="ttp-info-table">
                        <tr>
                            <td><strong><?php _e('Versione:', 'tiktok-tracking-pro'); ?></strong></td>
                            <td><?php echo TTP_VERSION; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Autore:', 'tiktok-tracking-pro'); ?></strong></td>
                            <td>Adelmo Infante</td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Sito:', 'tiktok-tracking-pro'); ?></strong></td>
                            <td><a href="https://ilcovodelnerd.com" target="_blank">Il Covo del Nerd</a></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('WooCommerce:', 'tiktok-tracking-pro'); ?></strong></td>
                            <td><?php echo class_exists('WooCommerce') ? WC()->version : __('Non installato', 'tiktok-tracking-pro'); ?></td>
                        </tr>
                    </table>
                </div>
                
            </div>
        </div>
        
        <style>
        .ttp-admin-container { max-width: 800px; }
        .ttp-status-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; }
        .ttp-status-ok { color: #46b450; font-weight: bold; }
        .ttp-status-ok .dashicons { color: #46b450; }
        .ttp-status-warning { color: #ffb900; font-weight: bold; }
        .ttp-status-warning .dashicons { color: #ffb900; }
        .ttp-switch { position: relative; display: inline-block; width: 60px; height: 34px; }
        .ttp-switch input { opacity: 0; width: 0; height: 0; }
        .ttp-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .ttp-slider:before { position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .ttp-slider { background-color: #ff0050; }
        input:checked + .ttp-slider:before { transform: translateX(26px); }
        .ttp-test-section, .ttp-info-section { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; }
        .ttp-info-table { width: 100%; }
        .ttp-info-table td { padding: 5px 0; }
        .ttp-show-password { margin-left: 10px; }
        </style>
        
        <script>
        function ttpTogglePassword(fieldId) {
            var field = document.getElementById(fieldId);
            var button = field.nextElementSibling;
            if (field.type === "password") {
                field.type = "text";
                button.textContent = "<?php _e('Nascondi', 'tiktok-tracking-pro'); ?>";
            } else {
                field.type = "password";
                button.textContent = "<?php _e('Mostra', 'tiktok-tracking-pro'); ?>";
            }
        }
        </script>
        <?php
    }
    
    /**
     * Testa la connessione API - VERSIONE TikTok
     */
    private function test_api_connection() {
        $pixel_id = get_option('ttp_pixel_id');
        $access_token = get_option('ttp_access_token');
        $api_version = get_option('ttp_api_version', 'v1.3');
        
        if (empty($pixel_id) || empty($access_token)) {
            return array(
                'success' => false,
                'message' => __('Pixel ID o Access Token mancante.', 'tiktok-tracking-pro')
            );
        }
        
        // Test con invio evento di prova TikTok
        $test_url = "https://business-api.tiktok.com/open_api/{$api_version}/pixel/track/";
        
        $test_event = array(
            'event_source' => 'web',
            'event_source_id' => $pixel_id,
            'data' => array(array(
                'event' => 'ViewContent',
                'event_time' => time(),
                'user' => array(
                    'external_id' => hash('sha256', 'test_user_' . time())
                ),
                'properties' => array(
                    'content_type' => 'product',
                    'currency' => 'EUR'
                ),
                'page' => array(
                    'url' => home_url(),
                    'referrer' => ''
                )
            ))
        );
        
        // Aggiungi test event code se presente
        $test_code = get_option('ttp_test_event_code', '');
        if (!empty($test_code)) {
            $test_event['test_event_code'] = $test_code;
        }
        
        $response = wp_remote_post($test_url, array(
            'timeout' => 10,
            'sslverify' => true,
            'body' => json_encode($test_event),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Access-Token' => $access_token
            )
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => sprintf(__('Errore connessione: %s', 'tiktok-tracking-pro'), $response->get_error_message())
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['code']) && $data['code'] == 0) {
            $message = __('✅ Connessione API riuscita! TikTok Events API operativo.', 'tiktok-tracking-pro');
            
            if (!empty($test_code)) {
                $message .= __(' (modalità test attiva)', 'tiktok-tracking-pro');
            }
            
            return array(
                'success' => true,
                'message' => $message
            );
        }
        
        if (isset($data['message'])) {
            return array(
                'success' => false,
                'message' => sprintf(__('Errore API: %s', 'tiktok-tracking-pro'), $data['message'])
            );
        }
        
        return array(
            'success' => false,
            'message' => __('Risposta API non valida', 'tiktok-tracking-pro')
        );
    }
}