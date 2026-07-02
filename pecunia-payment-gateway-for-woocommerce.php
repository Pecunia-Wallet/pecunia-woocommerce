<?php
/*
 * Copyright (C) 2026 Pecunia GmbH
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Plugin Name: WooCommerce Pecunia Wallet Gateway
 * Plugin URI: https://pecuniawallet.com/
 * Description: Adds the Pecunia Wallet Gateway to your WooCommerce website.
 * Version: 1.0.4
 * Author: Pecunia GmbH
 * Author URI: https://pecuniawallet.com/
 * Text Domain: woocommerce-gateway-pecunia
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PECUNIA_WOOCOMMERCE_VERSION', '1.0.4' );
define( 'PECUNIA_WOOCOMMERCE_FILE', __FILE__ );
define( 'PECUNIA_WOOCOMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PECUNIA_WOOCOMMERCE_URL', plugin_dir_url( __FILE__ ) );

require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/vendor/Psr/Http/Message/ResponseInterface.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/vendor/GuzzleHttp/Exception/GuzzleException.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/vendor/GuzzleHttp/Exception/RequestException.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/vendor/GuzzleHttp/Stream.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/vendor/GuzzleHttp/Response.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/vendor/GuzzleHttp/Client.php';

require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Exceptions/ApiException.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Exceptions/BadRequestException.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Exceptions/NotFoundException.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Exceptions/TooManyRequestsException.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Exceptions/UnauthorizedException.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Models/Currency.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Models/Invoice.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Models/InvoiceRef.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Models/ItemList.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Models/Settings.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Models/Transaction.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Client.php';
require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/Pecunia/Callback.php';

final class WC_Pecunia_Wallet_Gateway {

	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'bootstrap' ), 0 );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_blocks_support' ) );
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
	}

	public static function plugin_abspath(): string {
		return PECUNIA_WOOCOMMERCE_PATH;
	}

	public static function plugin_url(): string {
		return PECUNIA_WOOCOMMERCE_URL;
	}

	public static function bootstrap(): void {
		load_plugin_textdomain( 'woocommerce-gateway-pecunia', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/class-wc-pecunia-order.php';
		require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/class-wc-gateway-pecunia.php';

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'ensure_invoice_polling' ), 20 );
		add_action( 'pecunia_poll_pending_invoices', array( __CLASS__, 'poll_pending_invoices' ) );
	}

	public static function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'WooCommerce Pecunia Wallet Gateway requires WooCommerce to be installed and active.', 'woocommerce-gateway-pecunia' );
		echo '</p></div>';
	}

	public static function register_gateway( array $gateways ): array {
		$gateways[] = 'WC_Gateway_Pecunia';
		return $gateways;
	}

	public static function register_blocks_support(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once PECUNIA_WOOCOMMERCE_PATH . 'includes/blocks/class-wc-pecunia-wallet-blocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $payment_method_registry ) {
				$payment_method_registry->register( new WC_Gateway_Pecunia_Blocks_Support() );
			}
		);
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['pecunia_hourly'] = array(
			'interval' => 3600,
			'display'  => __( 'Every hour', 'woocommerce-gateway-pecunia' ),
		);

		return $schedules;
	}

	public static function ensure_invoice_polling(): void {
		if ( ! class_exists( 'WC_Gateway_Pecunia' ) ) {
			return;
		}

		$gateway = new WC_Gateway_Pecunia();
		if ( method_exists( $gateway, 'has_api_credentials' ) && ! $gateway->has_api_credentials() ) {
			return;
		}

		WC_Gateway_Pecunia::schedule_polling_action();
	}

	public static function poll_pending_invoices(): void {
		if ( ! class_exists( 'WC_Gateway_Pecunia' ) ) {
			return;
		}

		$gateway = new WC_Gateway_Pecunia();
		$gateway->poll_pending_invoices();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'pecunia_poll_pending_invoices', array(), 'pecunia' );
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'pecunia_poll_pending_invoices' );
		}
	}
}

WC_Pecunia_Wallet_Gateway::init();

register_deactivation_hook( __FILE__, array( 'WC_Pecunia_Wallet_Gateway', 'deactivate' ) );
