<?php
/**
 * Title: Front Page Header
 * Slug: wporg-make-2024/front-page-header
 * Inserter: no
 */

?>

<!-- wp:wporg/global-header {"style":{"border":{"bottom":{"color":"var:preset|color|white-opacity-15","style":"solid","width":"1px"}}}} /-->

<!-- wp:wporg/local-navigation-bar {"backgroundColor":"charcoal-2","style":{"elements":{"link":{"color":{"text":"var:preset|color|white"},":hover":{"color":{"text":"var:preset|color|white"}}}}},"textColor":"white","fontSize":"small"} -->

	<!-- wp:site-title {"level":0,"fontSize":"small","className":"wporg-local-navigation-bar__show-on-scroll"} /-->

	<!-- wp:navigation {"icon":"menu","overlayBackgroundColor":"charcoal-1","overlayTextColor":"white","layout":{"type":"flex","orientation":"horizontal"},"fontSize":"small","menuSlug":"make"} /-->

<!-- /wp:wporg/local-navigation-bar -->

<!-- wp:group {"align":"full","className":"has-white-color has-charcoal-2-background-color has-text-color has-background has-link-color","style":{"spacing":{"padding":{"right":"var:preset|spacing|edge-space","left":"var:preset|spacing|edge-space"}}},"backgroundColor":"charcoal-2","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-white-color has-charcoal-2-background-color has-text-color has-background has-link-color" style="padding-right:var(--wp--preset--spacing--edge-space);padding-left:var(--wp--preset--spacing--edge-space)">
	
	<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"bottom"}} -->
	<div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--30)">
		
		<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"50px"}}} -->
		<h1 class="wp-block-heading" style="font-size:50px"><?php esc_html_e( 'Make WordPress', 'make-wporg' ); ?></h1>
		<!-- /wp:heading -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"wporg-make-front-page-intro","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"stretch"}} -->
	<div class="wp-block-group wporg-make-front-page-intro">

		<!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|edge-space","top":"0","bottom":"var:preset|spacing|30"}},"layout":{"selfStretch":"fixed","flexSize":"50%"},"dimensions":{"minHeight":"100%"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"center"}} -->
		<div class="wp-block-group" style="min-height:100%;padding-top:0;padding-right:var(--wp--preset--spacing--edge-space);padding-bottom:var(--wp--preset--spacing--30)">

			<!-- wp:paragraph {"style":{"typography":{"lineHeight":"1.7"}},"fontSize":"large"} -->
			<p class="has-large-font-size" style="line-height:1.7"><?php esc_html_e( 'Whether you’re a budding developer, a designer, or just like helping out, we’re always looking for people to help make WordPress even better.', 'make-wporg' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'If you want to get involved in WordPress, this is the place to be. We’ve got blogs for each contributor group, general news, and upcoming events.', 'make-wporg' ); ?></p>
			<!-- /wp:paragraph -->

		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"layout":{"selfStretch":"fixed","flexSize":"50%"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"bottom"}} -->
		<div class="wp-block-group">
			
			<!-- wp:image -->
			<figure class="wp-block-image"><img src="<?php echo esc_url( get_stylesheet_directory_uri() ); ?>/assets/community.webp" alt="<?php esc_html_e( 'Community members', 'make-wporg' ); ?>"/></figure>
			<!-- /wp:image -->

		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->
