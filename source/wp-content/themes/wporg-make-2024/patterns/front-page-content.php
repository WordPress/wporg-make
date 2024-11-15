<?php
/**
 * Title: Front Page Content
 * Slug: wporg-make-2024/front-page-content
 * Inserter: no
 */

?>

<!-- wp:heading {"style":{"spacing":{"margin":{"top":"0","bottom":"var:preset|spacing|30"}}}} -->
<h2 class="wp-block-heading" style="margin-top:0;margin-bottom:var(--wp--preset--spacing--30)"><?php esc_html_e( 'Teams', 'make-wporg' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:query {"queryId":0,"query":{"perPage":100,"postType":"make_site","order":"asc","orderBy":"date"},"align":"wide","className":"wporg-make-team-list"} -->
<div class="wp-block-query alignwide wporg-make-team-list">
	
	<!-- wp:post-template {"style":{"spacing":{"blockGap":"0"}},"fontSize":"extra-small","layout":{"type":"default"}} -->

		<!-- wp:template-part {"slug":"card-team","className":"has-display-contents"} /-->

	<!-- /wp:post-template -->

	<!-- wp:query-no-results -->

		<!-- wp:pattern {"slug":"wporg-make-2024/query-no-sites"} /-->

	<!-- /wp:query-no-results -->

</div>
<!-- /wp:query -->

<!-- wp:heading {"style":{"typography":{"fontStyle":"normal","fontWeight":"400"},"spacing":{"margin":{"top":"var:preset|spacing|50"}}},"fontSize":"heading-1","fontFamily":"eb-garamond"} -->
<h2 class="wp-block-heading has-eb-garamond-font-family has-heading-1-font-size" style="margin-top:var(--wp--preset--spacing--50);font-style:normal;font-weight:400"><?php esc_html_e( 'Not sure which team to contribute to?', 'make-wporg' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>
<?php echo wp_kses_post(
	sprintf(
		/* translators: 1: Contributor wizard link */
		__( 'Check out our <a href="%s">contributor wizard</a> and find how to get started.', 'make-wporg' ),
		'https://make.wordpress.org/contribute/',
	)
); ?>
</p>
<!-- /wp:paragraph -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|edge-space","bottom":"var:preset|spacing|50","left":"var:preset|spacing|edge-space"},"margin":{"top":"var:preset|spacing|50"}},"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"backgroundColor":"blueberry-1","textColor":"white","layout":{"inherit":true,"type":"constrained"}} -->
<div class="wp-block-group alignfull has-white-color has-blueberry-1-background-color has-text-color has-background has-link-color" style="margin-top:var(--wp--preset--spacing--50);padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--edge-space);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--edge-space)"><!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|edge-space"}}}} -->
<div class="wp-block-columns alignwide"><!-- wp:column {"verticalAlignment":"center","width":"55%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:55%"><!-- wp:heading {"style":{"typography":{"fontStyle":"normal","fontWeight":"400"},"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"textColor":"white","fontSize":"heading-1","fontFamily":"eb-garamond"} -->
<h2 class="wp-block-heading has-white-color has-text-color has-link-color has-eb-garamond-font-family has-heading-1-font-size" style="font-style:normal;font-weight:400"><?php esc_html_e( 'Shape the future of WordPress', 'make-wporg' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"is-style-short-text"} -->
<p class="is-style-short-text"><?php esc_html_e( 'Become a Five for the Future participant.', 'make-wporg' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:list {"className":"is-style-links-list"} -->
<ul class="wp-block-list is-style-links-list"><!-- wp:list-item {"fontSize":"large"} -->
<li class="has-large-font-size"><a href="https://wordpress.org/five-for-the-future/for-organizations/" data-type="page" data-id="9"><?php esc_html_e( 'Contribute as an organization', 'make-wporg' ); ?></a></li>
<!-- /wp:list-item -->

<!-- wp:list-item {"fontSize":"large"} -->
<li class="has-large-font-size"><a href="https://wordpress.org/five-for-the-future/for-individuals/" data-type="page" data-id="8"><?php esc_html_e( 'Contribute as an individual', 'make-wporg' ); ?></a></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
