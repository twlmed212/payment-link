/**
 * "Credit / Debit Card – Secure Payment Link" in the Checkout block.
 * Plain script (no build step): uses the globals WooCommerce and WordPress load.
 */
( function () {
	var wc = window.wc || {};
	var wp = window.wp || {};
	if ( ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var decode = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function ( s ) { return s; };
	var data = wc.wcSettings.getSetting( 'syssell_payment_link_data', {} );
	var title = decode( data.title || '' );
	var description = decode( data.description || '' );
	var icons = Array.isArray( data.icons ) ? data.icons : [];

	function Label( props ) {
		var c = props.components || {};
		var text = c.PaymentMethodLabel ? el( c.PaymentMethodLabel, { text: title } ) : el( 'span', null, title );
		var logos = icons.length && c.PaymentMethodIcons ? el( c.PaymentMethodIcons, { icons: icons, align: 'right' } ) : null;
		return el( 'span', { className: 'spl-block-label' }, text, logos );
	}

	function Content() {
		return description ? el( 'p', { className: 'spl-block-description' }, description ) : null;
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'syssell_payment_link',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
