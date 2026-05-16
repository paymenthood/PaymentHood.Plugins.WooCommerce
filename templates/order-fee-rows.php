<?php
/**
 * Template: Order Fee Rows
 * Rendered inside the WooCommerce admin order totals table.
 *
 * Variables provided by woocommerce_admin_order_totals_after_total hook:
 *   $fee_html        (string) Formatted combined fee price HTML
 *   $net_amount_html (string) Formatted net amount price HTML (may be empty)
 */
defined( 'ABSPATH' ) || exit;
?>
<tr class="fee paymenthood-fee">
	<td class="label">PaymentHood Fee:</td>
	<td class="total"><?php echo $fee_html; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped by wc_price() ?></td>
</tr>
<?php if ( $net_amount_html ) : ?>
<tr class="total paymenthood-fee">
	<td class="label"><strong>Net Amount:</strong></td>
	<td class="total"><strong><?php echo $net_amount_html; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped by wc_price() ?></strong></td>
</tr>
<?php endif; ?>
