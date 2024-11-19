<?php
/**
 * Title: Search Content
 * Slug: wporg-make-2024/search-content
 * Inserter: no
 */

?>

<!-- wp:query {"queryId":0,"query":{"inherit":true,"perPage":25},"align":"wide"} -->
<div class="wp-block-query alignwide">

	<!-- wp:group {"className":"align-left","layout":{"type":"constrained","contentSize":"","justifyContent":"left"},"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|40"}}}} -->
	<div class="wp-block-group align-left" style="margin-bottom:var(--wp--preset--spacing--40)">

		<!-- wp:pattern {"slug":"wporg-make-2024/search-field"} /-->

		<!-- wp:wporg/search-results-context {"style":{"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}},"elements":{"link":{"color":{"text":"var:preset|color|charcoal-4"}}}},"textColor":"charcoal-4","fontSize":"small"} /-->

		<!-- wp:post-template {"align":"wide"} -->

			<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|20","right":"var:preset|spacing|20"}},"border":{"width":"1px","color":"#d9d9d9","radius":"2px"}}} -->
			<div class="wp-block-group has-border-color" style="border-color:#d9d9d9;border-width:1px;border-radius:2px;padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--20);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--20)">

				<!-- wp:post-title {"isLink":true,"style":{"typography":{"lineHeight":"1.7","fontStyle":"normal","fontWeight":"600"},"elements":{"link":{"color":{"text":"var:preset|color|blueberry-1"}}},"spacing":{"margin":{"bottom":"var:preset|spacing|10"}}},"textColor":"blueberry-1","fontSize":"small","fontFamily":"inter"} /-->

				<!-- wp:post-excerpt {"showMoreOnNewLine":false,"excerptLength":40,"style":{"spacing":{"margin":{"top":"var:preset|spacing|10"}}},"fontSize":"small"} /-->

			</div>
			<!-- /wp:group -->

		<!-- /wp:post-template -->

		<!-- wp:query-no-results -->
		
			<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"var:preset|spacing|40"}}}} -->
			<p style="margin-top:var(--wp--preset--spacing--40)"><?php esc_attr_e( 'Sorry, but nothing matched your search terms.', 'make-wporg' ); ?></p>
			<!-- /wp:paragraph -->

		<!-- /wp:query-no-results -->
	
	</div>
	<!-- /wp:group -->

	<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
		<!-- wp:query-pagination-previous {"label":"Previous"} /-->

		<!-- wp:query-pagination-numbers /-->

		<!-- wp:query-pagination-next {"label":"Next"} /-->
	<!-- /wp:query-pagination -->

</div>
<!-- /wp:query -->
