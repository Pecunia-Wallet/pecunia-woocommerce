<?php
declare(strict_types=1);

use Pecunia\Callback;
use Pecunia\Client;
use Pecunia\Exceptions\ApiException;
use Pecunia\Exceptions\NotFoundException;
use Pecunia\Models\Invoice;
use Pecunia\Models\InvoiceRef;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WC_Gateway_Pecunia extends WC_Payment_Gateway
{
	private const SYNC_TTL_SECONDS = 60;
	private const POLL_INTERVAL_SECONDS = 3600;
	private const POLL_BATCH_SIZE = 20;
	private const POLL_MIN_AGE_SECONDS = 900;
	private const POLL_HOOK = 'pecunia_poll_pending_invoices';
	private const ICON_RELATIVE_PATH = 'assets/images/pecunia-logo.png';
	private const STATUS_RANK = array(
		'pending'    => 10,
		'staggering' => 20,
		'completed'  => 30,
		'overpaid'   => 30,
		'expired'    => 40,
	);

	public string $api_token = '';
	public string $callback_secret = '';
	public string $previous_callback_secret = '';
	public string $instructions = '';
	private string $base_uri = 'https://pecuniawallet.com/api/';

	private Client $client;
	private WC_Pecunia_Order $orders;
	private static bool $payment_page_rendered = false;

	public function __construct()
	{
		$this->id                 = 'pecunia';
		$this->icon               = $this->get_gateway_icon_url();
		$this->has_fields         = false;
		$this->method_title       = __( 'Pecunia', 'woocommerce-gateway-pecunia' );
		$this->method_description = __( 'Allows cryptocurrency payments via Pecunia Wallet.', 'woocommerce-gateway-pecunia' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title                    = (string) $this->get_option( 'title', __( 'Cryptocurrency payments', 'woocommerce-gateway-pecunia' ) );
		$this->description              = (string) $this->get_option( 'description', __( 'Pay with cryptocurrency through Pecunia.', 'woocommerce-gateway-pecunia' ) );
		$this->instructions             = $this->description;
		$this->api_token                = (string) $this->get_option( 'api_token', '' );
		$this->callback_secret          = (string) $this->get_option( 'callback_secret', '' );
		$this->previous_callback_secret = (string) $this->get_option( 'previous_callback_secret', '' );
		$this->base_uri                 = rtrim( (string) $this->get_option( 'base_uri', 'https://pecuniawallet.com/api/' ), '/' ) . '/';

		$this->client = new Client( $this->api_token, $this->base_uri );
		$this->orders = new WC_Pecunia_Order();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_wc_gateway_pecunia', array( $this, 'handle_callback' ) );
	}

	public function get_gateway_icon_url(): string
	{
		$path = PECUNIA_WOOCOMMERCE_PATH . self::ICON_RELATIVE_PATH;
		if ( ! file_exists( $path ) ) {
			return '';
		}

		return PECUNIA_WOOCOMMERCE_URL . self::ICON_RELATIVE_PATH;
	}

	public function get_icon(): string
	{
		$icon_url = $this->get_gateway_icon_url();
		if ( $icon_url === '' ) {
			return '';
		}

		return sprintf(
			'<img src="%s" alt="%s" style="max-height:24px;width:auto;vertical-align:middle;" />',
			esc_url( $icon_url ),
			esc_attr( $this->method_title )
		);
	}

	public static function schedule_polling_action(): void
	{
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( as_next_scheduled_action( self::POLL_HOOK, array(), 'pecunia' ) ) {
				return;
			}

			as_schedule_recurring_action( time() + self::POLL_INTERVAL_SECONDS, self::POLL_INTERVAL_SECONDS, self::POLL_HOOK, array(), 'pecunia' );
			return;
		}

		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
			if ( wp_next_scheduled( self::POLL_HOOK ) ) {
				return;
			}

			wp_schedule_event( time() + self::POLL_INTERVAL_SECONDS, 'pecunia_hourly', self::POLL_HOOK );
		}
	}

	public function has_api_credentials(): bool
	{
		return $this->api_token !== '' && $this->callback_secret !== '';
	}

	public function poll_pending_invoices(): void
	{
		if ( ! $this->has_api_credentials() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		try {
			$orderIds = wc_get_orders( array(
				'limit'        => self::POLL_BATCH_SIZE,
				'return'       => 'ids',
				'orderby'      => 'date',
				'order'        => 'ASC',
				'payment_method' => $this->id,
				'meta_query'   => array(
					array(
						'key'     => WC_Pecunia_Order::META_INVOICE_STATUS,
						'value'   => array( 'pending', 'staggering' ),
						'compare' => 'IN',
					),
				),
			) );
		} catch ( Throwable $e ) {
			wc_get_logger()->warning( 'Unable to query Pecunia invoices for polling: ' . $e->getMessage(), array( 'source' => 'pecunia' ) );
			return;
		}

		if ( empty( $orderIds ) ) {
			return;
		}

		foreach ( $orderIds as $orderId ) {
			$order = wc_get_order( (int) $orderId );
			if ( ! $order ) {
				continue;
			}

			try {
				$stored = $this->get_stored_invoice( $order );
				if ( ! $stored ) {
					continue;
				}

				$status = $this->normalizeStatus( (string) ( $stored['status'] ?? 'pending' ) );
				if ( ! in_array( $status, array( 'pending', 'staggering' ), true ) ) {
					continue;
				}

				if ( $this->is_invoice_recent( $stored, self::POLL_MIN_AGE_SECONDS ) ) {
					continue;
				}

				$refresh = $this->refresh_invoice_from_api( $order, $stored );
				$invoice = $refresh['invoice'] ?? $stored;
				$state = (string) ( $refresh['state'] ?? 'error' );
				$invoiceId = (string) ( $invoice['invoice_id'] ?? $stored['invoice_id'] ?? '' );
				$payload = array();
				if ( isset( $invoice['payload'] ) && is_array( $invoice['payload'] ) ) {
					$payload = $invoice['payload'];
				}

				if ( $state === 'missing' ) {
					if ( ! $order->is_paid() ) {
						$this->applyStatusToOrder( $order, 'expired', $payload, $invoiceId, $status );
					}
					continue;
				}

				$liveStatus = $this->normalizeStatus( (string) ( $invoice['status'] ?? $status ) );
				if ( in_array( $liveStatus, array( 'completed', 'overpaid', 'expired' ), true ) ) {
					$this->applyStatusToOrder( $order, $liveStatus, $payload, $invoiceId, $status );
				}
			} catch ( Throwable $e ) {
				wc_get_logger()->warning( 'Unable to poll Pecunia invoice: ' . $e->getMessage(), array( 'source' => 'pecunia' ) );
			}
		}
	}
	private function is_invoice_recent( array $stored, int $thresholdSeconds ): bool
	{
		$createdAt = isset( $stored['created_at'] ) ? (string) $stored['created_at'] : '';
		if ( $createdAt === '' ) {
			return false;
		}

		$timestamp = strtotime( $createdAt );
		if ( $timestamp === false ) {
			return false;
		}

		return ( time() - $timestamp ) < $thresholdSeconds;
	}

	public function is_available(): bool
	{
		if ( ! parent::is_available() ) {
			return false;
		}

		return ! empty( $this->api_token ) && ! empty( $this->callback_secret );
	}

	public function init_form_fields(): void
	{
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable', 'woocommerce-gateway-pecunia' ),
				'label'   => __( 'Enable cryptocurrency payments via Pecunia', 'woocommerce-gateway-pecunia' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce-gateway-pecunia' ),
				'type'        => 'text',
				'description' => __( 'Title shown to customers at checkout.', 'woocommerce-gateway-pecunia' ),
				'default'     => __( 'Cryptocurrency payments', 'woocommerce-gateway-pecunia' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce-gateway-pecunia' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown to customers at checkout.', 'woocommerce-gateway-pecunia' ),
				'default'     => __( 'Pay securely with cryptocurrency.', 'woocommerce-gateway-pecunia' ),
			),
			'api_token' => array(
				'title'       => __( 'API token', 'woocommerce-gateway-pecunia' ),
				'type'        => 'password',
				'description' => __( 'Token used for Pecunia API requests.', 'woocommerce-gateway-pecunia' ),
				'default'     => '',
			),
			'callback_secret' => array(
				'title'       => __( 'Callback secret', 'woocommerce-gateway-pecunia' ),
				'type'        => 'password',
				'description' => __( 'Secret used to verify callback signatures.', 'woocommerce-gateway-pecunia' ),
				'default'     => '',
			),
			'previous_callback_secret' => array(
				'title'       => __( 'Previous callback secret', 'woocommerce-gateway-pecunia' ),
				'type'        => 'password',
				'description' => __( 'Optional previous callback secret for key rotation.', 'woocommerce-gateway-pecunia' ),
				'default'     => '',
			),
		);
	}

	public function process_admin_options(): bool
	{
		$ok = parent::process_admin_options();

		$this->api_token                = (string) $this->get_option( 'api_token', '' );
		$this->callback_secret          = (string) $this->get_option( 'callback_secret', '' );
		$this->previous_callback_secret = (string) $this->get_option( 'previous_callback_secret', '' );
		$this->base_uri                 = rtrim( (string) $this->get_option( 'base_uri', 'https://pecuniawallet.com/api/' ), '/' ) . '/';
		$this->client                   = new Client( $this->api_token, $this->base_uri );

		$this->sync_callback_endpoint();

		return $ok;
	}

	public function process_payment( $order_id ): array
	{
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'The order could not be loaded.', 'woocommerce-gateway-pecunia' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			$this->sync_callback_endpoint();
			$invoice = $this->get_invoice_for_order( $order, true );
		} catch ( Throwable $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'pecunia' ) );
			wc_add_notice( __( 'Unable to create or reuse a payment invoice right now. Please try again.', 'woocommerce-gateway-pecunia' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! $invoice ) {
			wc_add_notice( __( 'Unable to create or reuse a payment invoice right now. Please try again.', 'woocommerce-gateway-pecunia' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'pending', __( 'Awaiting Pecunia payment.', 'woocommerce-gateway-pecunia' ) );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_order_payment_url( $order ),
		);
	}

	public function receipt_page( $order_id ): void
	{
		$this->render_payment_page( $order_id );
	}

	public function thankyou_page( $order_id ): void
	{
		$this->render_payment_page( $order_id );
	}

	private function render_payment_page( $order_id ): void
	{
		if ( self::$payment_page_rendered ) {
			return;
		}
		self::$payment_page_rendered = true;

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		try {
			$invoice = $this->get_invoice_for_order( $order, false );
		} catch ( Throwable $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'pecunia' ) );
			return;
		}

		if ( ! $invoice ) {
			return;
		}

		$invoiceId = $invoice['invoice_id'] ?? '';
		$paymentUrl = (string) ( $invoice['payment_url'] ?? $invoice['invoice_url'] ?? $invoice['href'] ?? '' );
		if ( $paymentUrl === '' && ! empty( $invoice['payload']['paymentUrl'] ) ) {
			$paymentUrl = (string) $invoice['payload']['paymentUrl'];
		}
		if ( $paymentUrl === '' && $invoiceId !== '' ) {
			$paymentUrl = \Pecunia\Models\Invoice::paymentUrl( (string) $invoiceId );
		}
		$status = $invoice['status'] ?? 'pending';
		$amount = $invoice['amount'] ?? $order->get_total();
		$currency = $invoice['currency'] ?? get_woocommerce_currency();
		include PECUNIA_WOOCOMMERCE_PATH . 'resources/templates/invoice.php';
	}

	public function handle_callback(): void
	{
		$raw = file_get_contents( 'php://input' );
		if ( $raw === false ) {
			$raw = '';
		}

		$header = $_SERVER['HTTP_X_SIGNATURE'] ?? null;
		$match = Callback::verifySignature(
			$raw,
			is_string( $header ) ? $header : null,
			$this->callback_secret,
			$this->previous_callback_secret !== '' ? $this->previous_callback_secret : $this->callback_secret
		);

		if ( $match === false ) {
			status_header( 403 );
			wp_die( esc_html__( 'Invalid callback signature.', 'woocommerce-gateway-pecunia' ), '', array( 'response' => 403 ) );
		}

		$payload = $this->parseCallbackPayload( $raw );
		if ( empty( $payload ) ) {
			status_header( 400 );
			wp_die( esc_html__( 'Empty callback payload.', 'woocommerce-gateway-pecunia' ), '', array( 'response' => 400 ) );
		}

		$invoiceId = $this->extractCallbackValue( $payload, array( 'invoiceId', 'invoice_id', 'id', 'txn_id' ) );
		$status = $this->normalizeStatus( (string) $this->extractCallbackValue( $payload, array( 'status', 'state' ), 'pending' ) );
		$orderId = $this->extractOrderIdFromPayload( $payload );

		if ( $orderId <= 0 ) {
			$status_header = 200;
			status_header( $status_header );
			wp_die( 'ok', '', array( 'response' => $status_header ) );
		}

		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			status_header( 404 );
			wp_die( esc_html__( 'Order not found.', 'woocommerce-gateway-pecunia' ), '', array( 'response' => 404 ) );
		}

		$storedInvoice = $this->get_stored_invoice( $order );
		if ( ! $storedInvoice || ( $invoiceId !== '' && $storedInvoice['invoice_id'] !== '' && $storedInvoice['invoice_id'] !== $invoiceId ) ) {
			$metaInvoiceId = (string) $order->get_meta( WC_Pecunia_Order::META_INVOICE_ID, true );
			if ( $metaInvoiceId !== '' && $invoiceId !== '' && $metaInvoiceId !== $invoiceId ) {
				status_header( 200 );
				wp_die( 'ok', '', array( 'response' => 200 ) );
			}
		}

		$callbackHash = hash( 'sha256', $raw );
		if ( $this->orders->getCallbackHash( $order ) === $callbackHash ) {
			status_header( 200 );
			wp_die( 'ok', '', array( 'response' => 200 ) );
		}
		$this->orders->markCallbackHash( $order, $callbackHash );

		$this->applyStatusToOrder( $order, $status, $payload, $invoiceId );

		status_header( 200 );
		wp_die( 'ok', '', array( 'response' => 200 ) );
	}

	private function get_invoice_for_order( WC_Order $order, bool $allowCreate = true ): ?array
	{
		$fingerprint = $this->buildFingerprint( $order );
		$stored = $this->get_stored_invoice( $order );
		$createNew = false;

		if ( $stored && $stored['fingerprint'] === $fingerprint ) {
			$status = $this->normalizeStatus( $stored['status'] );

			if ( in_array( $status, array( 'completed', 'overpaid' ), true ) ) {
				return $stored;
			}

			if ( in_array( $status, array( 'pending', 'staggering' ), true ) ) {
				if ( $this->should_refresh_invoice_state( $stored ) ) {
					$refresh = $this->refresh_invoice_from_api( $order, $stored );
					$refreshedInvoice = $refresh['invoice'] ?? $stored;
					$refreshState = $refresh['state'] ?? 'error';

					if ( $refreshState === 'ok' ) {
						return $refreshedInvoice;
					}

					if ( $refreshState === 'expired' || $refreshState === 'missing' ) {
						if ( ! $allowCreate ) {
							return $refreshedInvoice;
						}

						$createNew = true;
					} else {
						return $refreshedInvoice;
					}
				}

				if ( ! $createNew ) {
					return $stored;
				}
			}

			if ( $status === 'expired' ) {
				if ( ! $allowCreate ) {
					return $stored;
				}

				$createNew = true;
			}
		}

		if ( ! $allowCreate ) {
			return $stored;
		}

		if ( ! $createNew && $stored && $stored['fingerprint'] === $fingerprint ) {
			return $stored;
		}

		$created = $this->createInvoiceForOrder( $order, $fingerprint );
		$this->orders->saveInvoice( $order, $created );

		return $created;
	}

	private function createInvoiceForOrder( WC_Order $order, string $fingerprint ): array
	{
		$body = array_merge(
			array(
				'amount'         => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'sourceCurrency' => get_woocommerce_currency(),
			),
			$this->get_callback_endpoint_payload(),
			array(
				'meta' => array(
					'order_id'       => $order->get_id(),
					'order_number'   => $order->get_order_number(),
					'order_key'      => $order->get_order_key(),
					'fingerprint'    => $fingerprint,
					'payment_method' => $this->id,
					'store_currency' => get_woocommerce_currency(),
					'store_url'      => home_url( '/' ),
				),
			)
		);

		$ref = $this->client->createInvoice( $body );

		if ( ! $ref instanceof InvoiceRef ) {
			throw new RuntimeException( 'Pecunia invoice reference was not returned.' );
		}

		$created = array(
			'invoice_id'   => $ref->id,
			'href'         => $ref->href,
			'payment_url'  => $ref->paymentUrl,
			'status'       => 'pending',
			'fingerprint'  => $fingerprint,
			'created_at'   => gmdate( 'c' ),
			'last_sync_at' => gmdate( 'c' ),
			'expires_at'   => '',
			'payload'      => array( 'id' => $ref->id, 'href' => $ref->href, 'paymentUrl' => $ref->paymentUrl ),
		);

		try {
			$live = $this->client->getInvoice( $ref->id, true );
			$normalized = $this->normalizeInvoice( $live );
			$created['payment_url'] = $normalized['payment_url'] ?: $created['payment_url'];
			$created['status'] = $normalized['status'] ?: 'pending';
			$created['amount'] = $normalized['amount'] ?? (string) $order->get_total();
			$created['currency'] = $normalized['currency'] ?? get_woocommerce_currency();
			$created['expires_at'] = $normalized['expires_at'] ?? '';
			$created['payload'] = $normalized['payload'] ?? $created['payload'];
			$created['last_sync_at'] = $normalized['last_sync_at'] ?? gmdate( 'c' );
		} catch ( Throwable $e ) {

		}

		return $created;
	}

	private function normalizeInvoice( Invoice $invoice ): array
	{
		$expiresAt = '';
		if ( $invoice->expires && isset( $invoice->expires->date ) && $invoice->expires->date instanceof DateTimeImmutable ) {
			$expiresAt = $invoice->expires->date->format( DATE_ATOM );
		}

		return array(
			'invoice_id'  => $invoice->id,
			'href'        => '',
			'payment_url' => $invoice->paymentUrl,
			'status'      => $this->normalizeStatus( $invoice->status ),
			'fingerprint' => '',
			'created_at'   => $invoice->creationDate->format( DATE_ATOM ),
			'last_sync_at' => gmdate( 'c' ),
			'expires_at'   => $expiresAt,
			'payload'      => array(
				'id'                  => $invoice->id,
				'paymentUrl'          => $invoice->paymentUrl,
				'status'              => $invoice->status,
				'amount'              => (array) $invoice->amount,
				'sourceCurrency'      => $invoice->sourceCurrency,
				'creationDate'        => $invoice->creationDate->format( DATE_ATOM ),
				'expires'             => $invoice->expires,
				'closureDate'         => $invoice->closureDate ? $invoice->closureDate->format( DATE_ATOM ) : null,
				'availableCoins'      => $invoice->availableCoins,
				'operationCoin'       => $invoice->operationCoin,
				'exchangeRate'        => $invoice->exchangeRate,
				'relatedTransactions' => $invoice->relatedTransactions,
				'meta'                => $invoice->meta,
			),
			'amount'      => isset( $invoice->amount->requested ) ? (string) $invoice->amount->requested : '0',
			'currency'    => $invoice->sourceCurrency,
			'meta'        => $invoice->meta,
		);
	}

	private function get_stored_invoice( WC_Order $order ): ?array
	{
		$stored = $this->orders->get( $order->get_id() );
		if ( ! $stored || $stored['invoice_id'] === '' ) {
			return null;
		}

		$payload = array();
		if ( $stored['payload'] !== '' ) {
			$decoded = json_decode( $stored['payload'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		$paymentUrl = (string) ( $stored['payment_url'] ?? $stored['invoice_url'] ?? $stored['href'] ?? '' );
		if ( $paymentUrl === '' && isset( $payload['paymentUrl'] ) ) {
			$paymentUrl = (string) $payload['paymentUrl'];
		}
		if ( $paymentUrl === '' && $stored['invoice_id'] !== '' ) {
			$paymentUrl = \Pecunia\Models\Invoice::paymentUrl( (string) $stored['invoice_id'] );
		}

		return array(
			'order'        => $stored['order'],
			'invoice_id'   => $stored['invoice_id'],
			'invoice_url'  => $stored['invoice_url'],
			'payment_url'  => $paymentUrl,
			'href'         => $stored['href'],
			'status'       => $stored['status'] ?: 'pending',
			'fingerprint'  => $stored['fingerprint'],
			'created_at'   => $stored['created_at'],
			'last_sync_at' => $stored['last_sync_at'],
			'expires_at'   => $stored['expires_at'] ?? '',
			'payload'      => $payload,
			'amount'       => $order->get_total(),
			'currency'     => get_woocommerce_currency(),
		);
	}

	private function should_refresh_invoice_state( array $stored ): bool
	{
		if ( $this->is_invoice_sync_stale( $stored ) ) {
			return true;
		}

		$expiresAt = isset( $stored['expires_at'] ) ? (string) $stored['expires_at'] : '';
		if ( $expiresAt === '' ) {
			return false;
		}

		$timestamp = strtotime( $expiresAt );
		if ( $timestamp === false ) {
			return false;
		}

		return time() >= $timestamp;
	}

	private function refresh_invoice_from_api( WC_Order $order, array $stored ): array
	{
		$invoiceId = isset( $stored['invoice_id'] ) ? (string) $stored['invoice_id'] : '';
		if ( $invoiceId === '' ) {
			return array(
				'state'  => 'missing',
				'invoice'=> $stored,
			);
		}

		try {
			$live = $this->client->getInvoice( $invoiceId, true );
			$normalized = $this->normalizeInvoice( $live );
			$normalized['fingerprint'] = (string) ( $stored['fingerprint'] ?? '' );
			$this->orders->saveInvoice( $order, $normalized );

			$status = $this->normalizeStatus( (string) ( $normalized['status'] ?? 'pending' ) );
			if ( in_array( $status, array( 'expired' ), true ) ) {
				return array(
					'state'  => 'expired',
					'invoice'=> $normalized,
				);
			}

			return array(
				'state'  => 'ok',
				'invoice'=> $normalized,
			);
		} catch ( NotFoundException $e ) {
			return array(
				'state'  => 'missing',
				'invoice'=> $stored,
			);
		} catch ( Throwable $e ) {
			wc_get_logger()->warning(
				'Unable to refresh Pecunia invoice state: ' . $e->getMessage(),
				array( 'source' => 'pecunia' )
			);
			return array(
				'state'  => 'error',
				'invoice'=> $stored,
			);
		}
	}

	private function applyStatusToOrder( WC_Order $order, string $incomingStatus, array $payload, string $invoiceId = '', ?string $currentStatusOverride = null ): void
	{
		$currentStatus = $currentStatusOverride !== null
			? $this->normalizeStatus( $currentStatusOverride )
			: $this->normalizeStatus( (string) $order->get_meta( WC_Pecunia_Order::META_INVOICE_STATUS, true ) );
		if ( $this->statusRank( $currentStatus ) >= $this->statusRank( $incomingStatus ) ) {
			if ( $invoiceId !== '' ) {
				$this->orders->updateStatus( $order, $currentStatus );
			}
			return;
		}

		$this->orders->updateStatus( $order, $incomingStatus );

		if ( $invoiceId !== '' ) {
			$order->update_meta_data( WC_Pecunia_Order::META_INVOICE_ID, $invoiceId );
		}

		if ( isset( $payload['paymentUrl'] ) ) {
			$order->update_meta_data( WC_Pecunia_Order::META_INVOICE_URL, (string) $payload['paymentUrl'] );
		}

		$order->save();

		if ( in_array( $incomingStatus, array( 'pending', 'staggering' ), true ) ) {
			$order->update_status( 'pending', __( 'Awaiting Pecunia payment.', 'woocommerce-gateway-pecunia' ) );
			$order->add_order_note( sprintf( __( 'Invoice %1$s is still awaiting payment (%2$s).', 'woocommerce-gateway-pecunia' ), $invoiceId ?: $order->get_meta( WC_Pecunia_Order::META_INVOICE_ID, true ), $incomingStatus ) );
			return;
		}

		if ( in_array( $incomingStatus, array( 'completed', 'overpaid' ), true ) ) {
			$order->update_meta_data( WC_Pecunia_Order::META_INVOICE_STATUS, $incomingStatus );
			$order->save();

			if ( ! $order->is_paid() ) {
				$order->payment_complete( $invoiceId ?: null );
			}

			$note = $incomingStatus === 'overpaid'
				? __( 'Payment completed with an overpayment. Surplus handling is managed by the merchant.', 'woocommerce-gateway-pecunia' )
				: __( 'Payment completed successfully.', 'woocommerce-gateway-pecunia' );

			$order->add_order_note( $note . ' ' . sprintf( __( 'Invoice ID: %s', 'woocommerce-gateway-pecunia' ), $invoiceId ?: (string) $order->get_meta( WC_Pecunia_Order::META_INVOICE_ID, true ) ) );
			return;
		}

		if ( $incomingStatus === 'expired' ) {
			if ( ! $order->is_paid() ) {
				$order->update_status( 'failed', __( 'Pecunia invoice expired before payment was completed.', 'woocommerce-gateway-pecunia' ) );
			}
			$order->add_order_note( sprintf( __( 'Invoice %s expired.', 'woocommerce-gateway-pecunia' ), $invoiceId ?: (string) $order->get_meta( WC_Pecunia_Order::META_INVOICE_ID, true ) ) );
		}
	}

	private function get_callback_url(): string
	{
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && is_object( $wc ) && method_exists( $wc, 'api_request_url' ) ) {
				return (string) $wc->api_request_url( 'WC_Gateway_Pecunia' );
			}
		}

		return add_query_arg( 'wc-api', 'WC_Gateway_Pecunia', home_url( '/' ) );
	}

	private function get_callback_endpoint_payload(): array
	{
		$callbackUrl = $this->get_callback_url();

		return array(
			'defaultCallbackUrl' => $callbackUrl,
		);
	}

	private function sync_callback_endpoint(): void
	{
		if ( $this->api_token === '' || $this->callback_secret === '' ) {
			return;
		}

		$callbackUrl = $this->get_callback_url();
		$syncHash = hash( 'sha256', $callbackUrl . '|' . $this->base_uri );
		$transientKey = 'pecunia_callback_sync_' . md5( $this->base_uri );
		$lastSyncHash = get_transient( $transientKey );
		if ( is_string( $lastSyncHash ) && hash_equals( $lastSyncHash, $syncHash ) ) {
			return;
		}

		try {
			$settings = $this->client->getSettings();
			$needsSync = ! isset( $settings->defaultCallbackUrl )
				|| (string) $settings->defaultCallbackUrl !== $callbackUrl;

			if ( ! $needsSync ) {
				set_transient( $transientKey, $syncHash, self::CALLBACK_SYNC_TTL_SECONDS );
				return;
			}

			$this->client->updateSettings( $this->get_callback_endpoint_payload() );
			set_transient( $transientKey, $syncHash, self::CALLBACK_SYNC_TTL_SECONDS );
		} catch ( Throwable $e ) {
			wc_get_logger()->warning(
				'Unable to sync Pecunia callback endpoint: ' . $e->getMessage(),
				array( 'source' => 'pecunia' )
			);
		}
	}

	private function get_order_payment_url( WC_Order $order ): string
	{
		return $order->get_checkout_payment_url( true );
	}

	private function is_invoice_sync_stale( array $stored ): bool
	{
		$lastSyncAt = isset( $stored['last_sync_at'] ) ? (string) $stored['last_sync_at'] : '';
		if ( $lastSyncAt === '' ) {
			return true;
		}

		$timestamp = strtotime( $lastSyncAt );
		if ( $timestamp === false ) {
			return true;
		}

		return ( time() - $timestamp ) >= self::SYNC_TTL_SECONDS;
	}

	private function parseCallbackPayload( string $raw ): array
	{
		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		$parsed = array();
		parse_str( $raw, $parsed );
		if ( is_array( $parsed ) && ! empty( $parsed ) ) {
			return $parsed;
		}

		return wp_unslash( $_POST );
	}

	private function extractCallbackValue( array $payload, array $keys, $default = '' )
	{
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $payload ) && $payload[ $key ] !== null && $payload[ $key ] !== '' ) {
				return $payload[ $key ];
			}
		}

		return $default;
	}

	private function extractOrderIdFromPayload( array $payload ): int
	{
		$meta = $this->extractCallbackValue( $payload, array( 'meta' ), array() );
		if ( is_string( $meta ) && $meta !== '' ) {
			$decoded = json_decode( $meta, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			} else {
				$decoded = base64_decode( $meta, true );
				if ( $decoded !== false ) {
					$json = json_decode( $decoded, true );
					if ( is_array( $json ) ) {
						$meta = $json;
					}
				}
			}
		}

		if ( is_array( $meta ) ) {
			foreach ( array( 'order_id', 'orderId', 'order_number' ) as $key ) {
				if ( isset( $meta[ $key ] ) && (int) $meta[ $key ] > 0 ) {
					return (int) $meta[ $key ];
				}
			}
		}

		foreach ( array( 'order_id', 'orderId', 'order_number' ) as $key ) {
			if ( isset( $payload[ $key ] ) && (int) $payload[ $key ] > 0 ) {
				return (int) $payload[ $key ];
			}
		}

		return 0;
	}

	private function buildFingerprint( WC_Order $order ): string
	{
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'order_id' => $order->get_id(),
					'total'    => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
					'currency' => get_woocommerce_currency(),
				)
			)
		);
	}

	private function normalizeStatus( string $status ): string
	{
		$status = strtolower( trim( $status ) );

		return match ( $status ) {
			'awaiting' => 'pending',
			'paid' => 'completed',
			default => $status,
		};
	}

	private function statusRank( string $status ): int
	{
		return self::STATUS_RANK[ $this->normalizeStatus( $status ) ] ?? 0;
	}
}
