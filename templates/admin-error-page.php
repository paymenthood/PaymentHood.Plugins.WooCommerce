<?php
/**
 * Template: Admin Error Page
 *
 * Variables provided by paymenthood_render_admin_error_page():
 *   $css_url    (string) URL to paymenthood-admin.css
 *   $error_json (string) JSON-encoded error details
 */
defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title><?php esc_html_e( 'PaymentHood Error', 'paymenthood' ); ?></title>
	<?php wp_admin_css( 'install', true ); ?>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
	<div class="paymenthood-error">
		<h1><?php esc_html_e( 'Something went wrong', 'paymenthood' ); ?></h1>
		<p><?php esc_html_e( 'Please contact the store administrator.', 'paymenthood' ); ?></p>

		<h3><?php esc_html_e( 'Error details', 'paymenthood' ); ?></h3>
		<pre><?php echo esc_html( $error_json ); ?></pre>
	</div>
</body>
</html>
