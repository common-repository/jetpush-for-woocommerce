<?php
class WC_Integration_Jetpush extends WC_Integration {

    private static $messages = array();

    public function __construct() {
        $this->id = 'jetpush';

        $this->apikey = $this->get_option('apikey');

        $this->method_title = 'JetPush for WooCommerce';
        $this->method_description = 'Hello, please enter your JetPush Project API Key below to complete your installation.' ;

        $this->init_settings();

        $this->init_form_fields();

        $this->init_hooks();
    }
    /**
     * Create form fields for jetpush setting page.
     * @return [type] [description]
     */
    public function init_form_fields() {

        $this->form_fields = array(
            'apikey' => array(
                'title' => 'Project API Key',
                'type' => 'text',
                'default' => '',
                'description' => '<a target="_blank" href="' . JETPUSH_DOMAIN .'">Login to JetPush</a> to get your API key'
            ),
            'permission' => array(
                'type' => 'radio',
                'id' => 'js-permission',
                'title' => 'Who to track?',
                'default' => '',
                'description' => '',
                'options' => array(
                    'track_all' => '<span style="color:orange;font-weight: bold">Recommended</span><br><span style="padding-left:24px">JetPush will track both new and existing customers</span>',
                    'track_new' => 'JetPush will track new customers only'
                ),
            ),
        );
    }

    /**
     * Generate Radio Input HTML.
     * @access public
     * @param  [mixed] $key
     * @param  [mixed] $data
     * @return [string]
     */
    public function generate_radio_html($key, $data) {
        $field = $this->plugin_id . $this->id . '_' .$key;
        $defaults = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'radio',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array()
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();

    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label for="<?php echo esc_attr($field); ?>">
                <?php echo wp_kses_post($data['title']); ?>
            </label>
        </th>
        <td class="forminp">
            <fieldset>
                <legend class="screen-reader-text">
                    <span><?php echo wp_kses_post($data['title']); ?></span>
                </legend>

                <ul>
                    <?php foreach($data['options'] as $radio_value => $radio_label) : ?>
                    <li>
                        <label>
                            <input
                            name="<?php echo $field ?>"
                            value="<?php echo $radio_value ?>"
                            type="radio"
                            style="<?php echo esc_attr($data['css']) ?>"
                            class="<?php echo esc_attr($data['class']) ?>"
                            <?php checked($radio_value, $this->get_option($key)) ?>>
                            <?php echo wp_kses_post($radio_label); ?>
                        </label>
                    </li>
                    <?php endforeach ?>
                </ul>
            </fieldset>
        </td>
    </tr>

    <?php
        return ob_get_clean();
    }
    /**
     * Init our hooks
     */
    public function init_hooks() {

        // add_action('woocommerce_update_options_integration', array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options'));

        if ( ! $this->apikey) {
            // JPE-61: WP plugin activation message should include instructions to proceed to settings
            add_action('admin_notices', array($this, 'activation_message_notice'));
            return;
        }

        // call create_customer when new customer was being created.
        add_action('woocommerce_created_customer', array($this, 'create_customer'), 10, 3);

        // call track cart content

        add_action('woocommerce_cart_updated', array($this, 'track_cart_content'));
        // add_action('woocommerce_cart_updated', array($this, 'track_cart_content'));

        // track when user logged in
        add_action('set_logged_in_cookie', array($this, 'track_alias'), 10, 4);

        // call track_purchase
        add_action('woocommerce_checkout_order_processed', array($this, 'track_purchase'), 10, 2);

        // track order status
        add_action('woocommerce_order_status_changed', array($this, 'track_order_status'), 10, 3);

        // call track_product_view
        add_action('wp_head', array($this, 'track_pageview'));

    }

    /**
     * Show customer message (allow HTML)
     * @return void
     */
    public function show_message() {
        foreach ( self::$messages as $message )
            echo '<div id="message" class="updated fade"><p><strong>' . wp_kses_post( $message ) . '</strong></p></div>';
    }

    public function activation_message_notice() {
        global $hook_suffix;
        if ($hook_suffix == 'plugins.php') {
            $link = get_admin_url(null,'admin.php?page=wc-settings&tab=integration&section=jetpush');
            echo '<div class="updated">
                    <p><a class="button button-primary" href="'.$link.'">Activate JetPush</a> <strong style="margin-left: 10px;">Almost done</strong>... Activate JetPush to engage with your customers and increase sales</p>
                </div>';
        }
    }

    /**
     * Call Import Customer when PO change the apikey
     * @return [type] [description]
     */
    public function process_admin_options() {
        // save to database
        $result = parent::process_admin_options();
        $old_apikey = $this->apikey;

        // if pass validate
        if ($result) {
            // get the new apikey
            $apikey = $this->get_option('apikey');

            if (empty($apikey)) {
                return;
            }

            // api change
            if ($apikey && $apikey !== $old_apikey) {
                return $this->import($apikey);
            }
        }
    }

    /**
     * Import data from this instance wordpress to jetpush
     * @param  string $apikey jetpush apikey
     * @return void
     */
    public function import($apikey) {
        // only import when user allows us
        $permission = $this->get_option('permission');
        if ($permission !== 'track_all') {
            return;
        }

        $url = JETPUSH_DOMAIN . API_PATH . '/collect/import?_k=' . $apikey;
        wp_remote_post($url, array(
            'body' => array(
                'customers' => $this->get_customers(),
                'orders' => $this->get_orders()
            )
        ));

        $msg = 'Congratulations, your installation is successful. <a href="'.JETPUSH_DOMAIN .'">Login to JetPush</a> to get started.';
        self::$messages[] = $msg;

        $this->show_message();
    }

    /**
     * get all orders
     */
    public function get_orders() {
        $orders = array();
        $args = array(
            'post_type' => 'shop_order',
            'post_status' => 'publish',
            'nopaging' => true,
            'posts_per_page' => -1
        );
        $loop = new WP_Query($args);
        while ($loop->have_posts()) {
            $loop->the_post();
            $order_id = $loop->post->ID;
            $order = new WC_Order($order_id);

            $products = $this->format_products($order->get_items());
            $orders[] = array(
                'orderId' => $order_id,
                'customerShopId' => $order->user_id,
                'totalPrice' => $order->get_total(),
                'orderNote' => $order->get_customer_order_notes(),
                'status' => $order->status,
                'products' => $products,
                'isSuccess' => $order->status === 'Completed' ? true : false
            );
        }

        wp_reset_postdata();
        return $orders;
    }


    /**
     * get all customers
     *
     * @return array customers
     */
    public function get_customers() {

        $users = get_users();
        $customers = array();
        foreach($users as $user) {
            $customers[] = array(
                'shopId' => $user->ID,
                'firstName' => $user->first_name ? $user->first_name : get_user_meta($user->ID, 'billing_first_name', true),
                'lastName' => $user->last_name ? $user->last_name : get_user_meta($user->ID, 'billing_last_name', true),
                'email' => $user->user_email,
                'address' => get_user_meta($user->ID, 'billing_address_1', true),
                'city' => get_user_meta($user->ID, 'billing_city', true),
                'zip' => get_user_meta($user->ID, 'billing_postcode', true),
                'country' => get_user_meta($user->ID, 'billing_country', true),
                'phone' => get_user_meta($user->ID, 'billing_phone', true),
            );
        }

        return $customers;
    }


    /**
     * Auto call alias to link actions of guest to customer after they logged in
     * @return [type] [description]
     */
    public function track_alias($logged_in_cookie, $expire, $expiration, $user_id) {
        $guest_id = WC()->session->get('jetpush_customer_id', false);
        if ( ! empty($guest_id)) {
            $this->alias($guest_id, $user_id);
        }
    }

    /**
     * Set customer properties
     */
    public function create_customer($customer_id, $customer_data, $password_generated) {
        $this->set(array(
            'email' => $customer_data['user_email']
        ), $customer_id);

        do_action('create_customer');
    }

    /**
     * Track various pageviews
     * @return [type] [description]
     */
    public function track_pageview() {
        if (is_product()) {
            $product_id = get_the_ID();
            $product = new WC_Product($product_id);

            $this->track('viewed product', array(
                'id' => $product_id,
                'name' => $product->get_title(),
                'price' => $product->get_price(),
                'imageUrl' => has_post_thumbnail() ? wp_get_attachment_url(get_post_thumbnail_id()) : '',
                'url' => get_permalink($product_id)
            ));
        }

        if (is_product_category()) {
            global $wp_query;
            $q_obj = $wp_query->get_queried_object();
            $cat_id = $q_obj->term_id;
            $cat = get_term_by("id", $cat_id, 'product_cat');

            $this->track('viewed category', array(
                'id' => $cat->term_id,
                'name' => $cat->name,
                'url' => get_term_link($cat->term_id, 'product_cat'),
            ));
        }
    }

    /**
     * Track add to cart event.
     * @return [type] [description]
     */
    public function track_cart_content() {
        global $woocommerce;
        $products = $this->get_products();

        $this->track('cart', array(
            'cartId' => $this->get_cart_id(),
            'total' => $woocommerce->cart->cart_contents_total,
            'products' => $products,
            'cartRecoveryUrl' => $this->getCartRecoveryUrl()
        ));

        // cart was destroy, re-generate new cart id
        if (count($products) === 0) {
            $this->generate_new_cart_id();
        }

        do_action('jetpush_track_add_to_cart');
    }

    /**
     * [track_order_status description]
     * @param  [type] $order_id [description]
     * @return [type]           [description]
     */
    public function track_order_status($order_id, $status, $new_status) {
        $this->track('order', array(
            'orderId' => $order_id,
            'status' => $new_status
        ));
    }

    /**
     * [track_purchase description]
     * @return [type] [description]
     */
    public function track_purchase($order_id, $posted) {
        $order = new WC_Order($order_id);

        // order status
        $terms        = wp_get_object_terms( $order_id, 'shop_order_status', array( 'fields' => 'slugs' ) );
        $order_status = isset( $terms[0] ) ? $terms[0] : 'pending';

        $this->track('order', array(
            'orderId' => $order_id,
            'cartId' => $this->get_cart_id(),
            'totalPrice' => $order->get_total(),
            'orderNote' => $order->get_customer_order_notes(),
            'status' => $order_status,
            'products' => $this->format_products($order->get_items())
        ));

        // track information customer enter during checkout process
        $this->set(array(
            'firstName' => $posted['billing_first_name'],
            'lastName' => $posted['billing_last_name'],
            'email' => $posted['billing_email'],
            'address' => $posted['billing_address_1'],
            'city' =>  $posted['billing_city'],
            'zip' => $posted['billing_state'],
            'country' => $posted['billing_country'],
            'phone' => $posted['billing_phone'],
            'company' => $posted['billing_company']
        ));

        do_action('jetpush_track_purchase');
    }

    /**
     * Track event
     * @return [type] [description]
     */
    protected function track($event_name, $event_data = array()) {
        $user_id = $this->get_current_user_id();

        $query_data = array(
            '_k' => $this->apikey,
            '_p' => $user_id,
            '_n' => $event_name,
            '_data' => base64_encode(json_encode($event_data))
        );
        $url = JETPUSH_DOMAIN . API_PATH .'/collect/track?' . http_build_query($query_data);

        wp_remote_get($url);
    }

    /**
     * Set customer properties
     * @param [type] $properties [description]
     * @param [type] $user_id    [description]
     */
    protected function set($properties, $user_id = null) {
        if(is_null($user_id))
            $user_id = $this->get_current_user_id();

        $query_data = array(
            '_k' => $this->apikey,
            '_p' => $user_id,
            '_data' => base64_encode(json_encode($properties))
        );
        $url = JETPUSH_DOMAIN . API_PATH . '/collect/set?' . http_build_query($query_data);

        wp_remote_get($url);
    }

    /**
     * Alias 2 customers together
     */
    protected function alias($customer, $another_customer) {
        $query_data = array(
            '_k' => $this->apikey,
            '_p' => $customer,
            '_n' => $another_customer,
        );

        $url = JETPUSH_DOMAIN . API_PATH . '/collect/alias?' . http_build_query($query_data);

        wp_remote_get($url);
    }

    /**
     * get current user id or generated new one if user was guest
     * @return [type] [description]
     */
    private function get_current_user_id() {
        // @TODO use my own session handle
        $user_id = get_current_user_id();

        // customer already logged in
        if ($user_id) {
            return $user_id;
        }

        // customer still being a guest
        $user_id = WC()->session->get('jetpush_customer_id', $this->UUID());
        WC()->session->set('jetpush_customer_id', $user_id);

        return $user_id;
    }

    private function get_cart_id() {
        $cartId = WC()->session->get('cartId');
        if ( ! $cartId) {
            $cartId = $this->generate_new_cart_id();
        };

        return $cartId;
    }

    private function generate_new_cart_id() {
        $cartId = $this->UUID();
        WC()->session->set('cartId', $cartId);
        return $cartId;
    }

    /**
     * Get cart recovery url
     * @return [string] [url for customer to revisit their cart and checkout]
     */
    private function getCartRecoveryUrl() {
        global $woocommerce;

        if (is_user_logged_in()) {
            $queryString = http_build_query(array(
                's' => base64_encode($_COOKIE['wordpress_logged_in_' . COOKIEHASH])
            ));

            return $woocommerce->cart->get_cart_url() . '?' . $queryString;
        }

        return $woocommerce->cart->get_cart_url();
    }

    /**
     * Get current products in cart
     * @return [array] [products in cart]
     */
    private function get_products($ignore_product = null) {
        global $woocommerce;

        $products = array();

        foreach($woocommerce->cart->get_cart() as $cart_item_key => $values) {

            $product = $values['data'];

            if ($ignore_product && $ignore_product === $cart_item_key) {
                continue;
            }

            $products[] = array(
                'id' => $product->id,
                'name' => $product->get_title(),
                'price' => $product->get_price(),
                'quantity' => $values['quantity']
            );
        }

        return $products;

    }

    /**
     * Generate unique id
     */
    private function UUID() {
        if (function_exists('com_create_guid') === true)
        {
            return trim(com_create_guid(), '{}');
        }

        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    /**
     * Format product, only get what we need to used.
     * @param  [type] $products [description]
     * @return [type]           [description]
     */
     private function format_products($products) {
        foreach ($products as $product_id => $product) {
            $result[] = array(
                'name' => $product['name'],
                'qty' => $product['qty'],
                'product_id' => $product['product_id'],
                'line_subtotal' => $product['line_subtotal'],
                'line_total' => $product['line_total'],
                'line_tax' => $product['line_tax'],
                'line_subtotal_tax' => $product['line_subtotal_tax'],
            );
        }
        return $result;
     }

}