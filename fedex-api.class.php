<?php
    /*
        Plugin Name: WC FedEx Shipment Tracking Notifications
        Plugin URI: https://github.com/darellduma/wc-fedex-shipment-tracking-notification.git
        Description: FedEx shipment tracking notification implementation for WooCommerce
        Author: Darell Duma
        Version: 0.1
    */


    defined( 'ABSPATH' ) or die( 'You are not allowed to access this file' );

    // Check if WooCommerce is active
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');

    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        // Deactivate this plugin
        deactivate_plugins(plugin_basename(__FILE__));

        // Display admin notice
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>WC Fedex Shipment Tracking</strong> requires WooCommerce to be installed and active.</p></div>';
        });

        return;
    }

    require_once __DIR__ . '/vendor/autoload.php';
    use Dotenv\Dotenv;

    if( ! class_exists( 'WCFedExShipmentTrackingNotification') ){
        class WCFedExShipmentTrackingNotification{
            private $fedex_api_base_url;
            private $tracking_error_messages = [];

            public function __construct() {
                $this->load_env();
            }

            function create_env_file(){
                $plugin_root = plugin_dir_path(__FILE__);
                $env_path = $plugin_root . '.env';


                // Check if .env already exists
                if (file_exists($env_path)) {
                    return;
                }

                $default_env = <<<ENV
                FEDEX_API_KEY=
                FEDEX_SECRET_KEY=
                FEDEX_API_ENVIRONMENT=production
                ENV;

                // Try to write the .env file
                file_put_contents($env_path, $default_env);

                // Set permissions (read/write for owner only)
                @chmod($env_path, 0600);
            }

            function get_api_keys(){
                return [
                    'api_key'       =>  $_ENV[ 'FEDEX_API_KEY' ],
                    'secret_key'    =>  $_ENV[ 'FEDEX_SECRET_KEY' ]
                ];
            }

            function mv_order_tracking_meta_boxes(){
                add_meta_box( 'mv_other_fields', __('Track Shipment','woocommerce'), [ $this, 'mv_add_other_fields_for_packaging' ], 'shop_order', 'side', 'core' );
            }

            function fedex_api_keys_settings_page(){
                if( isset( $_POST[ 'submit' ] ) ){
                    $has_errors = false;
                    if( ! isset( $_POST[ 'fedex_api_key' ] ) || empty( trim( $_POST[ 'fedex_api_key' ] ) ) ){
                        ?>
                            <div class="notice notice-error">
                                <p>FedEx API Key is required</p>
                            </div>
                        <?php
                        $has_errors = true;
                    } 

                    if( ! isset( $_POST[ 'fedex_secret_key' ] ) || empty( trim( $_POST[ 'fedex_secret_key' ] ) ) ){
                        ?>
                            <div class="notice notice-error">
                                <p>FedEx Secret Key is required</p>
                            </div>
                        <?php
                        $has_errors = true;
                    } 

                    $api_key_input_value = sanitize_text_field( trim( $_POST[ 'fedex_api_key' ] ) );
                    $secret_key_input_value = sanitize_text_field( trim( $_POST[ 'fedex_secret_key' ] ) );
                    $api_environment_input_value = sanitize_text_field( trim( $_POST[ 'fedex_api_environment' ] ) );

                    if( ! $has_errors ){
                        $this->update_env_value('FEDEX_API_KEY', $api_key_input_value );
                        $this->update_env_value('FEDEX_SECRET_KEY', $secret_key_input_value );
                        $this->update_env_value('FEDEX_API_ENVIRONMENT', $api_environment_input_value );
                        ?>
                            <div class="notice notice-success">
                                <p>Settings Updated</p>
                            </div>
                        <?php 
                    }
                    
                    
                }

                $fedex_api_keys =  $this->get_api_keys();

                $api_keys = [
                    'api_key'       => !empty( $api_key_input_value ) ? $api_key_input_value : $fedex_api_keys[ 'api_key' ] ?? "",
                    'secret_key'    => !empty( $secret_key_input_value ) ? $secret_key_input_value : $fedex_api_keys[ 'secret_key' ] ?? "",
                ];

                $api_environment = ! empty( $api_environment_input_value ) ? $api_environment_input_value : esc_attr( $_ENV[ 'FEDEX_API_ENVIRONMENT' ] ) ?? "production" ;

                ?>
                
                    <form method="post" action="<?php echo sprintf( '%s&_wpnonce=%s', menu_page_url( 'fedex-api-keys', false), wp_create_nonce( 'fedex-api-keys-save' ) ); ?>" class="fedex-api-keys-form" >
                        <h2>FedEx API Keys</h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="fedex_api_environment">API Environment</label></th>
                                <td>
                                    <select name="fedex_api_environment" id="fedex_api_environment">
                                        <option value="test" <?php selected( $api_environment, 'test' ) ?>>Test</option>
                                        <option value="production" <?php selected( $api_environment, 'production' ) ?>>Production</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fedex_api_key">Public Key</label></th>
                                <td><input class="regular-text" id="fedex_api_key" name="fedex_api_key" type="text" value="<?php echo esc_attr( $api_keys[ 'api_key' ] ?? "" ); ?>"  /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fedex_secret_key">Secret Key</label></th>
                                <td><input class="regular-text" id="fedex_secret_key" name="fedex_secret_key" type="password" value="<?php echo esc_attr( $api_keys[ 'secret_key' ] ?? "" ); ?>"  /></td>
                            </tr>
                        </table>
                        <input class="button button-primary" type="submit" name="submit" value="submit" />
                    </form>
                <?php
            }

            private function load_env() {
                $plugin_root = dirname( __FILE__ ); // Root of the plugin where .env lives

                if ( file_exists( $plugin_root . '/.env' ) ) {
                    $dotenv = Dotenv::createImmutable( $plugin_root );
                    $dotenv->safeLoad(); // Use load() if you want to enforce required keys
                    $api_environment = sanitize_text_field( trim( $_ENV[ 'FEDEX_API_ENVIRONMENT' ] ) );
                    $this->fedex_api_base_url = $this->load_fedex_api_base_url( $api_environment );
                } else {
                    error_log(".env file does not exist in $plugin_root /.env");
                }
            }

            private function load_fedex_api_base_url( $api_environment ){
                return $api_environment === 'production' ? 'https://apis.fedex.com/' : 'https://apis-sandbox.fedex.com/';
            }

            function mv_add_other_fields_for_packaging(){
                global $post;

                $meta_field_data = get_post_meta( $post->ID, 'tracking_number', true ) ? get_post_meta( $post->ID, 'tracking_number', true ) : '';

                $courier = get_post_meta( $post->ID, 'courier', true );

                echo '<input type="hidden" name="mv_other_meta_field_nonce" value="' . wp_create_nonce() . '">';
                echo '<p style="border-bottom:solid 1px #eee;padding-bottom:13px;">
                    <label id="tracking-number">Tracking Number: </label><input type="text" style="width:250px;";" id="tracking-number" name="tracking-number" value="' . $meta_field_data . '"></p>';
            }

            function mv_save_wc_order_other_fields( $post_id ) {

                // We need to verify this with the proper authorization (security stuff).
        
                // Check if our nonce is set.
                if ( ! isset( $_POST[ 'mv_other_meta_field_nonce' ] ) ) {
                    return $post_id;
                }
                $nonce = $_REQUEST[ 'mv_other_meta_field_nonce' ];
        
                //Verify that the nonce is valid.
                if ( ! wp_verify_nonce( $nonce ) ) {
                    return $post_id;
                }
        
                // If this is an autosave, our form has not been submitted, so we don't want to do anything.
                if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                    return $post_id;
                }
        
                // Check the user's permissions.
                if ( 'page' == $_POST[ 'post_type' ] ) {
        
                    if ( ! current_user_can( 'edit_page', $post_id ) ) {
                        return $post_id;
                    }
                } else {
        
                    if ( ! current_user_can( 'edit_post', $post_id ) ) {
                        return $post_id;
                    }
                }
                // --- Its safe for us to save the data ! --- //

                // Sanitize user input  and update the meta field in the database.
                if( isset( $_POST[ 'tracking-number' ] ) ){
                    $tracking_number = sanitize_text_field( $_POST[ 'tracking-number' ] );
                    $exising_tracking_number = get_post_meta( $post_id, 'tracking_number', true );
                    if( $exising_tracking_number === $tracking_number ) return;
                    $send_notification_response = $this->send_notification_success( $post_id, $tracking_number );
                    if($send_notification_response){
                        update_post_meta( $post_id, 'tracking_number', $tracking_number );
                    } else {
                        delete_post_meta( $post_id, 'tracking_number' );
                    }
                }
            }

            function on_activation(){
                flush_rewrite_rules();
                $this->create_env_file();
            } //end function on_activation

            function on_deactivation(){
                flush_rewrite_rules();
            } //end function on_deactivation

            function send_notification_success( $order_id, $tracking_number ){
                $token_request = $this->token_request();

                $order = wc_get_order( $order_id );

                $payload = [
                    "trackingNumberInfo"                => [
                        "trackingNumber"                =>  $tracking_number
                    ],
                    "senderEMailAddress"                =>  "notification@fedex.com",
                    "senderContactName"                 =>  "FedEx",
                    "trackingEventNotificationDetail"   =>  [
                        "trackingNotifications"     =>  [
                            [
                                "notificationEventTypes"    =>  [ "ON_ESTIMATED_DELIVERY", "ON_TENDER", "ON_EXCEPTION", "ON_DELIVERY" ],
                                "notificationDetail"        =>  [
                                    "notificationType"  =>  "HTML",
                                    "emailDetail"       =>  [
                                        'emailAddress'      =>  $order->get_billing_email()
                                    ],
                                    "localization"      =>  [
                                        "languageCode"  =>  "en",
                                        "localeCode"    =>  "US"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];

                $curl = curl_init();

                curl_setopt_array($curl, array(
                CURLOPT_URL => $this->fedex_api_base_url . 'track/v1/notifications',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode( $payload ),
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . $token_request[ 'access_token' ],
                    'Content-Type: application/json',
                ),
                ));

                $response = curl_exec( $curl );

                // Get HTTP response status code
                $httpCode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
                $decoded_response = json_decode( $response, true );
                $messages = [];

                if( $httpCode !== 200 ){
                    if (is_null($decoded_response)) {
                        error_log( 'JSON decode error: ' . json_last_error_msg() );
                    } else {
                        foreach( $decoded_response[ 'errors' ] as $d ){
                            array_push( $messages, $d[ 'message' ] );
                        }
                    }
                    // Store messages in a transient tied to the order ID
                    set_transient('tracking_error_' . $order_id, $messages, 60); // expires in 1 minute

                    add_filter( 'redirect_post_location', [ $this, 'update_notices' ], 10, 1 );
                    curl_close($curl);
                    return false;
                }

                if (curl_errno($curl)) {
                    add_filter( 'redirect_post_location', [ $this, 'update_notices' ], 10, 1 );
                    error_log( 'cURL Error (' . curl_errno( $curl ) . '): ' . curl_error( $curl ) );
                    curl_close($curl);
                    return false;
                }

                curl_close($curl);
                return true;

            } //send_notification_success

            function settings_submenu(){
                add_options_page(
                    'FedEx API Keys',
                    'FedEx API Keys',
                    'manage_options',
                    'fedex-api-keys',
                    [ $this, 'fedex_api_keys_settings_page' ],
                    99
                );
            }

            function show_notices( $location ){
                if (isset($_GET['tracking_error'])) {
                    $order_id = absint( $_GET['tracking_error'] );
                    $messages = get_transient( 'tracking_error_' . $order_id );
                    foreach ( $messages as $msg ) {
                        echo '<div class="notice notice-error"><p><strong>' . esc_html($msg) . '</strong></p></div>';
                    }

                    delete_transient('tracking_error_' . $order_id);
                }
            }

            function token_request(){

                $api_keys = $this->get_api_keys();

                $curl = curl_init();
        
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $this->fedex_api_base_url . 'oauth/token',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => 'grant_type=client_credentials&client_id=' . $api_keys[ 'api_key' ] .'&client_secret=' . $api_keys[ 'secret_key' ],
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/x-www-form-urlencoded',
                    ),
                ));
        
                $response = curl_exec( $curl );
                    
                if (curl_errno($curl)) {
                    add_action('admin_notices', function () {
                        echo '<div class="notice notice-error"><p><strong>Error sending notifications. Please check the logs.</strong></p></div>';
                    });
                    error_log( 'cURL Error (' . curl_errno( $curl ) . '): ' . curl_error( $curl ) );
                    curl_close($curl);
                    return;
                }

                curl_close($curl);
                return json_decode( $response, true );
        
            } //end function token_request

            function update_env_value($key, $value) {
                $env_path = plugin_dir_path(__FILE__) . '.env';

                // If .env doesn't exist, create it
                if (!file_exists($env_path)) {
                    file_put_contents($env_path, '');
                    @chmod($env_path, 0600);
                }

                $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $updated = false;

                foreach ($lines as &$line) {
                    if (strpos(trim($line), $key . '=') === 0) {
                        $line = $key . '=' . $value;
                        $updated = true;
                        break;
                    }
                }

                // If key wasn't found, add it
                if (!$updated) {
                    $lines[] = $key . '=' . $value;
                }

                file_put_contents($env_path, implode(PHP_EOL, $lines) . PHP_EOL);
            } //end function update_env_value

            function update_notices( $location ){
                global $post;

                // Only target shop_order post type
                if (isset($post) && $post->post_type === 'shop_order') {
                    // Remove the default 'message=1' (Order updated) if present
                    $location = remove_query_arg('message', $location);

                    // Add your custom error flag
                    $location = add_query_arg('tracking_error', $post->ID, $location);
                }

                return $location;
            }

        }

        $plugin = new WCFedExShipmentTrackingNotification();

        add_action( 'add_meta_boxes', [ $plugin, 'mv_order_tracking_meta_boxes' ] );
        add_action( 'admin_menu', [ $plugin, 'settings_submenu' ] );
        add_action('admin_notices', [ $plugin, 'show_notices' ] );
        add_action( 'save_post', [ $plugin, 'mv_save_wc_order_other_fields' ], 10, 1 );

        // add_filter( 'redirect_post_location', [ $plugin, 'update_notices' ] );

        register_activation_hook( __FILE__, [ $plugin, 'on_activation' ] );
    }