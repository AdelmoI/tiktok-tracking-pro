<?php
/**
 * Classe per il tracking degli eventi ecommerce TikTok
 *
 * @package TikTokTrackingPro
 * @author Adelmo Infante
 */

// Impedisce accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class TTP_Events_Tracking {
    
    /**
     * Costruttore
     */
    public function __construct() {
        // Solo se il tracking è abilitato e configurato
        if (!get_option('ttp_enabled', true) || !TTP_Pixel_Core::is_pixel_ready()) {
            return;
        }
        
        // Hook per gli eventi
        add_action('wp_footer', array($this, 'track_view_content'));
        add_action('wp_footer', array($this, 'track_add_to_cart_searchanise'));
        add_action('wp_footer', array($this, 'track_single_product_add_to_cart'));
        add_action('wp_footer', array($this, 'track_search_events'));
        add_action('wp_footer', array($this, 'track_high_interest'));
        add_action('woocommerce_after_checkout_form', array($this, 'track_initiate_checkout'));
        add_action('woocommerce_thankyou', array($this, 'track_purchase'));
        
        // AJAX handlers
        add_action('wp_ajax_ttp_track_add_to_cart_server', array($this, 'handle_add_to_cart_server'));
        add_action('wp_ajax_nopriv_ttp_track_add_to_cart_server', array($this, 'handle_add_to_cart_server'));
        add_action('wp_ajax_ttp_track_search_server', array($this, 'handle_search_server'));
        add_action('wp_ajax_nopriv_ttp_track_search_server', array($this, 'handle_search_server'));
        add_action('wp_ajax_ttp_track_checkout_server', array($this, 'handle_checkout_server'));
        add_action('wp_ajax_nopriv_ttp_track_checkout_server', array($this, 'handle_checkout_server'));
        
        // Ottimizzazioni
        add_filter('woocommerce_loop_add_to_cart_link', array($this, 'add_product_data_attributes'), 10, 2);
    }
    
    /**
     * Traccia ViewContent sulle pagine prodotto
     */
    public function track_view_content() {
        if (!is_product()) return;
        
        global $product;
        if (!$product) return;
        
        $content_ids = array($product->get_id());
        $content_name = $product->get_name();
        $content_category = '';
        
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        if (!empty($categories) && !is_wp_error($categories)) {
            $content_category = $categories[0]->name;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            if (typeof ttq !== 'undefined') {
                var eventData = {
                    contents: [{
                        content_id: '<?php echo $product->get_id(); ?>',
                        content_type: 'product',
                        content_name: '<?php echo esc_js($content_name); ?>'
                    }],
                    value: <?php echo $product->get_price() ?: 0; ?>,
                    currency: '<?php echo get_woocommerce_currency(); ?>',
                    content_type: 'product'
                };
                
                ttq('track', 'ViewContent', eventData);
                console.log('TikTok ViewContent tracked:', eventData);
            }
        });
        </script>
        <?php
        
        // Server-side tracking
        TTP_API_Server::send_event('ViewContent', array(
            'content_ids' => $content_ids,
            'content_name' => $content_name,
            'content_category' => $content_category,
            'content_type' => 'product',
            'value' => $product->get_price() ?: 0,
            'currency' => get_woocommerce_currency()
        ));
    }
    
    /**
     * Traccia AddToCart per Searchanise (solo su shop/categorie)
     */
    public function track_add_to_cart_searchanise() {
        if (!(is_shop() || is_product_category() || is_product_tag() || is_search() || (isset($_GET['se']) && $_GET['se']))) {
            return;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            var originalXHR = window.XMLHttpRequest;
            var originalOpen = originalXHR.prototype.open;
            var originalSend = originalXHR.prototype.send;
            
            if (!window.ttpSearchaniseIntercepted) {
                window.ttpSearchaniseIntercepted = true;
                
                originalXHR.prototype.open = function(method, url, async, user, password) {
                    this._url = url;
                    this._method = method;
                    return originalOpen.apply(this, arguments);
                };
                
                originalXHR.prototype.send = function(data) {
                    var self = this;
                    
                    var isSearchaniseAddToCart = this._url && (
                        (this._url.includes('se_ajax_add_to_cart') && this._url.includes('product_id=')) ||
                        this._url.includes('snize') ||
                        this._url.includes('searchanise')
                    ) && 
                    !(this._url && (
                        this._url.includes('get_refreshed_fragments') ||
                        this._url.includes('search-results') ||
                        this._url.includes('se_get_results') ||
                        (this._url.includes('admin-ajax.php') && data && (
                            data.includes('ttp_track_add_to_cart_server') ||
                            data.includes('ttp_track_search_server')
                        ))
                    ));
                    
                    if (isSearchaniseAddToCart) {
                        try {
                            var product_id = null;
                            var quantity = 1;
                            
                            var urlPatterns = [
                                /[?&]product_id=(\d+)/i,
                                /se_ajax_add_to_cart.*product_id[=:](\d+)/i
                            ];
                            
                            for (var i = 0; i < urlPatterns.length && !product_id; i++) {
                                var match = this._url.match(urlPatterns[i]);
                                if (match && match[1]) {
                                    product_id = match[1];
                                    break;
                                }
                            }
                            
                            var qtyMatch = this._url.match(/[?&](?:quantity|qty)=(\d+)/i);
                            if (qtyMatch && qtyMatch[1]) {
                                quantity = parseInt(qtyMatch[1]);
                            }
                            
                            if (product_id) {
                                self.addEventListener('load', function() {
                                    if (self.status === 200) {
                                        var product_name = '';
                                        var product_price = 0;
                                        
                                        var selectors = [
                                            '#snize-product-' + product_id,
                                            '[data-original-product-id="' + product_id + '"]',
                                            '[data-snize-product-id="' + product_id + '"]',
                                            '.snize-product[data-id="' + product_id + '"]',
                                            '[data-product-id="' + product_id + '"]'
                                        ];
                                        
                                        var $productElement = null;
                                        for (var k = 0; k < selectors.length && !$productElement; k++) {
                                            var elements = $(selectors[k]);
                                            if (elements.length > 0) {
                                                $productElement = elements.first();
                                                break;
                                            }
                                        }
                                        
                                        if ($productElement && $productElement.length > 0) {
                                            var nameElement = $productElement.find('.snize-title, .snize-product-title, .product-title').first();
                                            if (nameElement.length > 0) {
                                                product_name = nameElement.text().trim();
                                            }
                                            
                                            var priceElement = $productElement.find('.snize-price, .price .amount').first();
                                            if (priceElement.length > 0) {
                                                var priceText = priceElement.text().trim();
                                                
                                                if (priceText) {
                                                    var priceMatch = priceText.match(/[\d.,]+/);
                                                    if (priceMatch) {
                                                        var priceString = priceMatch[0];
                                                        
                                                        if (priceString.includes('.') && priceString.includes(',')) {
                                                            priceString = priceString.replace(/\./g, '').replace(',', '.');
                                                        } 
                                                        else if (priceString.includes(',') && !priceString.includes('.')) {
                                                            priceString = priceString.replace(',', '.');
                                                        }
                                                        
                                                        product_price = parseFloat(priceString);
                                                        if (isNaN(product_price)) {
                                                            product_price = 0;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        
                                        if (typeof ttq !== 'undefined') {
                                            var trackingParams = {
                                                contents: [{
                                                    content_id: String(product_id),
                                                    content_type: 'product',
                                                    content_name: (product_name || '').trim()
                                                }],
                                                value: parseFloat(product_price || 0) * parseInt(quantity),
                                                currency: 'EUR'
                                            };
                                            
                                            ttq('track', 'AddToCart', trackingParams);
                                            console.log('TikTok Searchanise AddToCart tracked:', trackingParams);
                                        }
                                        
                                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                                            action: 'ttp_track_add_to_cart_server',
                                            product_id: product_id,
                                            product_price: product_price || 0,
                                            product_name: product_name || '',
                                            quantity: quantity,
                                            source: 'searchanise_ajax',
                                            nonce: '<?php echo wp_create_nonce('ttp_tracking_nonce'); ?>'
                                        });
                                    }
                                });
                            }
                            
                        } catch(e) {
                            console.error('TTP Searchanise Error:', e);
                        }
                    }
                    
                    return originalSend.apply(this, arguments);
                };
            }
        });
        </script>
        <?php
    }
    
    /**
     * Traccia AddToCart per prodotti singoli WooCommerce
     */
    public function track_single_product_add_to_cart() {
        if (!is_product()) return;
        
        global $product;
        if (!$product) return;
        
        $product_data = array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'price' => $product->get_price() ?: 0,
            'currency' => get_woocommerce_currency(),
            'type' => $product->get_type()
        );
        
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        $product_data['category'] = '';
        if (!empty($categories) && !is_wp_error($categories)) {
            $product_data['category'] = $categories[0]->name;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            var productData = <?php echo json_encode($product_data); ?>;
            
            // Tracking AddToCart per prodotti singoli
            $(document).on('click', '.single_add_to_cart_button, button[name="add-to-cart"], .single-product .cart button[type="submit"]', function(e) {
                try {
                    var $button = $(this);
                    var product_id = $button.val() || $button.attr('value') || $button.data('product_id') || productData.id;
                    var quantity = 1;
                    
                    var $form = $button.closest('form');
                    if ($form.length > 0) {
                        var qtyInput = $form.find('input[name="quantity"], .qty').val();
                        if (qtyInput) {
                            quantity = parseInt(qtyInput) || 1;
                        }
                    }
                    
                    if (product_id && typeof ttq !== 'undefined') {
                        var trackingParams = {
                            contents: [{
                                content_id: String(product_id),
                                content_type: 'product',
                                content_name: productData.name || ''
                            }],
                            value: parseFloat(productData.price || 0) * parseInt(quantity),
                            currency: productData.currency || 'EUR'
                        };
                        
                        // Traccia l'evento TikTok
                        ttq('track', 'AddToCart', trackingParams);
                        console.log('TikTok AddToCart tracked:', trackingParams);
                        
                        // Server-side tracking
                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'ttp_track_add_to_cart_server',
                            product_id: product_id,
                            product_price: productData.price || 0,
                            product_name: productData.name || '',
                            quantity: quantity,
                            source: 'woocommerce_single_product',
                            nonce: '<?php echo wp_create_nonce('ttp_tracking_nonce'); ?>'
                        });
                    }
                    
                } catch(e) {
                    console.error('TTP WooCommerce Single Error:', e);
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Traccia eventi di ricerca
     */
    public function track_search_events() {
        if (!(is_shop() || is_product_category() || is_search())) {
            return;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Ricerca da URL
            if (window.location.pathname.includes('search-results') || window.location.search.includes('q=')) {
                var urlParams = new URLSearchParams(window.location.search);
                var searchQuery = urlParams.get('q') || urlParams.get('s') || urlParams.get('search');
                
                if (searchQuery && searchQuery.trim() && typeof ttq !== 'undefined') {
                    var searchData = {
                        search_string: searchQuery.trim()
                    };
                    
                    ttq('track', 'Search', searchData);
                    console.log('TikTok Search tracked (URL):', searchData);
                    
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'ttp_track_search_server',
                        search_query: searchQuery.trim(),
                        source: 'url_page_load',
                        nonce: '<?php echo wp_create_nonce('ttp_tracking_nonce'); ?>'
                    });
                }
            }
            
            // Intercettazione AJAX ricerche
            var originalXHR = window.XMLHttpRequest;
            var originalOpen = originalXHR.prototype.open;
            var originalSend = originalXHR.prototype.send;
            
            if (!window.ttpSearchIntercepted) {
                window.ttpSearchIntercepted = true;
                
                originalXHR.prototype.open = function(method, url, async, user, password) {
                    this._url = url;
                    this._method = method;
                    this._data = null;
                    return originalOpen.apply(this, arguments);
                };
                
                originalXHR.prototype.send = function(data) {
                    var self = this;
                    this._data = data;
                    
                    var isSearchRequest = this._url && (
                        this._url.includes('searchanise') ||
                        this._url.includes('snize') ||
                        this._url.includes('se_') ||
                        this._url.includes('search-results') ||
                        this._url.includes('/search') ||
                        (this._url.includes('admin-ajax.php') && data && (
                            data.includes('search') || 
                            data.includes('query') ||
                            data.includes('q=')
                        )) ||
                        (this._url.includes('?') && (
                            this._url.includes('q=') ||
                            this._url.includes('search=') ||
                            this._url.includes('query=')
                        ))
                    );
                    
                    if (isSearchRequest) {
                        var searchQuery = '';
                        
                        try {
                            if (this._url.includes('?')) {
                                var urlParts = this._url.split('?');
                                if (urlParts[1]) {
                                    var urlParams = new URLSearchParams(urlParts[1]);
                                    searchQuery = urlParams.get('q') || 
                                                 urlParams.get('s') || 
                                                 urlParams.get('query') || 
                                                 urlParams.get('search') ||
                                                 urlParams.get('term');
                                }
                            }
                            
                            if (!searchQuery && data) {
                                try {
                                    var params = new URLSearchParams(data);
                                    searchQuery = params.get('q') || 
                                                 params.get('s') || 
                                                 params.get('query') || 
                                                 params.get('search') ||
                                                 params.get('term');
                                } catch(e) {
                                    var patterns = [
                                        /[?&]q=([^&]+)/i,
                                        /[?&]s=([^&]+)/i,
                                        /[?&]query=([^&]+)/i,
                                        /[?&]search=([^&]+)/i,
                                        /"q"\s*:\s*"([^"]+)"/i
                                    ];
                                    
                                    for (var i = 0; i < patterns.length && !searchQuery; i++) {
                                        var match = data.match(patterns[i]);
                                        if (match && match[1]) {
                                            searchQuery = decodeURIComponent(match[1]);
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            if (searchQuery && searchQuery.trim() && searchQuery.length >= 2) {
                                self.addEventListener('load', function() {
                                    if (self.status >= 200 && self.status < 400) {
                                        var eventKey = 'search_' + searchQuery.trim().toLowerCase();
                                        var now = Date.now();
                                        
                                        if (!window.lastSearchEvents) {
                                            window.lastSearchEvents = {};
                                        }
                                        
                                        if (window.lastSearchEvents[eventKey] && (now - window.lastSearchEvents[eventKey]) < 2000) {
                                            return;
                                        }
                                        
                                        window.lastSearchEvents[eventKey] = now;
                                        
                                        if (typeof ttq !== 'undefined') {
                                            var searchData = {
                                                search_string: searchQuery.trim()
                                            };
                                            
                                            ttq('track', 'Search', searchData);
                                            console.log('TikTok Search tracked (AJAX):', searchData);
                                        }
                                        
                                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                                            action: 'ttp_track_search_server',
                                            search_query: searchQuery.trim(),
                                            source: 'searchanise_ajax_success',
                                            nonce: '<?php echo wp_create_nonce('ttp_tracking_nonce'); ?>'
                                        });
                                    }
                                });
                            }
                            
                        } catch(e) {
                            console.error('TTP Search Error:', e);
                        }
                    }
                    
                    return originalSend.apply(this, arguments);
                };
            }
            
            // Form submit ricerca
            $(document).on('submit', 'form.searchform, form[action*="search"], #search form', function(e) {
                try {
                    var $form = $(this);
                    var searchQuery = $form.find('input[name="s"], input[name="q"], input[type="search"], .search-field, input[name="search"]').val();
                    
                    if (searchQuery && searchQuery.trim() && typeof ttq !== 'undefined') {
                        var searchData = {
                            search_string: searchQuery.trim()
                        };
                        
                        ttq('track', 'Search', searchData);
                        console.log('TikTok Search tracked (Form):', searchData);
                        
                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'ttp_track_search_server',
                            search_query: searchQuery.trim(),
                            source: 'form_submit',
                            nonce: '<?php echo wp_create_nonce('ttp_tracking_nonce'); ?>'
                        });
                    }
                } catch(e) {
                    console.error('TTP Form Search Error:', e);
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Traccia InitiateCheckout
     */
    public function track_initiate_checkout() {
        if (!WC()->cart) return;
        
        $cart = WC()->cart;
        $content_ids = array();
        $contents = array();
        $value = 0;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $product = wc_get_product($product_id);
            
            if (!$product) continue;
            
            $content_ids[] = $product_id;
            $contents[] = array(
                'content_id' => $product_id,
                'content_type' => 'product',
                'content_name' => $product->get_name()
            );
            
            $value += ($product->get_price() ?: 0) * $quantity;
        }
        
        if (empty($content_ids)) return;
        
        $cart_hash = md5(serialize($content_ids) . $value);
        
        ?>
        <script>
        window.onTtqPixelReady(function() {
            var checkoutData = {
                contents: <?php echo json_encode($contents); ?>,
                value: <?php echo $value; ?>,
                currency: '<?php echo get_woocommerce_currency(); ?>',
                content_type: 'product',
                hash: '<?php echo $cart_hash; ?>',
                ttp_tracked: true
            };
            
            var storageKey = 'ttp_checkout_' + checkoutData.hash;
            var tracked = sessionStorage.getItem(storageKey);
            
            if (!tracked) {
                ttq('track', 'InitiateCheckout', checkoutData);
                
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'ttp_track_checkout_server',
                    event_type: 'InitiateCheckout',
                    checkout_data: checkoutData,
                    nonce: '<?php echo wp_create_nonce('ttp_tracking_nonce'); ?>'
                });
                
                sessionStorage.setItem(storageKey, '1');
                
                // AddPaymentInfo trigger
                var paymentTracked = false;
                jQuery('#billing_email, #billing_first_name').one('focus', function() {
                    if (!paymentTracked) {
                        paymentTracked = true;
                        
                        var paymentData = {
                            contents: checkoutData.contents,
                            value: checkoutData.value,
                            currency: checkoutData.currency,
                            content_type: 'product',
                            ttp_tracked: true
                        };
                        
                        ttq('track', 'AddPaymentInfo', paymentData);
                        
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'ttp_track_checkout_server',
                            event_type: 'AddPaymentInfo',
                            checkout_data: checkoutData,
                            nonce: '<?php echo wp_create_nonce('ttp_tracking_nonce'); ?>'
                        });
                    }
                });
            }
        });
        </script>
        <?php
        
        // Server-side tracking immediato
        TTP_API_Server::send_event('InitiateCheckout', array(
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => $value,
            'currency' => get_woocommerce_currency()
        ));
    }
    
    /**
     * Traccia Purchase
     */
    public function track_purchase($order_id) {
        if (!$order_id) return;
        
        $order = wc_get_order($order_id);
        
        if (!$order || get_post_meta($order_id, '_ttp_tracked', true)) {
            return;
        }
        
        $content_ids = array();
        $contents = array();
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            $product = $item->get_product();
            
            if (!$product) continue;
            
            $content_ids[] = $product_id;
            $contents[] = array(
                'content_id' => $product_id,
                'content_type' => 'product',
                'content_name' => $product->get_name()
            );
        }
        
        if (empty($content_ids)) return;
        
        ?>
        <script>
        window.onTtqPixelReady(function() {
            var purchaseData = {
                contents: <?php echo json_encode($contents); ?>,
                value: <?php echo $order->get_total(); ?>,
                currency: '<?php echo $order->get_currency(); ?>',
                content_type: 'product',
                ttp_tracked: true
            };
            
            ttq('track', 'Purchase', purchaseData);
        });
        </script>
        <?php
        
        // Server-side tracking con dati utente
        $user_data = array(
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'external_id' => $order->get_user_id() ?: 'guest_' . $order_id
        );
        
        TTP_API_Server::send_event('Purchase', array(
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => $order->get_total(),
            'currency' => $order->get_currency()
        ), $user_data);
        
        // Segna come tracciato
        update_post_meta($order_id, '_ttp_tracked', true);
    }
    
    /**
     * Traccia alto interesse (semplificato)
     */
    public function track_high_interest() {
        if (!is_product()) return;
        
        ?>
        <script>
        window.onTtqPixelReady(function() {
            setTimeout(function() {
                var interestData = {
                    content_type: 'product',
                    ttp_tracked: true
                };
                ttq('track', 'ClickButton', interestData);
            }, 20000);
        });
        </script>
        <?php
    }
    
    /**
     * Aggiunge attributi dati ai link prodotti
     */
    public function add_product_data_attributes($link, $product) {
        if (!$product) return $link;
        
        $price = $product->get_price() ?: 0;
        $name = $product->get_name() ?: '';
        
        $search = 'data-product_id';
        $replace = sprintf(
            'data-product_price="%s" data-product_name="%s" data-product_id',
            esc_attr($price),
            esc_attr($name)
        );
        
        return str_replace($search, $replace, $link);
    }
    
    /**
     * Handler AJAX AddToCart
     */
    public function handle_add_to_cart_server() {
        if (!wp_verify_nonce($_POST['nonce'], 'ttp_tracking_nonce')) {
            wp_die('Security check failed');
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $product_price = isset($_POST['product_price']) ? floatval($_POST['product_price']) : 0;
        $product_name = isset($_POST['product_name']) ? sanitize_text_field($_POST['product_name']) : '';
        
        if ($product_id) {
            TTP_API_Server::send_event('AddToCart', array(
                'content_ids' => array($product_id),
                'content_name' => $product_name,
                'content_type' => 'product',
                'value' => $product_price,
                'currency' => get_woocommerce_currency()
            ));
        }
        
        wp_die('OK');
    }
    
    /**
     * Handler AJAX Search
     */
    public function handle_search_server() {
        if (!wp_verify_nonce($_POST['nonce'], 'ttp_tracking_nonce')) {
            wp_die('Security check failed');
        }
        
        $search_query = isset($_POST['search_query']) ? sanitize_text_field($_POST['search_query']) : '';
        
        if (!empty($search_query)) {
            TTP_API_Server::send_event('Search', array(
                'search_string' => $search_query
            ));
        }
        
        wp_die('OK');
    }
    
    /**
     * Handler AJAX Checkout
     */
    public function handle_checkout_server() {
        if (!wp_verify_nonce($_POST['nonce'], 'ttp_tracking_nonce')) {
            wp_die('Security check failed');
        }
        
        $event_type = sanitize_text_field($_POST['event_type']);
        $checkout_data = isset($_POST['checkout_data']) ? $_POST['checkout_data'] : array();
        
        if (!empty($checkout_data['contents']) && in_array($event_type, ['InitiateCheckout', 'AddPaymentInfo'])) {
            
            $user_data = array();
            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                $user_data = array(
                    'email' => $user->user_email,
                    'external_id' => $user->ID
                );
            }
            
            TTP_API_Server::send_event($event_type, array(
                'contents' => $checkout_data['contents'],
                'content_type' => 'product',
                'value' => floatval($checkout_data['value']),
                'currency' => $checkout_data['currency']
            ), $user_data);
        }
        
        wp_die('OK');
    }
}