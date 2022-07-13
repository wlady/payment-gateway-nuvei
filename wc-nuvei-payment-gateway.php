<?php
/**
 * Plugin Name: WooCommerce Nuvei Gateway
 * Plugin URI: https://github.com/wlady/payment-gateway-nuvei/
 * Description: WooCommerce Nuvei Gateway
 * Author: Vladimir Zabara <wlady2001@gmail.com>
 * Author URI: https://github.com/wlady/
 * Version: 2.0.0
 * Text Domain: wc-nuvei
 * Requires PHP: 7.3
 * Requires at least: 4.7
 * Tested up to: 6.0
 * WC requires at least: 3.0
 * WC tested up to: 6.2
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

defined( 'ABSPATH' ) or exit;

define( 'NUVEI_SUPPORT_PHP', '7.3' );
define( 'NUVEI_SUPPORT_WP', '5.0' );
define( 'NUVEI_SUPPORT_WC', '3.0' );
define( 'NUVEI_DB_VERSION', '1.0' );


/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 *
 * @return array $gateways all WC gateways + offline gateway
 * @since 1.0.0
 *
 */
function wc_nuvei_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Nuvei';

	return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'wc_nuvei_add_to_gateways' );


/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 *
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.0.0
 *
 */
function wc_nuvei_gateway_plugin_links( $links ) {

	$plugin_links = [
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nuvei_gateway' ) . '">' . esc_html__( 'Settings',
			'wc-nuvei' ) . '</a>',
	];

	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_nuvei_gateway_plugin_links' );


/**
 * Nuvei Payment Gateway
 *
 * @class        WC_Gateway_Nuvei
 * @extends      WC_Payment_Gateway
 * @package      WooCommerce/Classes/Payment
 * @author       Vladimir Zabara
 */
add_action( 'plugins_loaded', 'wc_nuvei_gateway_init', 11 );

function wc_nuvei_gateway_init() {

	if ( class_exists( "WC_Payment_Gateway_CC", false ) ) {

		class WC_Gateway_Nuvei extends WC_Payment_Gateway_CC {

			public $supports = [
				'products',
				'refunds',
			];

			/**
			 * Constructor for the gateway.
			 */
			public function __construct() {

				$this->id                 = 'nuvei_gateway';
				$this->icon               = '';
				$this->has_fields         = true;
				$this->method_title       = __( 'Nuvei', 'wc-nuvei' );
				$this->method_description = __( 'Take payments in person via Nuvei', 'wc-nuvei' );

				// Load the settings.
				$this->init_form_fields();
				$this->init_settings();

				// Define user set variables
				$this->title        = $this->get_option( 'title' );
				$this->description  = $this->get_option( 'description' );
				$this->instructions = $this->get_option( 'instructions', $this->description );

				// Actions
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id,
					[ $this, 'process_admin_options' ] );
				add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
				// Customer Emails
				add_action( 'woocommerce_email_before_order_table', [ $this, 'email_instructions' ], 10, 3 );
				add_filter( 'transaction_details', [ $this, 'transaction_details' ], 10, 2 );

				$this->init();
			}

			protected function init() {
				if ( ! $this->check_environment() ) {
					return;
				}

				if ( get_site_option( 'NUVEI_DB_VERSION' ) != NUVEI_DB_VERSION ) {
					$this->install_db();
				}
			}

			public function install_db() {
				global $wpdb;

				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

				$installed_ver = get_option( "NUVEI_DB_VERSION" );
				if ( $installed_ver != NUVEI_DB_VERSION ) {
					// See https://codex.wordpress.org/Creating_Tables_with_Plugins
					$sql = "CREATE TABLE " . $wpdb->prefix . "nuvei_transactions (
                     id int(11) NOT NULL AUTO_INCREMENT,
                     order_id int(11) NOT NULL,
                     unique_ref char(10) COLLATE utf8_unicode_ci DEFAULT NULL,
                     data text COLLATE utf8_unicode_ci DEFAULT NULL,
                     date_time datetime NOT NULL,
                     PRIMARY KEY  (id),
                     UNIQUE KEY  unique_ref (unique_ref)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
					dbDelta( $sql );

					update_option( 'NUVEI_DB_VERSION', NUVEI_DB_VERSION );
				}
			}

			/**
			 * Initialize Gateway Settings Form Fields
			 */
			public function init_form_fields() {

				$this->form_fields = apply_filters( 'wc_nuvei_form_fields', [

					'enabled' => [
						'title'   => __( 'Enable/Disable', 'wc-nuvei' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Nuvei', 'wc-nuvei' ),
						'default' => 'yes',
					],

					'title' => [
						'title'       => __( 'Title', 'wc-nuvei' ),
						'type'        => 'text',
						'description' => __( 'This controls the title for the payment method the customer sees during checkout.',
							'wc-nuvei' ),
						'default'     => __( 'Nuvei', 'wc-nuvei' ),
						'desc_tip'    => true,
					],

					'description' => [
						'title'       => __( 'Description', 'wc-nuvei' ),
						'type'        => 'textarea',
						'description' => __( 'Payment method description that the customer will see on your checkout.',
							'wc-nuvei' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'instructions' => [
						'title'       => __( 'Instructions', 'wc-nuvei' ),
						'type'        => 'textarea',
						'description' => __( 'Instructions that will be added to the thank you page and emails.',
							'wc-nuvei' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'account_settings' => [
						'title'       => __( 'Account Settings', 'wc-nuvei' ),
						'type'        => 'title',
						'description' => '',
					],

					'nuvei_endpoint' => [
						'title'       => __( 'Payment XML End Point', 'wc-nuvei' ),
						'type'        => 'text',
						'description' => __( 'Contact Nuvei integration team to get correct URL.', 'wc-nuvei' ),
					],

					'nuvei_terminal_id' => [
						'title'       => __( 'Terminal ID', 'wc-nuvei' ),
						'type'        => 'text',
						'description' => __( 'Retrieve the "Terminal ID" from your Nuvei merchant account.',
							'wc-nuvei' ),
					],

					'nuvei_shared_secret' => [
						'title'       => __( 'Shared Secret', 'wc-nuvei' ),
						'type'        => 'text',
						'description' => __( 'Retrieve the "Shared Secret" from your Nuvei merchant account.',
							'wc-nuvei' ),
					],

				] );
			}

			public function payment_fields() {
				$this->form();
			}

			/**
			 * Outputs fields for entering credit card information.
			 *
			 * @since 2.6.0
			 */
			public function form() {
				wp_enqueue_script( 'wc-credit-card-form' );

				$fields = [];

				$default_fields = [
					'card-number-field'      => '<p class="form-row form-row-wide">
                    <label for="' . esc_attr( $this->id ) . '-card-number">' . esc_html__( 'Card number',
							'wc-nuvei' ) . '&nbsp;<span class="required">*</span></label>
                    <input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . $this->field_name( 'card-number' ) . ' />
                </p>',
					'card-expiry-field'      => '<p class="form-row form-row-first">
                    <label for="' . esc_attr( $this->id ) . '-card-expiry">' . esc_html__( 'Expiry (MM/YY)',
							'wc-nuvei' ) . '&nbsp;<span class="required">*</span></label>
                    <input id="' . esc_attr( $this->id ) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" autocomplete="cc-exp" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="' . esc_attr__( 'MM / YY',
							'wc-nuvei' ) . '" ' . $this->field_name( 'card-expiry' ) . ' />
                </p>',
					'<p class="form-row form-row-last">
                    <label for="' . esc_attr( $this->id ) . '-card-cvc">' . esc_html__( 'Card code', 'wc-nuvei' ) . '&nbsp;<span class="required">*</span></label>
                    <input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="' . esc_attr__( 'CVC',
						'wc-nuvei' ) . '" ' . $this->field_name( 'card-cvc' ) . ' style="width:100px" />
                </p>',
					'card-holder-name-field' => '<p class="form-row form-row-wide">
                    <label for="' . esc_attr( $this->id ) . '-card-holder-name">' . esc_html__( 'Holder Name',
							'wc-nuvei' ) . '&nbsp;<span class="required">*</span></label>
                    <input id="' . esc_attr( $this->id ) . '-card-holder-name" class="input-text wc-credit-card-form-card-holder-name" autocomplete="cc-holder-name" autocorrect="no" autocapitalize="no" spellcheck="no" type="text" placeholder="' . esc_attr__( 'Holder Name',
							'wc-nuvei' ) . '" ' . $this->field_name( 'card-holder-name' ) . ' />
                </p>',
				];

				$fields = wp_parse_args( $fields,
					apply_filters( 'woocommerce_credit_card_form_fields', $default_fields, $this->id ) );
				?>

                <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form"
                          class='wc-credit-card-form wc-payment-form'>
					<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>
					<?php
					foreach ( $fields as $field ) {
						_e( $field );
					}
					?>
					<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
                    <div class="clear"></div>
                </fieldset>
				<?php
			}

			/**
			 * Output for the order received page.
			 */
			public function thankyou_page() {
				if ( $this->instructions ) {
					_e( wpautop( wptexturize( $this->instructions ) ) );
				}
			}


			/**
			 * Add content to the WC emails.
			 *
			 * @access public
			 *
			 * @param WC_Order $order
			 * @param bool $sent_to_admin
			 * @param bool $plain_text
			 */
			public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
				if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
					_e( wpautop( wptexturize( $this->instructions . PHP_EOL ) ) );
				}
			}

			/**
			 * Process the payment and return the result
			 *
			 * @param int $order_id
			 *
			 * @return mixed
			 */
			public function process_payment( $order_id ) {

				$order    = wc_get_order( $order_id );
				$currency = $order->get_currency();
				$amount   = $order->get_total();

				$settings = [
					'endpoint'      => $this->get_option( 'nuvei_endpoint' ),
					'terminal_id'   => $this->get_option( 'nuvei_terminal_id' ),
					'shared_secret' => $this->get_option( 'nuvei_shared_secret' ),
				];

				$settings = apply_filters( 'nuvei_settings', $settings );

				if ( empty( $settings['endpoint'] ) || empty( $settings['terminal_id'] ) || empty( $settings['shared_secret'] ) ) {
					wc_add_notice( esc_html__( 'Incorrect WooCommerce Nuvei Gateway Settings', 'wc-nuvei' ), 'error' );

					return false;
				}
				$date = date( 'd-m-Y:H:i:s:v', time() );
				$hash = md5( $settings['terminal_id'] . $order_id . $amount . $date . $settings['shared_secret'] );

				$card_number = preg_replace( '~[^0-9]~', '',
						sanitize_text_field( $_POST['nuvei_gateway-card-number'] ) ) ?? '';
				$card_expire = preg_replace( '~[^0-9]~', '',
						sanitize_text_field( $_POST['nuvei_gateway-card-expiry'] ) ) ?? '';

				// try to fix wrong expire date format
				if ( strlen( $card_expire ) == 6 ) {
					$month       = substr( $card_expire, 0, 2 );
					$year        = substr( $card_expire, - 2 );
					$card_expire = $month . $year;
				} elseif ( strlen( $card_expire ) == 3 ) {
					$card_expire = '0' . $card_expire;
				}

				$card_type = $this->detect_card_type( $card_number );

				$card_holder      = sanitize_text_field( $_POST['nuvei_gateway-card-holder-name'] );
				$card_cvc         = sanitize_text_field( $_POST['nuvei_gateway-card-cvc'] );
				$terminal_type    = 2; // eCommerce
				$transaction_type = 7; // eCommerce

				$masked_cc_num = substr( $card_number, 0, 4 ) . '****' . substr( $card_number, - 4 );
				$log_date      = date( 'c', time() );
				$log_info      = "
Order ID: {$order_id}
Terminal ID: {$settings['terminal_id']}
Amount: {$amount}
Date: {$log_date}
CC#: {$masked_cc_num}
Card: {$card_type}
Expire: {$card_expire}
Card Holder: {$card_holder}
Hash: {$hash}
Currency: {$currency}
Terminal Type: {$terminal_type}
Transaction Type: {$transaction_type}
";

				$xml_request = "<?xml version='1.0' encoding='UTF-8'?>
<PAYMENT>
    <ORDERID>{$order_id}</ORDERID>
    <TERMINALID>{$settings['terminal_id']}</TERMINALID>
    <AMOUNT>{$amount}</AMOUNT>
    <DATETIME>{$date}</DATETIME>
    <CARDNUMBER>{$card_number}</CARDNUMBER>
    <CARDTYPE>{$card_type}</CARDTYPE>
    <CARDEXPIRY>{$card_expire}</CARDEXPIRY>
    <CARDHOLDERNAME>{$card_holder}</CARDHOLDERNAME>
    <HASH>{$hash}</HASH>
    <CURRENCY>{$currency}</CURRENCY>
    <TERMINALTYPE>{$terminal_type}</TERMINALTYPE>
    <TRANSACTIONTYPE>{$transaction_type}</TRANSACTIONTYPE>
    <CVV>{$card_cvc}</CVV>
</PAYMENT>";

				$args = [
					'body'        => $xml_request,
					'timeout'     => '10',
					'redirection' => '3',
					'blocking'    => true,
					'headers'     => [
						'Cache-Control: no-cache',
						'Content-Type: application/xml',
					],
				];

				$response = wp_remote_post( $settings['endpoint'], $args );
				$body = wp_remote_retrieve_body( $response );
				$array_data = json_decode( json_encode( simplexml_load_string( $body ) ), true );
				if ( isset( $array_data['ERRORSTRING'] ) ) {
					wc_add_notice( esc_html__( $array_data['ERRORSTRING'] ), 'error' );
					wc_get_logger()->critical(
						sprintf( 'Transaction Error: %s, %s', $array_data['ERRORSTRING'], $log_info ),
						[
							'source' => 'nuvei-errors',
						]
					);

					return false;
				} elseif ( isset( $array_data['RESPONSECODE'] ) && in_array( $array_data['RESPONSECODE'],
						[ 'A', 'R' ] ) ) {
					// transaction approved
					$order->payment_complete( $array_data['UNIQUEREF'] );
					// Reduce stock levels
					$order->reduce_order_stock();
					// Remove cart
					WC()->cart->empty_cart();
					// save transaction responce
					global $wpdb;
					$wpdb->insert( $wpdb->prefix . 'nuvei_transactions', [
						'order_id'   => $order_id,
						'unique_ref' => $array_data['UNIQUEREF'],
						'data'       => serialize( $body ),
						'date_time'  => date( 'Y-m-d H:i:s', strtotime( $array_data['DATETIME'] ) ),
					] );

					// Return thankyou redirect
					return [
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					];
				} elseif ( isset( $array_data['RESPONSETEXT'] ) ) {
					wc_add_notice( esc_html__( $array_data['RESPONSETEXT'] ), 'error' );
					wc_get_logger()->critical(
						sprintf( 'Transaction Error: %s, %s', $array_data['RESPONSETEXT'], $log_info ),
						[
							'source' => 'nuvei-errors',
						]
					);

					return false;
				}

				wc_add_notice( esc_html__( 'Unknown WooCommerce Nuvei Gateway Error', 'wc-nuvei' ), 'error' );
			}

			/**
			 * Emulate successful refunds to process requests from ROMPOS.
			 *
			 * @param int $order_id
			 * @param float|null $amount
			 * @param string $reason
			 *
			 * @return bool
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				return true;
			}

			public function transaction_details( $order_id, $details = [] ) {
				global $wpdb;

				$res = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nuvei_transactions WHERE order_id=%d", $order_id ),
					ARRAY_N
				);
				if ( ! empty( $res ) ) {
					$details['transaction'] = $res[0];
				}

				return $details;
			}

			public static function detect_card_type( $num ) {
				$re = [
					'VISA'       => '/^4[0-9]{12}(?:[0-9]{3})?$/',
					'MASTERCARD' => '/^5[1-5][0-9]{14}$/',
					'AMEX'       => '/^3[47][0-9]{13}$/',
					'DISCOVER'   => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
					'DINERS'     => '/^3(?:0[0-59]{1}|[689])[0-9]{0,}$/',
					'JCB'        => '/^(?:2131|1800|35)[0-9]{0,}$/',
				];

				if ( preg_match( $re['VISA'], $num ) ) {
					return 'VISA';
				} elseif ( preg_match( $re['MASTERCARD'], $num ) ) {
					return 'MASTERCARD';
				} elseif ( preg_match( $re['AMEX'], $num ) ) {
					return 'AMEX';
				} elseif ( preg_match( $re['DISCOVER'], $num ) ) {
					return 'DISCOVER';
				} elseif ( preg_match( $re['DINERS'], $num ) ) {
					return 'DINERS';
				} elseif ( preg_match( $re['JCB'], $num ) ) {
					return 'JCB';
				} else {
					return false;
				}
			}

			/**
			 * Check if environment meets requirements
			 *
			 * @access public
			 * @return bool
			 */
			public function check_environment() {
				$is_ok = true;

				// Check PHP version
				if ( ! version_compare( PHP_VERSION, NUVEI_SUPPORT_PHP, '>=' ) ) {
					// Add notice
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( sprintf( 'WooCommerce Nuvei Gateway requires PHP version %s or later.',
								NUVEI_SUPPORT_PHP ), 'wc-nuvei' )
						     . '</p></div>';
					} );
					$is_ok = false;
				}

				// Check WordPress version
				if ( ! $this->wp_version_gte( NUVEI_SUPPORT_WP ) ) {
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( sprintf( 'WooCommerce Nuvei Gateway requires WordPress version %s or later. Please update WordPress to use this plugin.',
								NUVEI_SUPPORT_WP ), 'wc-nuvei' )
						     . '</p></div>';
					} );
					$is_ok = false;
				}

				// Check if WooCommerce is installed and enabled
				if ( ! class_exists( 'WooCommerce' ) ) {
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( 'WooCommerce Nuvei Gateway requires WooCommerce to be active.',
								'wc-nuvei' )
						     . '</p></div>';
					} );
					$is_ok = false;
				} elseif ( ! $this->wc_version_gte( NUVEI_SUPPORT_WC ) ) {
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( sprintf( 'WooCommerce Nuvei Gateway requires WooCommerce version %s or later.',
								NUVEI_SUPPORT_WC ), 'wc-nuvei' )
						     . '</p></div>';
					} );
					$is_ok = false;
				}

				return $is_ok;
			}

			/**
			 * Check WooCommerce version
			 *
			 * @access public
			 *
			 * @param string $version
			 *
			 * @return bool
			 */
			public static function wc_version_gte( $version ) {
				if ( defined( 'WC_VERSION' ) && WC_VERSION ) {
					return version_compare( WC_VERSION, $version, '>=' );
				} elseif ( defined( 'WOOCOMMERCE_VERSION' ) && WOOCOMMERCE_VERSION ) {
					return version_compare( WOOCOMMERCE_VERSION, $version, '>=' );
				} else {
					return false;
				}
			}

			/**
			 * Check WordPress version
			 *
			 * @access public
			 *
			 * @param string $version
			 *
			 * @return bool
			 */
			public static function wp_version_gte( $version ) {
				$wp_version = get_bloginfo( 'version' );

				// Treat release candidate strings
				$wp_version = preg_replace( '/-RC.+/i', '', $wp_version );

				if ( $wp_version ) {
					return version_compare( $wp_version, $version, '>=' );
				}

				return false;
			}

			/**
			 * Check PHP version
			 *
			 * @access public
			 *
			 * @param string $version
			 *
			 * @return bool
			 */
			public static function php_version_gte( $version ) {
				return version_compare( PHP_VERSION, $version, '>=' );
			}
		}
	}
}