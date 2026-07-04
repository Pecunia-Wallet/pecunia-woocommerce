<?php
declare(strict_types=1);

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WC_Gateway_Pecunia_Blocks_Support extends AbstractPaymentMethodType
{
	private $gateway;

	protected $name = 'pecunia';

	public function initialize(): void
	{
		$this->settings = get_option( 'woocommerce_pecunia_settings', array() );
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->gateway = $gateways[ $this->name ] ?? null;
	}

	public function is_active(): bool
	{
		return $this->gateway instanceof WC_Gateway_Pecunia && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles(): array
	{
		$script_path = '/assets/js/frontend/blocks.js';
		$script_asset_path = WC_Pecunia_Wallet_Gateway::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
		$script_asset = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities' ),
				'version'      => '1.0.0',
			);
		$script_url = WC_Pecunia_Wallet_Gateway::plugin_url() . $script_path;

		wp_register_script(
			'wc-pecunia-wallet-blocks',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'wc-pecunia-wallet-blocks',
				'pecunia-wallet-payment-gateway',
				WC_Pecunia_Wallet_Gateway::plugin_abspath() . 'languages/'
			);
		}

		return array( 'wc-pecunia-wallet-blocks' );
	}

	public function get_payment_method_data(): array
	{
		return array(
			'title'       => $this->gateway ? $this->gateway->get_title() : '',
			'description' => $this->gateway ? $this->gateway->get_description() : '',
			'icon'        => $this->gateway ? $this->gateway->get_gateway_icon_url() : '',
			'supports'    => $this->gateway ? array_values( array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) ) : array(),
		);
	}
}
