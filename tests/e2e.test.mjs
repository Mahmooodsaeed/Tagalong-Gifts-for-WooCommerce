// End-to-end tests on a real WordPress + WooCommerce site (started by tests/server.mjs).
//
// - "Block cart" tests talk to the WooCommerce Store API, which is what the Cart and Checkout
//   blocks use.
// - "Classic cart" tests drive the shortcode cart and checkout pages in a real browser.
// - "Admin" tests check the coupon screen.
//
//   npm install
//   npx playwright install chromium
//   npm test
//
// `npm run screenshots` also saves the README screenshots into screenshots/.
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { mkdirSync } from 'node:fs';
import { chromium, request as pwRequest } from 'playwright';
import { startServer, root } from './server.mjs';

let server, browser, ids, lastOrderId;
const SHOTS = process.env.SCREENSHOTS === '1' || process.env.npm_lifecycle_event === 'screenshots';
const shotDir = path.join(root, 'screenshots');

before(async () => {
  // BASE_URL: reuse a site that is already running (for example while developing).
  server = process.env.BASE_URL ? { url: process.env.BASE_URL, stop() {} } : await startServer();
  const api = await pwRequest.newContext({ baseURL: server.url });
  // HPOS=0 runs the tests with WooCommerce's legacy order storage instead of HPOS.
  const storage = process.env.HPOS === '0' ? 'posts' : 'hpos';
  const res = await api.post('/wp-json/tagalong-test/v1/setup', { data: { storage } });
  assert.equal(res.status(), 200, await res.text());
  ids = await res.json();
  console.log(`# Order storage: ${ids.storage}`);
  await api.dispose();
  // CHROMIUM_PATH: use an already-installed Chromium instead of Playwright's own download.
  browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
  if (SHOTS) mkdirSync(shotDir, { recursive: true });
});

after(async () => {
  await browser?.close();
  server?.stop();
});

// Playground runs PHP in WebAssembly with a single worker, so admin pages can take a while.
async function newPage(options) {
  const page = await browser.newPage(options);
  page.setDefaultTimeout(90_000);
  return page;
}

// ---------------------------------------------------------------------------------------------
// A shopper using the Store API, with their own cookies (cart session) and nonce.
// ---------------------------------------------------------------------------------------------
class Shopper {
  static async create() {
    const s = new Shopper();
    s.ctx = await pwRequest.newContext({ baseURL: server.url });
    await s.cart();
    return s;
  }
  async call(method, route, data) {
    const res = await this.ctx.fetch('/wp-json/wc/store/v1/' + route, {
      method,
      headers: this.nonce ? { Nonce: this.nonce } : {},
      data,
    });
    this.nonce = res.headers()['nonce'] || this.nonce;
    return { status: res.status(), body: await res.json() };
  }
  async cart() { return (await this.call('GET', 'cart')).body; }
  add(id, quantity = 1, variation) { return this.call('POST', 'cart/add-item', { id, quantity, variation }); }
  apply(code) { return this.call('POST', 'cart/apply-coupon', { code }); }
  unapply(code) { return this.call('POST', 'cart/remove-coupon', { code }); }
  remove(key) { return this.call('POST', 'cart/remove-item', { key }); }
  update(key, quantity) { return this.call('POST', 'cart/update-item', { key, quantity }); }
  dispose() { return this.ctx.dispose(); }
}

const gifts = (cart) => cart.items.filter((i) => i.item_data.some((d) => d.name === 'Free gift' || d.key === 'Free gift'));
const paid = (cart) => cart.items.filter((i) => !gifts(cart).includes(i));
const codes = (cart) => cart.coupons.map((c) => c.code);
const total = (cart) => Number(cart.totals.total_price) / 100;

async function order(id) {
  const api = await pwRequest.newContext({ baseURL: server.url });
  const res = await api.get(`/wp-json/tagalong-test/v1/order/${id}`);
  const body = await res.json();
  await api.dispose();
  return body;
}

// ---------------------------------------------------------------------------------------------
describe('Block cart (Store API)', () => {
  test('applying a gift coupon adds the gift for free, in any letter case', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    const r = await s.apply('FREEMUG');
    assert.equal(r.status, 200, JSON.stringify(r.body));
    const cart = r.body;
    assert.deepEqual(codes(cart), ['freemug']);
    const [gift] = gifts(cart);
    assert.ok(gift, 'gift line added');
    assert.equal(gift.id, ids.mug);
    assert.equal(gift.quantity, 1);
    assert.equal(gift.prices.price, '0');
    assert.equal(gift.totals.line_total, '0');
    assert.equal(total(cart), 20, 'customer only pays for the shirt');
    assert.equal(gift.quantity_limits.editable, false, 'quantity is locked');
    assert.equal(gift.quantity_limits.maximum, 1);
    await s.dispose();
  });

  test('the gift quantity cannot be raised', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    const [gift] = gifts((await s.apply('freemug')).body);
    await s.update(gift.key, 5);
    const cart = await s.cart();
    assert.equal(gifts(cart)[0].quantity, 1);
    assert.equal(total(cart), 20);
    await s.dispose();
  });

  test('removing the gift removes the coupon', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    const [gift] = gifts((await s.apply('freemug')).body);
    const r = await s.remove(gift.key);
    assert.equal(r.status, 200);
    assert.deepEqual(codes(r.body), []);
    assert.equal(gifts(r.body).length, 0);
    assert.equal(total(await s.cart()), 20);
    await s.dispose();
  });

  test('removing the coupon removes the gift', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    await s.apply('freemug');
    const r = await s.unapply('freemug');
    assert.equal(r.status, 200);
    assert.equal(gifts(r.body).length, 0);
    assert.equal(r.body.items.length, 1);
    await s.dispose();
  });

  test('a paid copy of the gift product stays paid and separate', async () => {
    const s = await Shopper.create();
    await s.add(ids.mug, 2);
    const cart = (await s.apply('freemug')).body;
    const mugs = cart.items.filter((i) => i.id === ids.mug);
    assert.equal(mugs.length, 2, 'two separate mug lines');
    assert.equal(paid(cart)[0].quantity, 2);
    assert.equal(paid(cart)[0].prices.price, '800');
    assert.equal(gifts(cart)[0].quantity, 1);
    assert.equal(total(cart), 16, '2 paid mugs, 1 free');
    await s.dispose();
  });

  test('a coupon whose gift is out of stock is refused with a clear message', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    const r = await s.apply('soldout');
    assert.equal(r.status, 400);
    assert.match(r.body.message, /out of stock/i);
    const cart = await s.cart();
    assert.deepEqual(codes(cart), []);
    assert.equal(cart.items.length, 1);
    await s.dispose();
  });

  test('a variation can be the gift', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    const cart = (await s.apply('hoodiegift')).body;
    const [gift] = gifts(cart);
    assert.equal(gift.id, ids.hoodieM);
    assert.deepEqual(gift.variation.map((v) => v.value), ['M']);
    assert.equal(total(cart), 20);
    await s.dispose();
  });

  test('gifts can also be configured in code with the tagalong_gift_coupon_map filter', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    const cart = (await s.apply('CodeGift')).body;
    assert.equal(gifts(cart)[0]?.id, ids.mug);
    await s.dispose();
  });

  test('WooCommerce coupon rules still apply: minimum spend', async () => {
    const s = await Shopper.create();
    const added = (await s.add(ids.shirt, 1)).body;
    const shirtKey = added.items[0].key;

    const refused = await s.apply('spend50');
    assert.equal(refused.status, 400, 'refused under $50');
    assert.equal(gifts(await s.cart()).length, 0);

    await s.update(shirtKey, 3);
    const ok = await s.apply('spend50');
    assert.equal(ok.status, 200, JSON.stringify(ok.body));
    assert.equal(gifts(ok.body).length, 1);

    // Drop below the minimum: WooCommerce removes the coupon, and the gift goes with it.
    await s.update(shirtKey, 1);
    const cart = await s.cart();
    assert.deepEqual(codes(cart), []);
    assert.equal(gifts(cart).length, 0);
    await s.dispose();
  });

  test('works alongside a normal discount coupon', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    await s.apply('tenoff');
    const cart = (await s.apply('freemug')).body;
    assert.deepEqual(codes(cart).sort(), ['freemug', 'tenoff']);
    assert.equal(total(cart), 10);
    await s.dispose();
  });

  test('no leftover "gift added" notice appears later on a classic page', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    await s.apply('freemug');
    // Hand the Store API session cookie to a browser and open a classic page.
    const state = await s.ctx.storageState();
    const ctx = await browser.newContext({ storageState: state });
    const page = await ctx.newPage();
    page.setDefaultTimeout(90_000);
    await page.goto(`${server.url}/classic-cart/`);
    await page.locator('tr.cart_item', { hasText: 'Coffee Mug' }).waitFor();
    assert.doesNotMatch(await page.locator('body').innerText(), /Your free gift has been added/);
    await ctx.close();
    await s.dispose();
  });

  test('block checkout: the order has the free gift line, its label and an order note', async () => {
    const s = await Shopper.create();
    await s.add(ids.shirt);
    await s.apply('freemug');
    const address = {
      first_name: 'Test', last_name: 'Customer', address_1: '1 Main St', city: 'San Francisco',
      state: 'CA', postcode: '94103', country: 'US', email: 'test@example.com', phone: '5555555555',
    };
    const r = await s.call('POST', 'checkout', { billing_address: address, shipping_address: address, payment_method: 'cheque' });
    assert.equal(r.status, 200, JSON.stringify(r.body));
    const o = await order(r.body.order_id);
    assert.equal(o.total, 20);
    const gift = o.items.find((i) => i.gift);
    assert.equal(gift.product_id, ids.mug);
    assert.equal(gift.total, 0);
    assert.equal(gift.gift, 'freemug');
    assert.ok(gift.meta.includes('Free gift: with coupon freemug'), JSON.stringify(gift.meta));
    assert.equal(o.notes.filter((n) => n.includes('Free gift "Coffee Mug"')).length, 1, JSON.stringify(o.notes));
    await s.dispose();
  });
});

// ---------------------------------------------------------------------------------------------
describe('Classic cart and checkout (browser)', () => {
  async function cartWithCoupon(page, code = 'FreeMug') {
    await page.goto(`${server.url}/?add-to-cart=${ids.shirt}`);
    await page.goto(`${server.url}/classic-cart/`);
    await page.fill('#coupon_code', code);
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('wc-ajax=apply_coupon')),
      page.click('button[name="apply_coupon"]'),
    ]);
    await page.reload();
    await page.waitForSelector('.cart-discount');
  }
  const giftRow = (page) => page.locator('tr.cart_item', { hasText: 'Coffee Mug' });

  test('the gift shows as Free with a fixed quantity, and the total excludes it', async () => {
    const page = await newPage({ viewport: { width: 1100, height: 900 } });
    await cartWithCoupon(page);
    const row = giftRow(page);
    await assert.doesNotReject(row.waitFor());
    assert.equal((await row.locator('td.product-price').innerText()).trim(), 'Free');
    assert.equal((await row.locator('td.product-subtotal').innerText()).trim(), 'Free');
    assert.equal(await row.locator('input.qty').count(), 0, 'no quantity box');
    assert.match(await row.innerText(), /Free gift:\s*with coupon freemug/);
    assert.match(await page.locator('.order-total').innerText(), /\$20\.00/);
    assert.match(await page.locator('.cart-discount').innerText(), /Free gift/, 'coupon row says Free gift, not -$0.00');
    if (SHOTS) await page.locator('.woocommerce').first().screenshot({ path: path.join(shotDir, 'classic-cart.png') });
    await page.close();
  });

  test('removing the gift removes the coupon; Undo brings both back', async () => {
    const page = await newPage();
    await cartWithCoupon(page);
    await giftRow(page).locator('a.remove').click();
    // Classic notices, or the block-style notices WooCommerce uses with block themes.
    const notices = page.locator('.woocommerce-message, .woocommerce-info, .wc-block-components-notice-banner');
    await notices.filter({ hasText: 'You removed the free gift' }).waitFor();
    assert.match(await notices.allInnerTexts().then((t) => t.join('\n')), /You removed the free gift, so coupon .freemug. was removed too/);
    assert.equal(await page.locator('.cart-discount').count(), 0, 'coupon removed');

    await page.locator('.restore-item').click();
    await page.waitForLoadState('load');
    await giftRow(page).waitFor();
    assert.equal(await page.locator('.cart-discount').count(), 1, 'coupon back');
    assert.equal(await giftRow(page).count(), 1, 'one gift, not two');
    await page.close();
  });

  test('removing the coupon from the totals removes the gift', async () => {
    const page = await newPage();
    await cartWithCoupon(page);
    await page.locator('.woocommerce-remove-coupon').click();
    await page.waitForSelector('.cart-discount', { state: 'detached' });
    await page.reload();
    assert.equal(await giftRow(page).count(), 0);
    await page.close();
  });

  test('classic checkout: order received page shows the gift and the order is correct', async () => {
    const page = await newPage({ viewport: { width: 1100, height: 1200 } });
    await cartWithCoupon(page);
    await page.goto(`${server.url}/classic-checkout/`);
    await page.fill('#billing_first_name', 'Test');
    await page.fill('#billing_last_name', 'Customer');
    await page.fill('#billing_address_1', '1 Main St');
    await page.fill('#billing_city', 'San Francisco');
    await page.fill('#billing_postcode', '94103');
    await page.fill('#billing_phone', '5555555555');
    await page.fill('#billing_email', 'classic@example.com');
    await page.waitForSelector('.blockOverlay', { state: 'detached' });
    await page.click('#place_order');
    await page.waitForURL(/order-received/);
    // Classic template (.woocommerce-order-details) or the block Order Confirmation template.
    const details = await page.locator('.woocommerce-order-details, .wc-block-order-confirmation-totals').first().innerText();
    assert.match(details, /Coffee Mug/);
    assert.match(details, /Free gift:\s*with coupon freemug/);
    const id = Number(new URL(page.url()).pathname.match(/order-received\/(\d+)/)[1]);
    lastOrderId = id;
    const o = await order(id);
    assert.equal(o.total, 20);
    assert.equal(o.notes.filter((n) => n.includes('Free gift "Coffee Mug"')).length, 1, JSON.stringify(o.notes));
    await page.close();
  });

  test('block cart page renders the gift (screenshot only)', { skip: !SHOTS }, async () => {
    const page = await newPage({ viewport: { width: 1100, height: 900 } });
    await page.goto(`${server.url}/?add-to-cart=${ids.shirt}`);
    await page.goto(`${server.url}/classic-cart/`);
    await page.fill('#coupon_code', 'FreeMug');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('wc-ajax=apply_coupon')),
      page.click('button[name="apply_coupon"]'),
    ]);
    await page.goto(`${server.url}/cart/`);
    await page.locator('.wc-block-cart-items__row', { hasText: 'Coffee Mug' }).waitFor();
    await page.waitForSelector('.wc-block-components-skeleton, .wc-block-components-skeleton__element', { state: 'detached' }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.locator('.wp-block-woocommerce-cart').screenshot({ path: path.join(shotDir, 'block-cart.png') });
    await page.close();
  });
});

// ---------------------------------------------------------------------------------------------
describe('Admin', () => {
  let page;
  before(async () => {
    page = await newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto(`${server.url}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
  });
  after(() => page?.close());

  const editCoupon = (id) => page.goto(`${server.url}/wp-admin/post.php?post=${id}&action=edit`);

  test('the coupon screen shows the chosen gift', async () => {
    await editCoupon(ids.coupons.freemug);
    const field = page.locator('#tagalong_gift_product_id');
    await field.waitFor({ state: 'attached' });
    assert.equal(await field.inputValue(), String(ids.mug));
    assert.match(await page.locator('.tagalong_gift_product_id_field, p.form-field:has(#tagalong_gift_product_id)').innerText(), /Coffee Mug/);
    if (SHOTS) {
      await page.locator('#woocommerce-coupon-data').screenshot({ path: path.join(shotDir, 'coupon-settings.png') });
    }
  });

  test('picking a gift through the product search saves it', async () => {
    await editCoupon(ids.coupons.codegift);
    await page.locator('p.form-field:has(#tagalong_gift_product_id) .select2-selection').click();
    await page.keyboard.type('Classic');
    await page.locator('.select2-results__option', { hasText: 'Classic T-Shirt' }).click();
    await page.click('#publish');
    await page.waitForSelector('#message');
    assert.equal(await page.locator('#tagalong_gift_product_id').inputValue(), String(ids.shirt));
    // Clear it again so the code-map test data stays as it was.
    await page.locator('p.form-field:has(#tagalong_gift_product_id) .select2-selection__clear').click();
    await page.keyboard.press('Escape');
    await page.click('#publish');
    await page.waitForSelector('#message');
    assert.equal(await page.locator('#tagalong_gift_product_id').inputValue(), '');
  });

  test('a variable product (which needs options chosen) is refused', async () => {
    await editCoupon(ids.coupons.soldout);
    // The search already hides variable products, so post one directly.
    await page.evaluate((id) => {
      const s = document.querySelector('#tagalong_gift_product_id');
      s.add(new Option('Hoodie', id, true, true));
    }, String(ids.hoodie));
    await page.click('#publish');
    await page.waitForLoadState('load');
    assert.match(await page.locator('#woocommerce_errors').innerText(), /free gift was not saved/i);
    assert.equal(await page.locator('#tagalong_gift_product_id').inputValue(), String(ids.cap), 'previous gift kept');
  });

  test('order screen shows a readable gift line, not the raw marker', async () => {
    assert.ok(lastOrderId, 'needs the order from the classic checkout test');
    await page.goto((await order(lastOrderId)).edit_url);
    const items = page.locator('#woocommerce-order-items');
    await items.waitFor();
    const text = await items.innerText();
    assert.match(text, /Free gift:\s*with coupon freemug/);
    assert.doesNotMatch(text, /_tagalong_gift_coupon/);
    if (SHOTS) await items.screenshot({ path: path.join(shotDir, 'order-items.png') });
  });
});

// ---------------------------------------------------------------------------------------------
describe('PHP log', () => {
  test('the plugin caused no PHP errors, warnings, notices or deprecations', async () => {
    const api = await pwRequest.newContext({ baseURL: server.url });
    const lines = await (await api.get('/wp-json/tagalong-test/v1/log')).json();
    await api.dispose();
    assert.deepEqual(lines, []);
  });
});
