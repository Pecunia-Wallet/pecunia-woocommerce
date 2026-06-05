import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { createElement } from '@wordpress/element';

const settings = getSetting( 'pecunia_data', {} );

const defaultLabel = __( 'Cryptocurrency payments', 'woocommerce-gateway-pecunia' );

const label = decodeEntities( settings.title ) || defaultLabel;
const icon = settings.icon || '';

const Content = () => {
	return createElement(
		'div',
		{ className: 'wc-block-components-payment-method-description' },
		decodeEntities( settings.description || '' )
	);
};

const Label = () => {
	return createElement(
		'span',
		{
			className: 'wc-block-components-payment-method-label',
			style: {
				display: 'inline-flex',
				alignItems: 'center',
				gap: '10px',
			},
		},
		icon
			? createElement( 'img', {
					className: 'wc-pecunia-payment-method-icon',
					src: icon,
					alt: '',
					'aria-hidden': 'true',
					style: {
						width: '24px',
						height: '24px',
						objectFit: 'contain',
						flex: '0 0 auto',
					},
				} )
			: null,
		createElement(
			'span',
			{ className: 'wc-pecunia-payment-method-label-text' },
			label
		)
	);
};

const Pecunia = {
	name: 'pecunia',
	label: createElement( Label ),
	content: createElement( Content ),
	edit: createElement( Content ),
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};

registerPaymentMethod( Pecunia );
