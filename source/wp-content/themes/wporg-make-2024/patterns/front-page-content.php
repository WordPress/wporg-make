<?php
/**
 * Title: Front Page Content
 * Slug: wporg-make-2024/front-page-content
 * Inserter: no
 */

?>

<!-- wp:group {"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained","justifyContent":"left","contentSize":"750px"}} -->
<div class="wp-block-group" style="margin-bottom:var(--wp--preset--spacing--50)">

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Whether you’re a budding developer, a designer, or just like helping out, we’re always looking for people to help make WordPress even better.', 'make-wporg' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"style":{"spacing":{"margin":{"top":"0","bottom":"var:preset|spacing|10"}}}} -->
	<h2 class="wp-block-heading" style="margin-top:0;margin-bottom:var(--wp--preset--spacing--10)"><?php esc_html_e( 'Teams', 'make-wporg' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'If you want to get involved in WordPress, this is the place to start. We’ve got blogs for each contributor group, general news, and upcoming events.', 'make-wporg' ); ?></p>
	<!-- /wp:paragraph -->

</div>
<!-- /wp:group -->

<!-- wp:query {"queryId":0,"query":{"perPage":1000,"postType":"make_site"},"align":"wide","className":"wporg-learn-site-grid"} -->
<div class="wp-block-query alignwide wporg-make-site-grid">

	<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"grid","columnCount":null,"minimumColumnWidth":"330px"}} -->

		<!-- wp:post-title {"isLink":true} /-->

	<!-- /wp:post-template -->

	<!-- wp:query-no-results -->

		<!-- wp:pattern {"slug":"wporg-make-2024/query-no-sites"} /-->

	<!-- /wp:query-no-results -->

</div>
<!-- /wp:query -->
