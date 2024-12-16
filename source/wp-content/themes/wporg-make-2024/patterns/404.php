<?php
/**
 * Title: 404
 * Slug: wporg-make-2024/404
 * Inserter: no
 */

?>

<!-- wp:group {"style":{"border":{"top":{"width":"1px","color":"var:preset|color|charcoal-1"},"right":{"width":"0px","style":"none"},"bottom":{"width":"0px","style":"none"},"left":{"width":"0px","style":"none"}},"spacing":{"padding":{"left":"var:preset|spacing|60","right":"var:preset|spacing|60"},"margin":{"top":"0"}}},"backgroundColor":"charcoal-2","textColor":"white","className":"site-content-container"} -->
<main class="wp-block-group site-content-container has-white-color has-charcoal-2-background-color has-text-color has-background" style="border-top-color:var(--wp--preset--color--charcoal-1);border-top-width:1px;border-right-style:none;border-right-width:0px;border-bottom-style:none;border-bottom-width:0px;border-left-style:none;border-left-width:0px;margin-top:0;padding-right:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--60)"><!-- wp:group {"textColor":"charcoal-1","className":"wporg-parent-oops-container","layout":{"type":"constrained"}} -->
<div class="wp-block-group wporg-parent-oops-container has-charcoal-1-color has-text-color"><!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color">Change the text color of the group here to adjust "Oops" color.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:heading {"level":1} -->
<h1><?php esc_html_e( 'This page doesn&#146;t exist.', 'make-wporg' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>
	<?php
	printf(
		/* translators: %s is the site URL. */
		wp_kses_post( __( 'Go to <a href="%s">the homepage</a>.', 'make-wporg' ) ),
		esc_url( get_site_url( null, '/' ) )
	);
	?>
</p>
<!-- /wp:paragraph -->

</main>
<!-- /wp:group -->
