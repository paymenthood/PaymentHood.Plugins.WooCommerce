=== PaymentHood ===
Contributors: lukahood
Tags: payment, woocommerce, payment gateway, checkout, paymenthood
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments via PaymentHood — a flexible, multi-provider payment gateway for WooCommerce.

== Description ==

PaymentHood is a multi-provider payment gateway for WooCommerce that lets merchants accept payments through a wide range of payment providers from a single integration.

= Features =

* Multi-provider checkout — offer customers multiple payment methods in a single hosted page.
* Automatic and manual refund support — full refunds processed through the provider or flagged for manual handling.
* HPOS (High-Performance Order Storage) compatible.
* WooCommerce Blocks / Cart & Checkout blocks compatible.
* Webhook-based real-time order status synchronisation.
* Sandbox (test) mode with a separate set of credentials.
* Detailed fee breakdown stored per order (provider fee, app fee, net amount).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/paymenthood` directory, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **WooCommerce > Settings > Payments** and enable **PaymentHood**.
4. Enter your PaymentHood License ID and Authorization Code, then save. The plugin will automatically retrieve your API credentials.
5. Configure sandbox or live mode and complete the webhook setup.

== Frequently Asked Questions ==

= Does PaymentHood support partial refunds? =

No. PaymentHood currently supports full-order refunds only. Attempting a partial refund will return an error with the full order amount required.

= What happens when a provider does not support automatic refunds? =

The order is marked as refunded inside PaymentHood, and a prominent warning is displayed in the WooCommerce admin. You must manually transfer the refund amount to the customer.

= Is sandbox/test mode available? =

Yes. Enable sandbox mode in the gateway settings and provide your sandbox License ID and Authorization Code to test without processing real payments.

= Is this plugin compatible with WooCommerce HPOS? =

Yes. The plugin declares full compatibility with WooCommerce High-Performance Order Storage (custom order tables).

== Third-Party Services ==

This plugin connects to the PaymentHood payment platform (https://www.paymenthood.com) to process payments, retrieve payment provider profiles, and handle refunds. The following data is transmitted to PaymentHood's servers:

* License ID and Authorization Code — used during initial setup to obtain API credentials.
* Order amount, currency, and order ID — sent when a payment session is created.
* Refund amount and order ID — sent when a refund is requested.
* Provider icon images — fetched from PaymentHood's CDN and proxied through your server for display in the checkout and admin settings.

No customer personal data (names, addresses, card numbers) is transmitted directly by this plugin; customers are redirected to PaymentHood's hosted checkout page where payment details are entered and processed entirely by PaymentHood.

By using this plugin you agree to PaymentHood's Terms of Service and Privacy Policy:

* Terms of Service: https://www.paymenthood.com/terms
* Privacy Policy: https://www.paymenthood.com/privacy

== Screenshots ==

1. PaymentHood payment settings page.
2. Multi-provider checkout hosted page.
3. Admin order detail with PaymentHood payment ID and refund notices.

== Changelog ==

= 1.0.0 =
* Initial release of the PaymentHood Payment Gateway for WooCommerce.
* Multi-provider hosted checkout page integration.
* Automatic and manual refund support with admin notices.
* Webhook-based real-time order status synchronisation.
* Sandbox (test) mode with separate credentials.
* HPOS (High-Performance Order Storage) compatibility.
* WooCommerce Blocks (Cart and Checkout) compatibility.
* Per-order fee breakdown (provider fee, app fee, net amount).

For the full version history, see changelog.txt.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
