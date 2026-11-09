<?php
/**
 * Block: Latest activity.
 *
 * Shows the newest posts and comments from the Make team blogs, with Newer
 * and Older links over up to MAX_PAGES pages.
 *
 * @package WordPressdotorg\Theme\Make_2024
 */

declare( strict_types = 1 );

namespace WordPressdotorg\Theme\Make_2024\Latest_Activity;

require_once __DIR__ . '/query.php';

/**
 * Query string parameter holding the page number.
 *
 * Deliberately not a registered query var: on a static front page, one makes
 * WordPress treat the request as the blog index.
 *
 * @var string
 */
const QUERY_ARG = 'activity-page';

/**
 * ID of the block's wrapper, which pagination links point to.
 *
 * @var string
 */
const ANCHOR = 'latest-activity';

add_action( 'init', __NAMESPACE__ . '\init' );
add_action( 'template_redirect', __NAMESPACE__ . '\maybe_redirect' );
add_filter( 'wporg_noindex_request', __NAMESPACE__ . '\noindex_paged_views' );

/**
 * Registers the block from its built metadata.
 */
function init(): void {
	register_block_type(
		dirname( __DIR__, 2 ) . '/build/latest-activity',
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}

/**
 * Returns the requested page number.
 *
 * @return int The page, 1 when none is requested, or 0 when the parameter is not a page that can be served.
 */
function get_requested_page(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging parameter.
	if ( ! isset( $_GET[ QUERY_ARG ] ) ) {
		return 1;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only, and only a canonical integer is accepted.
	$raw = wp_unslash( $_GET[ QUERY_ARG ] );
	if ( ! is_string( $raw ) ) {
		return 0;
	}

	// Anything that isn't the integer written plainly ("02", "2abc", " 2") would serve a duplicate page.
	$page = (int) $raw;
	if ( (string) $page !== $raw ) {
		return 0;
	}

	// Page 1 is served without the parameter, so `?activity-page=1` is a duplicate.
	return $page >= 2 && $page <= MAX_PAGES ? $page : 0;
}

/**
 * Returns where a request for an invalid or empty page should go.
 *
 * Redirecting rather than clamping keeps duplicate pages out of the page cache.
 * A page number that can never be served redirects permanently. A valid page
 * that is empty right now redirects temporarily, and a failed query does not
 * redirect at all, so browsers don't keep a redirect after it recovers.
 *
 * @return array{url: string, status: int}|null The URL without the page parameter and the status to send, or null when no redirect is needed.
 */
function get_redirect_url(): ?array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging parameter.
	if ( ! is_front_page() || ! isset( $_GET[ QUERY_ARG ] ) ) {
		return null;
	}

	$page   = get_requested_page();
	$status = 301;

	if ( $page ) {
		$result = get_activity_page( $page );
		if ( $result['failed'] || $result['rows'] ) {
			return null;
		}

		$status = 302;
	}

	return array(
		'url'    => remove_query_arg( QUERY_ARG ),
		'status' => $status,
	);
}

/**
 * Redirects requests for pages that cannot be served.
 */
function maybe_redirect(): void {
	$redirect = get_redirect_url();

	if ( null !== $redirect ) {
		wp_safe_redirect( $redirect['url'], $redirect['status'] );
		exit;
	}
}

/**
 * Marks paged views of the front page as noindex.
 *
 * @param mixed $noindex Whether to noindex the request, or a robots value.
 * @return mixed The filtered value.
 */
function noindex_paged_views( mixed $noindex ): mixed {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging parameter.
	if ( is_front_page() && isset( $_GET[ QUERY_ARG ] ) ) {
		return true;
	}

	return $noindex;
}

/**
 * Returns the URL of a page of the block.
 *
 * @param int $page Page number.
 * @return string URL.
 */
function get_page_url( int $page ): string {
	$url = home_url( '/' );

	if ( $page > 1 ) {
		$url = add_query_arg( QUERY_ARG, $page, $url );
	}

	return $url . '#' . ANCHOR;
}

/**
 * Renders the block.
 *
 * The block has no attributes or content, so the arguments WordPress passes are not declared.
 *
 * @return string Block markup.
 */
function render(): string {
	$page   = max( 1, get_requested_page() );
	$result = get_activity_page( $page );
	$items  = get_items( $result['rows'], get_allowed_sites() );

	$wrapper_attributes = get_block_wrapper_attributes( array( 'id' => ANCHOR ) );

	if ( ! $items ) {
		return sprintf(
			'<div %1$s><p>%2$s</p></div>',
			$wrapper_attributes,
			esc_html__( 'No recent activity.', 'wporg' )
		);
	}

	cache_users( array_unique( array_column( $items, 'user_id' ) ) );

	$list = '';
	foreach ( $items as $item ) {
		$list .= render_item( $item );
	}

	$links = '';
	if ( $page > 1 ) {
		$links .= sprintf(
			'<a class="wp-block-wporg-make-latest-activity__newer" href="%1$s">%2$s</a>',
			esc_url( get_page_url( $page - 1 ) ),
			esc_html__( '← Newer', 'wporg' )
		);
	}
	if ( $result['has_older'] ) {
		$links .= sprintf(
			'<a class="wp-block-wporg-make-latest-activity__older" href="%1$s">%2$s</a>',
			esc_url( get_page_url( $page + 1 ) ),
			esc_html__( 'Older →', 'wporg' )
		);
	}

	$navigation = '';
	if ( $links ) {
		$navigation = sprintf(
			'<nav class="wp-block-wporg-make-latest-activity__pagination" aria-label="%1$s">%2$s</nav>',
			esc_attr__( 'Latest activity pages', 'wporg' ),
			$links
		);
	}

	$end_note = '';
	if ( MAX_PAGES === $page ) {
		$end_note = sprintf(
			'<p class="wp-block-wporg-make-latest-activity__end">%s</p>',
			esc_html__( 'Older activity is on each team’s blog.', 'wporg' )
		);
	}

	return sprintf(
		'<div %1$s><ol class="wp-block-wporg-make-latest-activity__list">%2$s</ol>%3$s%4$s</div>',
		$wrapper_attributes,
		$list,
		$navigation,
		$end_note
	);
}

/**
 * Renders one post or comment.
 *
 * Posts look the way they do on an o2 blog: a byline, the title and the full
 * content. Comments are shown on their own, so their byline names the post.
 *
 * @param array<string, mixed> $item Item from get_items().
 * @return string List item markup.
 */
function render_item( array $item ): string {
	$user = get_userdata( (int) $item['user_id'] );
	if ( ! $user ) {
		return '';
	}

	$url    = (string) $item['url'];
	$author = sprintf(
		'<a class="wp-block-wporg-make-latest-activity__author" href="%1$s">%2$s</a>',
		esc_url( 'https://profiles.wordpress.org/' . $user->user_nicename . '/' ),
		esc_html( $user->display_name )
	);

	$is_comment = 'comment' === $item['type'];
	if ( $is_comment ) {
		$author = sprintf(
			/* translators: 1: Linked author name. 2: Linked post title. */
			__( '%1$s commented on %2$s', 'wporg' ),
			$author,
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( (string) $item['title'] ) )
		);
	}

	$timestamp = (int) strtotime( $item['date_gmt'] . ' UTC' );
	$time      = sprintf(
		'<a href="%1$s"><time datetime="%2$s" title="%3$s">%4$s</time></a>',
		esc_url( $url ),
		esc_attr( mysql_to_rfc3339( (string) $item['date_gmt'] ) . 'Z' ),
		esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
		/* translators: %s: Human-readable time difference, such as "2 hours". */
		esc_html( sprintf( __( '%s ago', 'wporg' ), human_time_diff( $timestamp ) ) )
	);

	$team = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( $item['team']['url'] ),
		esc_html( $item['team']['name'] )
	);

	$title = '';
	if ( ! $is_comment ) {
		$title = sprintf(
			'<h3 class="wp-block-wporg-make-latest-activity__title has-heading-5-font-size"><a href="%1$s">%2$s</a></h3>',
			esc_url( $url ),
			esc_html( (string) $item['title'] )
		);
	}

	// The content is the team blog's own rendered HTML, served on the same origin, so it is output as the blog itself would.
	return sprintf(
		'<li class="wp-block-wporg-make-latest-activity__item %1$s">
			<article>
				<header class="wp-block-wporg-make-latest-activity__header">
					<img class="wp-block-wporg-make-latest-activity__avatar" src="%2$s" alt="" width="40" height="40" loading="lazy" />
					<p class="wp-block-wporg-make-latest-activity__byline"><span>%3$s</span><span class="wp-block-wporg-make-latest-activity__meta">%4$s · %5$s</span></p>
				</header>
				%6$s
				<div class="wp-block-wporg-make-latest-activity__content">%7$s</div>
			</article>
		</li>',
		esc_attr( 'is-type-' . $item['type'] . ' make-' . $item['team']['slug'] ),
		esc_url( (string) get_avatar_url( $user->ID, array( 'size' => 80 ) ) ),
		$author,
		$team,
		$time,
		$title,
		$item['content']
	);
}
