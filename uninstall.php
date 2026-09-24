<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. Removes the gift setting from coupons.
 * Past orders keep their "Free gift" details.
 *
 * @package TagalongGifts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_post_meta_by_key( '_tagalong_gift_product_id' );
