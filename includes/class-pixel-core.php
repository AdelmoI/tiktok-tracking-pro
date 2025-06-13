<?php
/**
 * Classe core per l'inizializzazione del TikTok Pixel
 *
 * @package TikTokTrackingPro
 * @author Adelmo Infante
 */

// Impedisce accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class TTP_Pixel_Core {
    
    /**
     * Costruttore
     */
    public function __construct() {
        // Solo se il tracking è abilitato
        if (!get_option('ttp_enabled', true)) {
            return;
        }
        
        // Verifica che abbiamo le costanti necessarie
        if (!defined('TTP_PIXEL_ID') || !defined('TTP_ACCESS_TOKEN')) {
            return;
        }
        
        // Hook per l'inizializzazione
        add_action('wp_head', array($this, 'define_callback_system'), 1);
        add_action('wp_head', array($this, 'pixel_base_code'), 2);
        add_action('wp_head', array($this, 'block_conflicting_pixels'), 999);
    }
    
    /**
     * Definisce il sistema di callback JavaScript
     */
    public function define_callback_system() {
        ?>
        <script>
        // Sistema callback TikTok Tracking Pro
        window.ttqPixelCallbacks = window.ttqPixelCallbacks || [];
        window.ttqPixelReady = false;
        window.ttqTrackedEvents = window.ttqTrackedEvents || new Set();
        
        // Blocca pixel preesistenti
        if (typeof ttq !== 'undefined') {
            window.originalTtq = ttq;
        }
        
        // Blocca auto-tracking WooCommerce/altri plugin TikTok
        window.wc_tiktok_pixel = null;
        window.wcTikTokPixel = null;
        
        if (typeof jQuery !== 'undefined') {
            jQuery(document).ready(function() {
                jQuery(document).off('click.wc-tiktok-pixel');
                jQuery(document).off('added_to_cart.wc-tiktok-pixel');
                
                if (window.wc_tiktok_pixel_options) {
                    window.wc_tiktok_pixel_options = null;
                }
            });
        }
        
        // Funzione principale per callback
        window.onTtqPixelReady = function(callback) {
            if (window.ttqPixelReady) {
                try {
                    callback();
                } catch(e) {
                    console.error('TTP Callback Error:', e);
                }
            } else {
                window.ttqPixelCallbacks.push(callback);
            }
        };
        
        // Funzione per tracking con deduplicazione
        window.ttqTrackEvent = function(eventName, params, source) {
            var timestamp = Date.now();
            var eventId = eventName.toLowerCase() + '_' + timestamp + '_' + Math.random().toString(36).substr(2, 9);
            
            var eventKey = eventName + '_' + JSON.stringify(params) + '_' + (source || '');
            var eventHash = btoa(eventKey).replace(/[^a-zA-Z0-9]/g, '').substring(0, 20);
            
            if (window.ttqTrackedEvents.has(eventHash)) {
                return false;
            }
            
            window.ttqTrackedEvents.add(eventHash);
            
            setTimeout(function() {
                window.ttqTrackedEvents.delete(eventHash);
            }, 30000);
            
            ttq('track', eventName, params);
            return true;
        };
        
        // Monitor eventi per bloccare auto-tracking
        window.ttqEventMonitor = function() {
            if (typeof ttq !== 'undefined' && !window.ttqOverridden) {
                window.ttqOverridden = true;
                var originalTtq = ttq;
                
                window.ttq = function() {
                    var args = Array.prototype.slice.call(arguments);
                    var eventType = args[0];
                    var eventName = args[1];
                    var params = args[2] || {};
                    
                    // Blocca eventi auto-trackati senza identificazione TTP
                    if (eventType === 'track' && ['AddToCart', 'Purchase', 'InitiateCheckout'].includes(eventName)) {
                        if (!params.ttp_tracked) {
                            return;
                        }
                        delete params.ttp_tracked; // Rimuovi il flag prima dell'invio
                    }
                    
                    return originalTtq.apply(this, arguments);
                };
                
                // Copia proprietà dell'ttq originale
                for (var prop in originalTtq) {
                    if (originalTtq.hasOwnProperty(prop)) {
                        window.ttq[prop] = originalTtq[prop];
                    }
                }
            }
        };
        </script>
        <?php
    }
    
    /**
     * Codice base del TikTok Pixel
     */
    public function pixel_base_code() {
        ?>
        <script>
        // TikTok Pixel Base Code - TikTok Tracking Pro
        !function (w, d, t) {
            w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(
            var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script")
            ;n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};
        
            function initializeTikTokPixel() {
                try {
                    if (typeof ttq !== 'undefined' && ttq.load) {
                        // Inizializza pixel
                        ttq.load('<?php echo TTP_PIXEL_ID; ?>');
                        ttq.page();
            
                        // Rimuovi script WooCommerce conflittuali
                        if (typeof jQuery !== 'undefined') {
                            jQuery(document).off('click', '.single_add_to_cart_button');
                            jQuery(document).off('added_to_cart');
                            
                            jQuery('script').each(function() {
                                var scriptText = jQuery(this).text();
                                if (scriptText.includes('ttq') && scriptText.includes('AddToCart') && !scriptText.includes('TTP')) {
                                    jQuery(this).remove();
                                }
                            });
                        }
                        
                        // Attiva monitor eventi
                        setTimeout(function() {
                            if (typeof window.ttqEventMonitor === 'function') {
                                window.ttqEventMonitor();
                            }
                        }, 100);
                        
                        // Notifica pixel pronto
                        window.ttqPixelReady = true;
                        
                        // Esegui callback in coda
                        if (window.ttqPixelCallbacks && window.ttqPixelCallbacks.length > 0) {
                            var callbacksToExecute = window.ttqPixelCallbacks.slice();
                            window.ttqPixelCallbacks = [];
                            
                            callbacksToExecute.forEach(function(callback) {
                                try {
                                    callback();
                                } catch(e) {
                                    console.error('TTP Callback Error:', e);
                                }
                            });
                        }
                    } else {
                        setTimeout(initializeTikTokPixel, 500);
                    }
                } catch(e) {
                    console.error('TTP Pixel Init Error:', e);
                    setTimeout(initializeTikTokPixel, 1000);
                }
            }
            
            // Avvia inizializzazione
            ttq.load('<?php echo TTP_PIXEL_ID; ?>');
            ttq.page();
            initializeTikTokPixel();
        }(window, document, 'ttq');
        </script>
        <noscript>
        <img height="1" width="1" style="display:none"
        src="https://analytics.tiktok.com/i18n/pixel/track.png?sdkid=<?php echo TTP_PIXEL_ID; ?>&e=PageView"/>
        </noscript>
        <?php
    }
    
    /**
     * Blocca pixel conflittuali
     */
    public function block_conflicting_pixels() {
        ?>
        <script>
        // Blocca pixel TikTok nativi di altri plugin - TikTok Tracking Pro
        if (typeof wc_tiktok_pixel !== 'undefined') {
            wc_tiktok_pixel = null;
        }
        
        jQuery(document).ready(function($) {
            // Rimuovi script TikTok di altri plugin
            $('script[src*="tiktok"]').not('[src*="analytics.tiktok.com"]').remove();
            $('script').filter(function() {
                return $(this).text().includes('wc_tiktok_pixel') || 
                       ($(this).text().includes('ttq') && $(this).text().includes('AddToCart') && !$(this).text().includes('TTP'));
            }).remove();
        });
        </script>
        <?php
    }
    
    /**
     * Ottieni il Pixel ID configurato
     */
    public static function get_pixel_id() {
        return defined('TTP_PIXEL_ID') ? TTP_PIXEL_ID : '';
    }
    
    /**
     * Verifica se il pixel è inizializzato
     */
    public static function is_pixel_ready() {
        return defined('TTP_PIXEL_ID') && defined('TTP_ACCESS_TOKEN') && get_option('ttp_enabled', true);
    }
    
    /**
     * Genera un event ID unico
     */
    public static function generate_event_id($event_name, $additional_data = '') {
        return strtolower($event_name) . '_' . time() . '_' . substr(md5($additional_data . uniqid()), 0, 8);
    }
}