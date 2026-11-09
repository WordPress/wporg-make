<?php
/**
 * Title: Team list
 * Slug: wporg-make-2024/team-list
 * Inserter: no
 */

?>

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
