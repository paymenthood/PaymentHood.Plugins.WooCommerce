<?php
/**
 * Template: Order Fee Rows
 * Rendered inside the WooCommerce admin order totals table.
 *
 * Variables provided by woocommerce_admin_order_totals_after_total hook:
 *   $provider_fee_html (string) Formatted provider fee price HTML
 *   $app_fee_html      (string) Formatted app fee price HTML
 */
defined( 'ABSPATH' ) || exit;
?>
<tr class="fee paymenthood-fee">
	<td class="label">Provider Fee:</td>
	<td class="total"><?php echo $provider_fee_html; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped by wc_price() ?></td>
</tr>
<tr class="fee paymenthood-fee">
	<td class="label">App Fee:</td>
	<td class="total"><?php echo $app_fee_html; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped by wc_price() ?></td>
</tr>
