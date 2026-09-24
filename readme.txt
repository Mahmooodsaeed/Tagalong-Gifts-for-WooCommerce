=== Tagalong Gifts for WooCommerce ===
Contributors: moodithegamer
Tags: woocommerce, coupon, free gift, gift, promotion
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Give customers a free product when they apply a coupon. Choose the gift on the coupon screen and it's added to the cart at no cost.

== Description ==

Run offers like "Use code FREEMUG and get a mug free" with the coupons you already use.

Choose a **Free gift product** on any coupon. When a customer applies that coupon, one of that product is added to their cart for free. The gift tags along with the coupon: remove the coupon and the gift goes too, and the other way round. The gift is clearly labelled on the order, so whoever packs it knows why it's there.

= Features =

* **Adds the gift automatically** when the coupon is applied.
* **Really free.** The gift is priced at 0 in the cart total, not just labelled "Free".
* **One gift per coupon.** The customer can't raise the gift's quantity. If they also buy the same product, those units stay paid and appear as a separate line.
* **Coupon and gift stay together.** Remove one and the other goes too, including when WooCommerce drops the coupon because its rules are no longer met (for example, minimum spend).
* **Refuses a coupon whose gift isn't available** (out of stock, deleted or not for sale), with a clear message.
* **Labelled everywhere:** "Free gift: with coupon freemug" in the cart, checkout, order emails, My Account and the order screen, plus a private order note.
* **Works with the Cart and Checkout blocks and the classic cart and checkout.**
* **Compatible with High-Performance Order Storage (HPOS).**
* Uses WooCommerce's own coupon rules: expiry dates, minimum spend, usage limits and so on.
* No settings page, no extra database tables, no scripts or styles loaded on your store.

= For developers =

* `tagalong_gift_coupon_map`: map coupon codes to product or variation IDs in code.
* `tagalong_gift_product_id`: decide the gift for a coupon at runtime.
* `tagalong_remove_coupon_with_gift`: return `false` to keep the coupon when the gift is removed.
* `tagalong_free_price_html`: change the "Free" label in the classic cart.

The source code and end-to-end tests are on [GitHub](https://github.com/Mahmooodsaeed/Gift-Coupon-for-WooCommerce).

== Installation ==

1. Install and activate WooCommerce.
2. Go to **Plugins → Add New Plugin**, search for "Tagalong Gifts for WooCommerce", then install and activate it.
3. Go to **Marketing → Coupons** and create or edit a coupon.
4. On the **General** tab, search for a product under **Free gift product**. For a gift-only coupon, use **Fixed cart discount** with **Coupon amount 0**.
5. Publish the coupon.

== Frequently Asked Questions ==

= Which products can be a gift? =

A simple product, or one exact variation such as "Hoodie – Medium". Variable products can't be chosen, because the customer would need to pick options. The product needs a price and must be in stock. The customer never pays that price.

= How do I stop people buying the gift on its own? =

Edit the gift product and set **Catalog visibility** to **Hidden**. It can still be given as a gift.

= Can a coupon give a discount as well as a gift? =

Yes. Set the coupon's discount type and amount as usual, then also choose a free gift product.

= What happens if the gift runs out of stock? =

New customers can't apply the coupon. They see "Sorry, the free gift for this coupon is out of stock right now." A customer who already has the gift in their cart gets WooCommerce's usual out-of-stock message at checkout, as with any product.

= Can the customer choose between several gifts? =

Not at the moment. Each coupon gives one specific product.

= Does it work when I add a coupon to an order in the admin? =

The coupon is applied, but the gift isn't added automatically. Add the gift product to the order by hand.

= What does it store, and what happens when I delete the plugin? =

It stores the chosen gift on each coupon, a hidden marker on gift order lines, and a flag on orders that got a gift note. Deleting the plugin removes the coupon setting. Past orders keep their gift details.

== Screenshots ==

1. The classic cart: the gift is shown as Free, its quantity is fixed, and the total doesn't include it.
2. The block cart: the gift's original price is crossed out and its quantity can't be changed.
3. Choose the Free gift product on the coupon's General tab.
4. The order screen shows the gift line and the coupon that added it.

== Changelog ==

= 2.1.0 =
* First release on WordPress.org.
* Renamed to Tagalong Gifts for WooCommerce. Code prefix changed from `gcl_` to `tagalong_`. If you installed 2.0.0 from GitHub, choose the gift on your coupons again after updating.
* The coupon screen checks its security token and user permission itself before saving.

= 2.0.0 =
* Rewritten as a plugin: gift chosen on the coupon screen, gift really free and locked to quantity 1, coupon codes match in any letter case, Cart and Checkout blocks and HPOS supported, order note fixed, unavailable gifts refuse the coupon.

= 1.0.0 =
* Original code snippet.

== Upgrade Notice ==

= 2.1.0 =
First WordPress.org release. If you used 2.0.0 from GitHub, choose the gift on your coupons again.
