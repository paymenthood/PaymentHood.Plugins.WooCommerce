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
	<title>PaymentHood Error</title>
	<?php wp_admin_css( 'install', true ); ?>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
	<div class="paymenthood-error">
		<h1>Something went wrong</h1>
		<p>Please call plugin admin.</p>

		<h3>Error details</h3>
		<pre><?php echo esc_html( $error_json ); ?></pre>
	</div>
</body>
</html>
