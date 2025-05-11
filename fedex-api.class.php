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
                FEDEX_API_BASE_URL=https://apis.fedex.com/
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

                    if( ! $has_errors ){
                        $this->update_env_value('FEDEX_API_KEY', $api_key_input_value );
                        $this->update_env_value('FEDEX_SECRET_KEY', $secret_key_input_value );
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

                ?>
                
                    <form method="post" action="<?php echo sprintf( '%s&_wpnonce=%s', menu_page_url( 'fedex-api-keys', false), wp_create_nonce( 'fedex-api-keys-save' ) ); ?>" class="fedex-api-keys-form" >
                        <h2>FedEx API Keys</h2>
                        <table class="form-table" role="presentation">
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
                } else {
                    error_log(".env file does not exist in $plugin_root /.env");
                }
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
                    update_post_meta( $post_id, 'tracking_number', $tracking_number );
                    $this->send_notifications( $post_id, $tracking_number );
                }
            }

            function on_activation(){
                flush_rewrite_rules();
                $this->create_env_file();
            } //end function on_activation

            function on_deactivation(){
                flush_rewrite_rules();
            } //end function on_deactivation

            function send_notifications( $order_id, $tracking_number ){
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
                CURLOPT_URL => $_ENV[ 'FEDEX_API_BASE_URL' ] . 'track/v1/notifications',
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

                $response = curl_exec($curl);

                curl_close($curl);
                echo $response;

            } //send_notifications

            function settings_submenu(){
                add_submenu_page(
                    'options-general.php',
                    'FedEx API Keys',
                    'FedEx API Keys',
                    'administrator',
                    'fedex-api-keys',
                    [ $this, 'fedex_api_keys_settings_page' ],
                    99
                );
            }

            function token_request(){

                $api_keys = $this->get_api_keys();

                $curl = curl_init();
        
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $_ENV[ 'FEDEX_API_BASE_URL' ] . 'oauth/token',
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
        
                $response = json_decode( curl_exec( $curl ), true );


                return $response;
                curl_close($curl);
        
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

        }

        $plugin = new WCFedExShipmentTrackingNotification();

        add_action( 'add_meta_boxes', [ $plugin, 'mv_order_tracking_meta_boxes' ] );
        add_action( 'init', [ $plugin, 'settings_submenu' ] );
        add_action( 'save_post', [ $plugin, 'mv_save_wc_order_other_fields' ], 10, 1 );

        register_activation_hook( __FILE__, [ $plugin, 'on_activation' ] );
    }