<?php
/**
 * Plugin Name:          Tagalong Gifts for WooCommerce
 * Plugin URI:           https://github.com/Mahmooodsaeed/Gift-Coupon-for-WooCommerce
 * Description:          Give customers a free product when they apply a coupon. Choose the gift on the coupon screen.
 * Version:              2.1.0
 * Author:               Mahmood Saeed
 * Author URI:           https://www.linkedin.com/in/mahmood-i-saeed
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          tagalong-gifts-for-woocommerce
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.5
 * WC tested up to:      11.1
 *
 * @package TagalongGifts
 */

defined( 'ABSPATH' ) || exit;

define( 'TAGALONG_GIFTS_VERSION', '2.1.0' );
define( 'TAGALONG_GIFTS_FILE', __FILE__ );

/**
 * Coupon-driven free gifts.
 *
 * How it fits together:
 * - The gift product is stored on the coupon (meta `_tagalong_gift_product_id`), or supplied
 *   in code through the `tagalong_gift_coupon_map` filter.
 * - The gift is a separate cart line, marked with `tagalong_gift_for` => coupon code. That keeps it
 *   apart from any paid units of the same product, and it survives page loads with the session.
 * - The line is always priced at 0 and locked to quantity 1, so it really is free.
 * - Coupon and gift are kept in sync in both directions: remove one and the other goes too.
 */
final class Tagalong_Gifts {

	/** Coupon meta key holding the gift product or variation ID. */
	const META_KEY = '_tagalong_gift_product_id';

	/** Cart item key that marks a line as a gift. Value: the coupon code. */
	const CART_KEY = 'tagalong_gift_for';

	/** Order line item meta key that marks a line as a gift. Value: the coupon code. */
	const ITEM_META = '_tagalong_gift_coupon';

	/** Order meta flag so the order note is only written once. */
	const NOTE_FLAG = '_tagalong_gift_note_added';

	/**
	 * Set while this plugin removes gift lines itself, so it doesn't react to its own removals.
	 *
	 * @var bool
	 */
	private $removing = false;

	/**
	 * Per-request cache of coupon code => gift product ID.
	 *
	 * @var int[]
	 */
	private $gift_ids = array();

	/** Register hooks. */
	public function __construct() {
		// Settings on the coupon screen.
		add_action( 'woocommerce_coupon_options', array( $this, 'render_coupon_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_field' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( TAGALONG_GIFTS_FILE ), array( $this, 'action_links' ) );

		// Coupon <-> gift.
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon' ), 10, 2 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_coupon_applied' ) );
		add_action( 'woocommerce_removed_coupon', array( $this, 'on_coupon_removed' ) );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_gift_removed' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'on_gift_restored' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_cart' ), 20 );

		// Cart display (classic cart and mini cart; the block cart reads the same item data).
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'free_price_html' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'free_price_html' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'fixed_quantity_html' ), 10, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_totals_html' ), 10, 3 );

		// Block cart: gift quantity can't be changed.
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( $this, 'store_api_editable' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( $this, 'store_api_maximum' ), 10, 3 );

		// Orders.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'tag_order_item' ), 10, 3 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'order_item_meta' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_order_item_meta' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'add_order_note' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'add_order_note' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Configuration
	|--------------------------------------------------------------------------
	*/

	/**
	 * The gift product (or variation) ID for a coupon code, or 0 if the coupon has no gift.
	 *
	 * @param string $code Coupon code, any case.
	 * @return int
	 */
	public function gift_id_for( $code ) {
		$code = wc_format_coupon_code( $code );
		if ( '' === $code ) {
			return 0;
		}
		if ( isset( $this->gift_ids[ $code ] ) ) {
			return $this->gift_ids[ $code ];
		}

		$product_id = 0;

		/**
		 * Map coupon codes to gift products in code instead of on the coupon screen.
		 * Codes are matched case-insensitively. A code here wins over the coupon setting.
		 *
		 * @param array $map Coupon code => product or variation ID.
		 */
		$map = (array) apply_filters( 'tagalong_gift_coupon_map', array() );
		foreach ( $map as $map_code => $map_id ) {
			if ( wc_format_coupon_code( (string) $map_code ) === $code ) {
				$product_id = absint( $map_id );
				break;
			}
		}

		if ( ! $product_id ) {
			$coupon_id = wc_get_coupon_id_by_code( $code );
			if ( $coupon_id ) {
				$product_id = absint( get_post_meta( $coupon_id, self::META_KEY, true ) );
			}
		}

		/**
		 * Final say on which product a coupon gives away. Return 0 for no gift.
		 *
		 * @param int    $product_id Product or variation ID.
		 * @param string $code       Coupon code (lowercase).
		 */
		$product_id = absint( apply_filters( 'tagalong_gift_product_id', $product_id, $code ) );

		$this->gift_ids[ $code ] = $product_id;
		return $product_id;
	}

	/**
	 * Whether a product can be given as a gift right now.
	 * Returns an empty string if it can, or a customer-facing reason if it can't.
	 *
	 * @param WC_Product|false|null $product Product.
	 * @return string
	 */
	public function unavailable_reason( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return __( 'Sorry, the free gift for this coupon no longer exists.', 'tagalong-gifts-for-woocommerce' );
		}
		if ( ! self::is_giftable_type( $product ) ) {
			return __( 'Sorry, the free gift for this coupon is set up incorrectly. Please contact us.', 'tagalong-gifts-for-woocommerce' );
		}
		if ( ! $product->is_purchasable() ) {
			return __( 'Sorry, the free gift for this coupon is not available right now.', 'tagalong-gifts-for-woocommerce' );
		}
		if ( ! $product->is_in_stock() ) {
			return __( 'Sorry, the free gift for this coupon is out of stock right now.', 'tagalong-gifts-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Only products that go into the cart as one exact item can be gifts: simple products and
	 * fully specified variations. A variable product, or a variation with an "Any" attribute,
	 * would need the customer to choose options.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_giftable_type( WC_Product $product ) {
		if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			foreach ( $product->get_variation_attributes() as $value ) {
				if ( '' === $value ) {
					return false;
				}
			}
		}
		return true;
	}

	/*
	|--------------------------------------------------------------------------
	| Coupon screen
	|--------------------------------------------------------------------------
	*/

	/**
	 * "Free gift product" field in the coupon's General tab.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon    Coupon.
	 */
	public function render_coupon_field( $coupon_id, $coupon = null ) {
		$product_id = absint( get_post_meta( $coupon_id, self::META_KEY, true ) );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		?>
		<p class="form-field">
			<label for="tagalong_gift_product_id"><?php esc_html_e( 'Free gift product', 'tagalong-gifts-for-woocommerce' ); ?></label>
			<select
				class="wc-product-search"
				style="width: 50%;"
				id="tagalong_gift_product_id"
				name="tagalong_gift_product_id"
				data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'tagalong-gifts-for-woocommerce' ); ?>"
				data-action="woocommerce_json_search_products_and_variations"
				data-exclude_type="variable,grouped,external"
				data-allow_clear="true"
			>
				<?php if ( $product ) : ?>
					<option value="<?php echo esc_attr( (string) $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
				<?php endif; ?>
			</select>
			<?php echo wc_help_tip( __( 'When a customer applies this coupon, one of this product is added to their cart for free. Removing the gift also removes the coupon. Choose a simple product or a specific variation.', 'tagalong-gifts-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes. ?>
		</p>
		<?php
	}

	/**
	 * Save the field.
	 *
	 * @param int       $post_id Coupon ID.
	 * @param WC_Coupon $coupon  Coupon.
	 */
	public function save_coupon_field( $post_id, $coupon = null ) {
		// WooCommerce checks these too before this hook runs; checked again here so this function is safe on its own.
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$product_id = isset( $_POST['tagalong_gift_product_id'] ) ? absint( wp_unslash( $_POST['tagalong_gift_product_id'] ) ) : 0;

		if ( ! $product_id ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! self::is_giftable_type( $product ) ) {
			WC_Admin_Meta_Boxes::add_error( __( 'The free gift was not saved. Choose a simple product or a variation with every option set (not a variable product, and no "Any" options).', 'tagalong-gifts-for-woocommerce' ) );
			return;
		}

		update_post_meta( $post_id, self::META_KEY, $product_id );
	}

	/**
	 * "Coupons" link on the Plugins screen, since that's where the settings live.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=shop_coupon' ) ), esc_html__( 'Coupons', 'tagalong-gifts-for-woocommerce' ) )
		);
		return $links;
	}

	/*
	|--------------------------------------------------------------------------
	| Coupon <-> gift
	|--------------------------------------------------------------------------
	*/

	/**
	 * Refuse a gift coupon when its gift can't be given, with a clear message.
	 *
	 * @param bool      $valid  Whether the coupon is valid so far.
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool
	 * @throws Exception When the gift is unavailable. WooCommerce shows the message to the customer.
	 */
	public function validate_coupon( $valid, $coupon ) {
		if ( ! $valid || ! $coupon instanceof WC_Coupon ) {
			return $valid;
		}
		$gift_id = $this->gift_id_for( $coupon->get_code() );
		if ( ! $gift_id ) {
			return $valid;
		}
		$reason = $this->unavailable_reason( wc_get_product( $gift_id ) );
		if ( '' !== $reason ) {
			throw new Exception( esc_html( $reason ) );
		}
		return $valid;
	}

	/**
	 * Coupon applied: add its gift.
	 *
	 * @param string $code Coupon code.
	 */
	public function on_coupon_applied( $code ) {
		$cart = WC()->cart;
		$code = wc_format_coupon_code( $code );
		if ( ! $cart || ! $this->gift_id_for( $code ) || $this->find_gift_keys( $cart, $code ) ) {
			return;
		}

		$key = $this->add_gift( $cart, $code );
		if ( $key ) {
			$this->notice(
				sprintf(
					/* translators: %s: gift product name */
					__( 'Your free gift has been added to your cart: %s.', 'tagalong-gifts-for-woocommerce' ),
					'<strong>' . esc_html( $cart->cart_contents[ $key ]['data']->get_name() ) . '</strong>'
				),
				'success'
			);
			return;
		}

		// The gift couldn't be added (add_to_cart() has already said why), so the coupon goes too.
		$cart->remove_coupon( $code );
	}

	/**
	 * Coupon removed (by the customer, or by WooCommerce because it's no longer valid): remove its gift.
	 *
	 * @param string $code Coupon code.
	 */
	public function on_coupon_removed( $code ) {
		$cart = WC()->cart;
		if ( $cart ) {
			$this->remove_gifts( $cart, wc_format_coupon_code( $code ) );
		}
	}

	/**
	 * Customer removed the gift: remove the coupon that came with it.
	 *
	 * @param string  $key  Removed cart item key.
	 * @param WC_Cart $cart Cart.
	 */
	public function on_gift_removed( $key, $cart ) {
		if ( $this->removing || empty( $cart->removed_cart_contents[ $key ][ self::CART_KEY ] ) ) {
			return;
		}
		$code = $cart->removed_cart_contents[ $key ][ self::CART_KEY ];

		/**
		 * Whether removing a gift also removes its coupon. Default true.
		 *
		 * @param bool   $remove Remove the coupon.
		 * @param string $code   Coupon code.
		 */
		if ( ! apply_filters( 'tagalong_remove_coupon_with_gift', true, $code ) ) {
			return;
		}
		if ( $cart->has_discount( $code ) && ! $this->find_gift_keys( $cart, $code ) ) {
			$cart->remove_coupon( $code );
			$this->notice(
				sprintf(
					/* translators: %s: coupon code */
					__( 'You removed the free gift, so coupon "%s" was removed too.', 'tagalong-gifts-for-woocommerce' ),
					esc_html( $code )
				),
				'notice'
			);
		}
	}

	/**
	 * Customer clicked "Undo" after removing the gift: put the coupon back, or drop the gift if the
	 * coupon can't be applied any more.
	 *
	 * @param string  $key  Restored cart item key.
	 * @param WC_Cart $cart Cart.
	 */
	public function on_gift_restored( $key, $cart ) {
		if ( empty( $cart->cart_contents[ $key ][ self::CART_KEY ] ) ) {
			return;
		}
		$code = $cart->cart_contents[ $key ][ self::CART_KEY ];
		if ( $cart->has_discount( $code ) || $cart->apply_coupon( $code ) ) {
			return;
		}
		$this->remove_line( $cart, $key );
	}

	/**
	 * Runs before every total calculation. Keeps each gift free and at quantity 1, and fixes any
	 * mismatch that got past the other hooks (for example, all coupons cleared at once).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public function sync_cart( $cart ) {
		if ( ! $cart instanceof WC_Cart || $this->removing ) {
			return;
		}

		$applied = array_map( 'wc_format_coupon_code', $cart->get_applied_coupons() );

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			if ( ! in_array( $item[ self::CART_KEY ], $applied, true ) ) {
				// Coupon is gone, so the gift goes.
				$this->remove_line( $cart, $key );
				continue;
			}
			if ( 1 !== (int) $item['quantity'] ) {
				$cart->cart_contents[ $key ]['quantity'] = 1;
			}
			$cart->cart_contents[ $key ]['data']->set_price( 0 );
		}

		// A gift coupon without its gift (the gift was removed some other way) is removed as well.
		foreach ( $applied as $code ) {
			if ( $this->gift_id_for( $code ) && ! $this->find_gift_keys( $cart, $code ) ) {
				$cart->remove_coupon( $code );
			}
		}
	}

	/**
	 * Show a notice on classic pages. Skipped for the block cart (Store API), which has no place to
	 * show it, so it doesn't pop up later on an unrelated page.
	 *
	 * @param string $message Message HTML.
	 * @param string $type    success or notice.
	 */
	private function notice( $message, $type ) {
		if ( method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() ) {
			return;
		}
		wc_add_notice( $message, $type );
	}

	/**
	 * Add the gift line for a coupon.
	 *
	 * @param WC_Cart $cart Cart.
	 * @param string  $code Coupon code.
	 * @return string|false Cart item key, or false.
	 */
	private function add_gift( WC_Cart $cart, $code ) {
		$product = wc_get_product( $this->gift_id_for( $code ) );
		if ( '' !== $this->unavailable_reason( $product ) ) {
			return false;
		}

		$data = array( self::CART_KEY => $code );

		try {
			if ( $product->is_type( 'variation' ) ) {
				return $cart->add_to_cart( $product->get_parent_id(), 1, $product->get_id(), $product->get_variation_attributes(), $data );
			}
			return $cart->add_to_cart( $product->get_id(), 1, 0, array(), $data );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Remove every gift line that belongs to a coupon.
	 *
	 * @param WC_Cart $cart Cart.
	 * @param string  $code Coupon code.
	 */
	private function remove_gifts( WC_Cart $cart, $code ) {
		foreach ( $this->find_gift_keys( $cart, $code ) as $key ) {
			$this->remove_line( $cart, $key );
		}
	}

	/**
	 * Remove a gift line without offering "Undo" and without triggering our own coupon removal.
	 *
	 * @param WC_Cart $cart Cart.
	 * @param string  $key  Cart item key.
	 */
	private function remove_line( WC_Cart $cart, $key ) {
		$this->removing = true;
		$cart->remove_cart_item( $key );
		unset( $cart->removed_cart_contents[ $key ] );
		$this->removing = false;
	}

	/**
	 * Keys of the gift lines that belong to a coupon.
	 *
	 * @param WC_Cart $cart Cart.
	 * @param string  $code Coupon code.
	 * @return string[]
	 */
	private function find_gift_keys( WC_Cart $cart, $code ) {
		$keys = array();
		foreach ( $cart->get_cart_contents() as $key => $item ) {
			if ( isset( $item[ self::CART_KEY ] ) && $item[ self::CART_KEY ] === $code ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/*
	|--------------------------------------------------------------------------
	| Cart display
	|--------------------------------------------------------------------------
	*/

	/**
	 * "Free gift: with coupon X" under the product name (classic and block cart, checkout, mini cart).
	 *
	 * @param array $item_data Item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item[ self::CART_KEY ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Free gift', 'tagalong-gifts-for-woocommerce' ),
				/* translators: %s: coupon code */
				'value' => sprintf( __( 'with coupon %s', 'tagalong-gifts-for-woocommerce' ), $cart_item[ self::CART_KEY ] ),
			);
		}
		return $item_data;
	}

	/**
	 * Show "Free" instead of 0.00 for the gift's price and subtotal in the classic cart.
	 *
	 * @param string $html      Price HTML.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public function free_price_html( $html, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $html;
		}
		/**
		 * HTML shown instead of the gift's price in the classic cart.
		 *
		 * @param string $label     Label HTML.
		 * @param array  $cart_item Cart item.
		 */
		return apply_filters( 'tagalong_free_price_html', '<span class="tagalong-free">' . esc_html__( 'Free', 'tagalong-gifts-for-woocommerce' ) . '</span>', $cart_item );
	}

	/**
	 * Cart totals: a gift-only coupon shows "Free gift" instead of "-$0.00".
	 *
	 * @param string    $html          Coupon row HTML (amount + remove link).
	 * @param WC_Coupon $coupon        Coupon.
	 * @param string    $discount_html Amount HTML.
	 * @return string
	 */
	public function coupon_totals_html( $html, $coupon, $discount_html = '' ) {
		if ( ! $coupon instanceof WC_Coupon || '' === $discount_html || ! WC()->cart || ! $this->gift_id_for( $coupon->get_code() ) ) {
			return $html;
		}
		if ( 0.0 !== (float) WC()->cart->get_coupon_discount_amount( $coupon->get_code(), WC()->cart->display_cart_ex_tax ) ) {
			return $html;
		}
		return str_replace( $discount_html, esc_html__( 'Free gift', 'tagalong-gifts-for-woocommerce' ), $html );
	}

	/**
	 * Classic cart: show a fixed "1" instead of a quantity box for the gift.
	 *
	 * @param string $html      Quantity input HTML.
	 * @param string $key       Cart item key.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public function fixed_quantity_html( $html, $key, $cart_item = array() ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $html;
		}
		return sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', esc_attr( $key ) );
	}

	/**
	 * Block cart: the gift's quantity selector is read-only.
	 *
	 * @param bool       $editable  Editable.
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return bool
	 */
	public function store_api_editable( $editable, $product, $cart_item = null ) {
		return empty( $cart_item[ self::CART_KEY ] ) ? $editable : false;
	}

	/**
	 * Block cart: the gift's maximum quantity is 1.
	 *
	 * @param int|float  $maximum   Maximum.
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return int|float
	 */
	public function store_api_maximum( $maximum, $product, $cart_item = null ) {
		return empty( $cart_item[ self::CART_KEY ] ) ? $maximum : 1;
	}

	/*
	|--------------------------------------------------------------------------
	| Orders
	|--------------------------------------------------------------------------
	*/

	/**
	 * Carry the gift marker from the cart to the order line (classic and block checkout).
	 *
	 * @param WC_Order_Item_Product $item   Order item.
	 * @param string                $key    Cart item key.
	 * @param array                 $values Cart item.
	 */
	public function tag_order_item( $item, $key, $values ) {
		if ( ! empty( $values[ self::CART_KEY ] ) ) {
			$item->add_meta_data( self::ITEM_META, $values[ self::CART_KEY ], true );
		}
	}

	/**
	 * Show "Free gift: with coupon X" on the order line in the admin, emails and My Account.
	 *
	 * @param array         $meta Formatted meta.
	 * @param WC_Order_Item $item Order item.
	 * @return array
	 */
	public function order_item_meta( $meta, $item ) {
		$code = $item->get_meta( self::ITEM_META );
		if ( $code ) {
			// Display-only entry. Its key differs from the stored (hidden) marker so the admin shows it.
			$meta['tagalong_gift'] = (object) array(
				'key'           => 'tagalong_free_gift',
				'value'         => $code,
				'display_key'   => __( 'Free gift', 'tagalong-gifts-for-woocommerce' ),
				/* translators: %s: coupon code */
				'display_value' => esc_html( sprintf( __( 'with coupon %s', 'tagalong-gifts-for-woocommerce' ), $code ) ),
			);
		}
		return $meta;
	}

	/**
	 * Hide the raw marker in the order admin; the readable "Free gift" line is shown instead.
	 *
	 * @param string[] $keys Hidden meta keys.
	 * @return string[]
	 */
	public function hide_order_item_meta( $keys ) {
		$keys[] = self::ITEM_META;
		return $keys;
	}

	/**
	 * Private order note listing each gift and its coupon. Runs after the order is saved.
	 *
	 * @param WC_Order $order Order.
	 */
	public function add_order_note( $order ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( self::NOTE_FLAG ) ) {
			return;
		}
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$code = $item->get_meta( self::ITEM_META );
			if ( $code ) {
				$lines[] = sprintf(
					/* translators: 1: product name, 2: product ID, 3: coupon code */
					__( 'Free gift "%1$s" (#%2$d) added with coupon "%3$s".', 'tagalong-gifts-for-woocommerce' ),
					$item->get_name(),
					$item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					$code
				);
			}
		}
		if ( $lines ) {
			$order->add_order_note( implode( "\n", $lines ) );
			$order->update_meta_data( self::NOTE_FLAG, 1 );
			$order->save();
		}
	}
}

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TAGALONG_GIFTS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TAGALONG_GIFTS_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			$GLOBALS['tagalong_gifts'] = new Tagalong_Gifts();
		}
	}
);
