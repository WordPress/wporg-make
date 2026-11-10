<?php
/**
 * Title: Front Page Content
 * Slug: wporg-make-2024/front-page-content
 * Inserter: no
 */

?>

<!-- wp:columns {"align":"wide","className":"wporg-make-front-columns","style":{"spacing":{"blockGap":{"left":"0"}}}} -->
<div class="wp-block-columns alignwide wporg-make-front-columns">

	<!-- wp:column -->
	<div class="wp-block-column">

		<!-- wp:heading {"style":{"spacing":{"margin":{"top":"0","bottom":"var:preset|spacing|20"}}},"fontSize":"heading-5"} -->
		<h2 class="wp-block-heading has-heading-5-font-size" style="margin-top:0;margin-bottom:var(--wp--preset--spacing--20)"><?php esc_html_e( 'Teams', 'wporg' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:query {"queryId":0,"query":{"perPage":100,"postType":"make_site","order":"asc","orderBy":"date"},"className":"wporg-make-team-list wporg-make-team-grid"} -->
		<div class="wp-block-query wporg-make-team-list wporg-make-team-grid">

			<!-- wp:post-template {"layout":{"type":"default"}} -->

				<!-- wp:template-part {"slug":"card-team-grid","className":"has-display-contents"} /-->

			<!-- /wp:post-template -->

			<!-- wp:query-no-results -->

				<!-- wp:pattern {"slug":"wporg-make-2024/query-no-sites"} /-->

			<!-- /wp:query-no-results -->

		</div>
		<!-- /wp:query -->

		<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"var:preset|spacing|20"}}},"fontSize":"small"} -->
		<p class="has-small-font-size" style="margin-top:var(--wp--preset--spacing--20)"><a href="<?php echo esc_url( home_url( '/teams/' ) ); ?>"><?php esc_html_e( 'All teams and their meetings', 'wporg' ); ?></a></p>
		<!-- /wp:paragraph -->

		<!-- wp:group {"className":"wporg-make-latest-activity-intro","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"},"padding":{"top":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|10"},"border":{"top":{"color":"var:preset|color|light-grey-1","width":"1px"}}},"layout":{"type":"default"}} -->
		<div class="wp-block-group wporg-make-latest-activity-intro" style="border-top-color:var(--wp--preset--color--light-grey-1);border-top-width:1px;margin-top:var(--wp--preset--spacing--50);padding-top:var(--wp--preset--spacing--40)">

			<!-- wp:heading {"style":{"spacing":{"margin":{"top":"0","bottom":"0"}}},"fontSize":"heading-5"} -->
			<h2 class="wp-block-heading has-heading-5-font-size" style="margin-top:0;margin-bottom:0"><?php esc_html_e( 'Latest activity', 'wporg' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"charcoal-4","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|30"}}}} -->
			<p class="has-charcoal-4-color has-text-color" style="margin-bottom:var(--wp--preset--spacing--30)"><?php esc_html_e( 'See the latest happenings across the entire project.', 'wporg' ); ?></p>
			<!-- /wp:paragraph -->

		</div>
		<!-- /wp:group -->

		<!-- wp:wporg-make/latest-activity /-->

	</div>
	<!-- /wp:column -->

	<!-- wp:column {"className":"wporg-make-front-sidebar"} -->
	<div class="wp-block-column wporg-make-front-sidebar">

		<!-- wp:group {"className":"wporg-make-front-callout","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|10"},"border":{"color":"var:preset|color|light-grey-1","width":"1px","radius":"2px"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group wporg-make-front-callout has-border-color" style="border-color:var(--wp--preset--color--light-grey-1);border-width:1px;border-radius:2px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">

			<!-- wp:heading {"style":{"typography":{"fontStyle":"normal","fontWeight":"400"},"spacing":{"margin":{"top":"0"}}},"fontSize":"heading-4","fontFamily":"eb-garamond"} -->
			<h2 class="wp-block-heading has-eb-garamond-font-family has-heading-4-font-size" style="margin-top:0;font-style:normal;font-weight:400"><?php esc_html_e( 'Not sure which team to contribute to?', 'wporg' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: 1: Contributor wizard link */
					__( 'Check out our <a href="%s">contributor wizard</a> and find how to get started.', 'wporg' ),
					'https://make.wordpress.org/contribute/',
				)
			);
			?>
			</p>
			<!-- /wp:paragraph -->

		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"wporg-make-front-callout","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30"},"margin":{"top":"var:preset|spacing|20"},"blockGap":"var:preset|spacing|10"},"border":{"radius":"2px"},"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"backgroundColor":"blueberry-1","textColor":"white","layout":{"type":"default"}} -->
		<div class="wp-block-group wporg-make-front-callout has-white-color has-blueberry-1-background-color has-text-color has-background has-link-color" style="border-radius:2px;margin-top:var(--wp--preset--spacing--20);padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">

			<!-- wp:heading {"style":{"typography":{"fontStyle":"normal","fontWeight":"400"},"spacing":{"margin":{"top":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"textColor":"white","fontSize":"heading-4","fontFamily":"eb-garamond"} -->
			<h2 class="wp-block-heading has-white-color has-text-color has-link-color has-eb-garamond-font-family has-heading-4-font-size" style="margin-top:0;font-style:normal;font-weight:400"><?php esc_html_e( 'Shape the future of WordPress', 'wporg' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'Become a Five for the Future participant.', 'wporg' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:list {"className":"is-style-links-list","fontSize":"small"} -->
			<ul class="wp-block-list is-style-links-list has-small-font-size"><!-- wp:list-item -->
			<li><a href="https://wordpress.org/five-for-the-future/for-organizations/"><?php esc_html_e( 'Contribute as an organization', 'wporg' ); ?></a></li>
			<!-- /wp:list-item -->

			<!-- wp:list-item -->
			<li><a href="https://wordpress.org/five-for-the-future/for-individuals/"><?php esc_html_e( 'Contribute as an individual', 'wporg' ); ?></a></li>
			<!-- /wp:list-item --></ul>
			<!-- /wp:list -->

		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:column -->

</div>
<!-- /wp:columns -->
