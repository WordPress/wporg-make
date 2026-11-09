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

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only, and only digits are accepted.
	$raw = wp_unslash( $_GET[ QUERY_ARG ] );
	if ( ! is_string( $raw ) || ! ctype_digit( $raw ) ) {
		return 0;
	}

	$page = (int) $raw;

	// Page 1 is served without the parameter, so `?activity-page=1` is a duplicate.
	return $page >= 2 && $page <= MAX_PAGES ? $page : 0;
}

/**
 * Returns where a request for an invalid or empty page should go.
 *
 * Redirecting rather than clamping keeps duplicate pages out of the page cache.
 *
 * @return string|null The URL without the page parameter, or null when no redirect is needed.
 */
function get_redirect_url(): ?string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging parameter.
	if ( ! is_front_page() || ! isset( $_GET[ QUERY_ARG ] ) ) {
		return null;
	}

	$page = get_requested_page();
	if ( $page && get_activity_page( $page )['rows'] ) {
		return null;
	}

	return remove_query_arg( QUERY_ARG );
}

/**
 * Redirects requests for pages that cannot be served.
 */
function maybe_redirect(): void {
	$url = get_redirect_url();

	if ( null !== $url ) {
		wp_safe_redirect( $url, 301 );
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
 * @param array<string, mixed> $item Item from get_items().
 * @return string List item markup.
 */
function render_item( array $item ): string {
	$user = get_userdata( (int) $item['user_id'] );
	if ( ! $user ) {
		return '';
	}

	$author = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( 'https://profiles.wordpress.org/' . $user->user_nicename . '/' ),
		esc_html( $user->display_name )
	);
	$title  = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( (string) $item['url'] ),
		esc_html( (string) $item['title'] )
	);

	$summary = 'comment' === $item['type']
		/* translators: 1: Linked author name. 2: Linked post title. */
		? sprintf( __( '%1$s commented on %2$s', 'wporg' ), $author, $title )
		/* translators: 1: Linked author name. 2: Linked post title. */
		: sprintf( __( '%1$s published %2$s', 'wporg' ), $author, $title );

	$timestamp = (int) strtotime( $item['date_gmt'] . ' UTC' );
	$time      = sprintf(
		'<time datetime="%1$s" title="%2$s">%3$s</time>',
		esc_attr( mysql_to_rfc3339( (string) $item['date_gmt'] ) . 'Z' ),
		esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
		/* translators: %s: Human-readable time difference, such as "2 hours". */
		esc_html( sprintf( __( '%s ago', 'wporg' ), human_time_diff( $timestamp ) ) )
	);

	$team = sprintf(
		'<a class="wp-block-wporg-make-latest-activity__team" href="%1$s">%2$s</a>',
		esc_url( $item['team']['url'] ),
		esc_html( $item['team']['name'] )
	);

	return sprintf(
		'<li class="wp-block-wporg-make-latest-activity__item %1$s">
			<img class="wp-block-wporg-make-latest-activity__avatar" src="%2$s" alt="" width="32" height="32" loading="lazy" />
			<div class="wp-block-wporg-make-latest-activity__body">
				<p class="wp-block-wporg-make-latest-activity__summary">%3$s</p>
				<p class="wp-block-wporg-make-latest-activity__excerpt">%4$s</p>
				<p class="wp-block-wporg-make-latest-activity__meta">%5$s · %6$s</p>
			</div>
		</li>',
		esc_attr( 'is-type-' . $item['type'] . ' make-' . $item['team']['slug'] ),
		esc_url( (string) get_avatar_url( $user->ID, array( 'size' => 64 ) ) ),
		$summary,
		esc_html( (string) $item['excerpt'] ),
		$team,
		$time
	);
}
