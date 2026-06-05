<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pecunia-payment-box" style="max-width: 820px; margin: 2rem 0; padding: 1.25rem; border: 1px solid #ddd; border-radius: 12px;">
	<h2 style="margin-top: 0;"><?php echo esc_html__( 'Complete your cryptocurrency payment', 'woocommerce-gateway-pecunia' ); ?></h2>
	<p>
		<?php
		echo esc_html(
			sprintf(

				__( 'Invoice amount: %1$s %2$s', 'woocommerce-gateway-pecunia' ),
				(string) $amount,
				(string) $currency
			)
		);
		?>
	</p>
	<?php if ( ! empty( $invoiceId ) ) : ?>
		<p>
			<strong><?php echo esc_html__( 'Invoice ID:', 'woocommerce-gateway-pecunia' ); ?></strong>
			<?php echo esc_html( (string) $invoiceId ); ?>
		</p>
	<?php endif; ?>
	<?php if ( empty( $paymentUrl ) && ! empty( $invoiceId ) ) : ?>
		<?php $paymentUrl = \Pecunia\Models\Invoice::paymentUrl( (string) $invoiceId ); ?>
	<?php endif; ?>
	<?php if ( ! empty( $paymentUrl ) ) : ?>
		<p>
			<a class="button alt" href="<?php echo esc_url( $paymentUrl ); ?>" target="_blank" rel="noopener noreferrer">
				<?php echo esc_html__( 'Proceed to payment', 'woocommerce-gateway-pecunia' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
