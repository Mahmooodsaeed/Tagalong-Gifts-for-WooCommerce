<?php
/**
 * TEST SITE ONLY. Loaded as a must-use plugin into the throwaway WordPress Playground site
 * that `npm test` starts. Never install this on a real store: its REST routes are open.
 *
 * - POST /wp-json/tagalong-test/v1/setup       creates the store settings, products and coupons
 * - GET  /wp-json/tagalong-test/v1/order/<id>  returns an order's lines, meta and notes
 * - GET  /wp-json/tagalong-test/v1/log         returns PHP errors, warnings and notices from this plugin
 *
 * @package TagalongGifts
 */

defined( 'ABSPATH' ) || exit;

// The test site can't send email. Pretend it did, so orders don't fill up with "failed to send" notes.
// Plain-text emails also skip WooCommerce's CSS inliner, which crashes Playground's PHP 7.4 build.
add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'woocommerce_email_content_type',
	static function () {
		return 'text/plain';
	}
);

// Also exercises the code-based configuration: coupon "codegift" gives a mug via the filter.
add_filter(
	'tagalong_gift_coupon_map',
	static function ( $map ) {
		$mug = (int) get_option( 'tagalong_test_mug_id' );
		if ( $mug ) {
			$map['CODEGIFT'] = $mug;
		}
		return $map;
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'tagalong-test/v1',
			'/setup',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'tagalong_test_setup',
			)
		);
		register_rest_route(
			'tagalong-test/v1',
			'/order/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'tagalong_test_order',
			)
		);
		register_rest_route(
			'tagalong-test/v1',
			'/log',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					$file  = WP_CONTENT_DIR . '/debug.log';
					$lines = file_exists( $file ) ? file( $file, FILE_IGNORE_NEW_LINES ) : array();
					if ( $r['all'] ) {
						return array_slice( $lines, -80 ); // Everything, for debugging.
					}
					return array_values(
						array_filter(
							$lines,
							static function ( $l ) {
								return false !== strpos( $l, 'tagalong-gifts-for-woocommerce' );
							}
						)
					);
				},
			)
		);
	}
);

/**
 * Create (once) everything the tests need. Returns the IDs.
 *
 * @return array
 */
function tagalong_test_setup( WP_REST_Request $r ) {
	$ids = get_option( 'tagalong_test_ids' );
	if ( $ids ) {
		return $ids;
	}

	// Order storage: "hpos" (High-Performance Order Storage) or "posts" (legacy), as the test asks.
	$hpos = 'hpos' === $r['storage'];
	if ( $hpos ) {
		wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
	}
	update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );

	// A plain store: US dollars, no tax, no shipping, guest checkout, "Check payments" enabled.
	update_option( 'woocommerce_coming_soon', 'no' );
	update_option( 'woocommerce_store_pages_only', 'no' );
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_default_country', 'US:CA' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_enable_coupons', 'yes' );
	update_option( 'woocommerce_enable_guest_checkout', 'yes' );
	update_option( 'woocommerce_cart_redirect_after_add', 'no' );
	update_option( 'woocommerce_cheque_settings', array( 'enabled' => 'yes', 'title' => 'Check payments' ) );
	update_option( 'blogname', 'Demo Store' );
	WC_Install::create_pages();

	$simple = static function ( $name, $price, $extra = array() ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( $price );
		$p->set_virtual( true );
		foreach ( $extra as $k => $v ) {
			$p->{"set_$k"}( $v );
		}
		return $p->save();
	};

	$shirt = $simple( 'Classic T-Shirt', '20' );
	// No stock management here: Playground's SQLite database can't run WooCommerce's MySQL-only
	// stock reservation query at checkout. The out-of-stock case is covered by the cap.
	$mug   = $simple( 'Coffee Mug', '8' );
	$cap   = $simple( 'Sold Out Cap', '5', array( 'stock_status' => 'outofstock' ) );

	// Variable hoodie with sizes S and M.
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$hoodie = new WC_Product_Variable();
	$hoodie->set_name( 'Hoodie' );
	$hoodie->set_attributes( array( $attr ) );
	$hoodie_id = $hoodie->save();
	$variations = array();
	foreach ( array( 'S', 'M' ) as $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $hoodie_id );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_regular_price( '30' );
		$v->set_virtual( true );
		$variations[ $size ] = $v->save();
	}
	WC_Product_Variable::sync( $hoodie_id );

	$coupon = static function ( $code, $gift, $extra = array() ) {
		$c = new WC_Coupon();
		$c->set_code( $code );
		$c->set_discount_type( 'fixed_cart' );
		$c->set_amount( 0 );
		foreach ( $extra as $k => $v ) {
			$c->{"set_$k"}( $v );
		}
		$id = $c->save();
		if ( $gift ) {
			update_post_meta( $id, '_tagalong_gift_product_id', $gift );
		}
		return $id;
	};

	$ids = array(
		'shirt'   => $shirt,
		'mug'     => $mug,
		'cap'     => $cap,
		'hoodie'  => $hoodie_id,
		'hoodieM' => $variations['M'],
		'coupons' => array(
			'freemug'    => $coupon( 'FreeMug', $mug ),
			'hoodiegift' => $coupon( 'HoodieGift', $variations['M'] ),
			'soldout'    => $coupon( 'SoldOut', $cap ),
			'spend50'    => $coupon( 'Spend50', $mug, array( 'minimum_amount' => '50' ) ),
			'codegift'   => $coupon( 'CodeGift', 0 ),
			'tenoff'     => $coupon( 'TenOff', 0, array( 'amount' => '10' ) ),
		),
	);

	// Classic (shortcode) cart and checkout pages, next to the block ones WooCommerce creates.
	$ids['classic_cart']     = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Classic Cart', 'post_name' => 'classic-cart', 'post_content' => '[woocommerce_cart]' ) );
	$ids['classic_checkout'] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Classic Checkout', 'post_name' => 'classic-checkout', 'post_content' => '[woocommerce_checkout]' ) );

	$ids['storage'] = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts';

	update_option( 'tagalong_test_mug_id', $mug );
	update_option( 'tagalong_test_ids', $ids );
	return $ids;
}

/**
 * An order's lines (with meta and formatted meta), totals and notes.
 *
 * @param WP_REST_Request $r Request.
 * @return array|WP_Error
 */
function tagalong_test_order( WP_REST_Request $r ) {
	$order = wc_get_order( (int) $r['id'] );
	if ( ! $order ) {
		return new WP_Error( 'not_found', 'No order', array( 'status' => 404 ) );
	}
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$formatted = array();
		foreach ( $item->get_formatted_meta_data() as $m ) {
			$formatted[] = wp_strip_all_tags( $m->display_key ) . ': ' . wp_strip_all_tags( $m->display_value );
		}
		$items[] = array(
			'name'       => $item->get_name(),
			'product_id' => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
			'quantity'   => $item->get_quantity(),
			'total'      => (float) $item->get_total(),
			'gift'       => $item->get_meta( '_tagalong_gift_coupon' ),
			'meta'       => $formatted,
		);
	}
	$notes = array_map(
		static function ( $n ) {
			return $n->content;
		},
		wc_get_order_notes( array( 'order_id' => $order->get_id() ) )
	);
	return array(
		'edit_url' => $order->get_edit_order_url(),
		'total'   => (float) $order->get_total(),
		'coupons' => $order->get_coupon_codes(),
		'items'   => $items,
		'notes'   => $notes,
	);
}
