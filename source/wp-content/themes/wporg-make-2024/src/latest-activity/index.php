<?php
/**
 * Block: Latest activity.
 *
 * Shows the newest posts from the Make team blogs the way o2 does, each with
 * its comments, with Newer and Older links over up to MAX_PAGES pages.
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

/**
 * Interactivity API store namespace.
 *
 * @var string
 */
const STORE = 'wporg-make/latest-activity';

/**
 * Deepest level comments are nested to, as on o2. Deeper replies continue at this level.
 *
 * @var int
 */
const COMMENT_DEPTH = 10;

add_action( 'init', __NAMESPACE__ . '\init' );
add_action( 'template_redirect', __NAMESPACE__ . '\maybe_redirect' );
add_filter( 'wporg_noindex_request', __NAMESPACE__ . '\noindex_paged_views' );
add_action( 'wp_delete_site', __NAMESPACE__ . '\flush_allowed_sites' );
add_action( 'wp_update_site', __NAMESPACE__ . '\flush_allowed_sites' );
add_action( 'save_post_make_site', __NAMESPACE__ . '\flush_allowed_sites' );

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
	$page = max( 1, get_requested_page() );

	// The markup doesn't depend on who is viewing, so one copy serves everyone.
	$cache_key = get_cache_key( 'html', $page );
	$html      = wp_cache_get( $cache_key, CACHE_GROUP );
	if ( is_string( $html ) ) {
		return $html;
	}

	$result = get_activity_page( $page );
	$html   = render_page( $page, $result );

	if ( ! $result['failed'] ) {
		wp_cache_set( $cache_key, $html, CACHE_GROUP, CACHE_TTL );
	}

	return $html;
}

/**
 * Renders one page of posts with its pagination.
 *
 * @param int                  $page   Page number.
 * @param array<string, mixed> $result Result of get_activity_page().
 * @return string Block markup.
 */
function render_page( int $page, array $result ): string {
	$items = get_items( $result['rows'], get_allowed_sites() );

	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'id'                  => ANCHOR,
			'data-wp-interactive' => STORE,
		)
	);

	// Keep a failed query out of the page cache, so the front page recovers as soon as the database does.
	if ( $result['failed'] && ! headers_sent() ) {
		nocache_headers();
	}

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
		$list .= render_post( $item );
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
		'<div %1$s><div class="wp-block-wporg-make-latest-activity__list">%2$s</div>%3$s%4$s</div>',
		$wrapper_attributes,
		$list,
		$navigation,
		$end_note
	);
}

/**
 * Renders one post the way o2 does: byline, title, content, actions and comments.
 *
 * Reply and Follow link to the post on its team blog, where both work.
 *
 * @param array<string, mixed> $item Post from get_items().
 * @return string Post markup.
 */
function render_post( array $item ): string {
	$user = get_userdata( (int) $item['user_id'] );
	if ( ! $user ) {
		return '';
	}

	$url     = (string) $item['url'];
	$profile = get_profile_url( $user );
	$count   = count( $item['comments'] );

	$reply_count = '';
	if ( $count ) {
		$reply_count = sprintf( '<span class="wp-block-wporg-make-latest-activity__count">%s</span>', esc_html( number_format_i18n( $count ) ) );
	}

	$comments = '';
	if ( $count ) {
		$comments_id = sprintf( 'latest-activity-comments-%1$s-%2$d', $item['team']['slug'], $item['id'] );
		$comments    = sprintf(
			'<footer class="wp-block-wporg-make-latest-activity__footer">
				<button type="button" class="wp-block-wporg-make-latest-activity__toggle" aria-controls="%1$s" aria-expanded="true" data-wp-bind--aria-expanded="context.isOpen" data-wp-on--click="actions.toggleComments">
					<span data-wp-bind--hidden="!context.isOpen">%2$s %3$s</span>
					<span hidden data-wp-bind--hidden="context.isOpen">%4$s %5$s</span>
				</button>
			</footer>
			<div id="%1$s" class="wp-block-wporg-make-latest-activity__comments" data-wp-bind--hidden="!context.isOpen">%6$s</div>',
			esc_attr( $comments_id ),
			get_icon( 'collapse' ),
			esc_html__( 'Hide Comments', 'wporg' ),
			get_icon( 'expand' ),
			esc_html__( 'Show Comments', 'wporg' ),
			render_comments( $item['comments'] )
		);
	}

	// The content is the team blog's own rendered HTML, served on the same origin, so it is output as the blog itself would.
	return sprintf(
		'<article class="wp-block-wporg-make-latest-activity__item %1$s" %2$s>
			<header class="wp-block-wporg-make-latest-activity__header">
				<a class="wp-block-wporg-make-latest-activity__avatar" href="%3$s"><img src="%4$s" alt="" width="50" height="50" loading="lazy" /></a>
				<a class="wp-block-wporg-make-latest-activity__author" href="%3$s">%5$s</a>
				<span class="wp-block-wporg-make-latest-activity__date"><a href="%6$s">%7$s</a> · %8$s</span>
				<h2 class="wp-block-wporg-make-latest-activity__title"><a href="%9$s">%10$s</a></h2>
			</header>
			<div class="wp-block-wporg-make-latest-activity__content">%11$s</div>
			<nav class="wp-block-wporg-make-latest-activity__actions" aria-label="%12$s">
				<a class="wp-block-wporg-make-latest-activity__button" href="%13$s">%14$s%15$s</a>
				<a class="wp-block-wporg-make-latest-activity__button" href="%9$s">%16$s</a>
			</nav>
			%17$s
		</article>',
		esc_attr( 'make-' . $item['team']['slug'] ),
		wp_interactivity_data_wp_context( array( 'isOpen' => true ) ),
		esc_url( $profile ),
		esc_url( (string) get_avatar_url( $user->ID, array( 'size' => 100 ) ) ),
		esc_html( $user->display_name ),
		esc_url( $item['team']['url'] ),
		esc_html( $item['team']['name'] ),
		render_time( $url, (string) $item['date_gmt'] ),
		esc_url( $url ),
		esc_html( (string) $item['title'] ),
		$item['content'],
		esc_attr__( 'Post actions', 'wporg' ),
		esc_url( $url . '#respond' ),
		esc_html__( 'Reply', 'wporg' ),
		$reply_count,
		esc_html__( 'Follow', 'wporg' ),
		$comments
	);
}

/**
 * Renders a post's comments as threads.
 *
 * A reply whose parent is not shown is treated as a top-level comment.
 *
 * @param array<int, array<string, mixed>> $comments Comments, oldest first.
 * @return string Comments markup.
 */
function render_comments( array $comments ): string {
	$shown    = array_flip( array_column( $comments, 'id' ) );
	$children = array();

	foreach ( $comments as $comment ) {
		$parent                = isset( $shown[ $comment['parent'] ] ) ? $comment['parent'] : 0;
		$children[ $parent ][] = $comment;
	}

	return render_comment_level( $children, 0, 1 );
}

/**
 * Renders the replies to one comment, or the top-level comments.
 *
 * @param array<int, array<int, array<string, mixed>>> $children  Comments grouped by parent ID.
 * @param int                                          $parent_id Parent comment ID, 0 for top-level.
 * @param int                                          $depth     Depth of the comments being rendered.
 * @return string Comments markup.
 */
function render_comment_level( array $children, int $parent_id, int $depth ): string {
	$html = '';

	foreach ( $children[ $parent_id ] ?? array() as $comment ) {
		if ( $depth < COMMENT_DEPTH ) {
			$html .= render_comment( $comment, render_comment_level( $children, $comment['id'], $depth + 1 ) );
		} else {
			$html .= render_comment( $comment, '' ) . render_comment_level( $children, $comment['id'], $depth );
		}
	}

	return $html;
}

/**
 * Renders one comment the way o2 does. Its Reply link points to the comment on the team blog.
 *
 * @param array<string, mixed> $comment Comment from get_post_comments().
 * @param string               $replies Rendered replies, nested under the comment.
 * @return string Comment markup.
 */
function render_comment( array $comment, string $replies ): string {
	$user = get_userdata( (int) $comment['user_id'] );
	if ( ! $user ) {
		return $replies;
	}

	$url = (string) $comment['url'];

	if ( $replies ) {
		$replies = '<div class="wp-block-wporg-make-latest-activity__children">' . $replies . '</div>';
	}

	return sprintf(
		'<article class="wp-block-wporg-make-latest-activity__comment">
			<header class="wp-block-wporg-make-latest-activity__comment-header">
				<img class="wp-block-wporg-make-latest-activity__comment-avatar" src="%1$s" alt="" width="32" height="32" loading="lazy" />
				<p class="wp-block-wporg-make-latest-activity__comment-meta"><a class="wp-block-wporg-make-latest-activity__comment-author" href="%2$s">%3$s</a> %4$s</p>
			</header>
			<div class="wp-block-wporg-make-latest-activity__comment-content">
				%5$s
				<p class="wp-block-wporg-make-latest-activity__comment-actions"><a class="wp-block-wporg-make-latest-activity__comment-reply" href="%6$s">%7$s%8$s</a></p>
			</div>
			%9$s
		</article>',
		esc_url( (string) get_avatar_url( $user->ID, array( 'size' => 64 ) ) ),
		esc_url( get_profile_url( $user ) ),
		esc_html( $user->display_name ),
		render_time( $url, (string) $comment['date_gmt'] ),
		$comment['content'],
		esc_url( $url ),
		get_icon( 'reply' ),
		esc_html__( 'Reply', 'wporg' ),
		$replies
	);
}

/**
 * Returns a user's WordPress.org profile URL.
 *
 * @param \WP_User $user User.
 * @return string Profile URL.
 */
function get_profile_url( \WP_User $user ): string {
	return 'https://profiles.wordpress.org/' . $user->user_nicename . '/';
}

/**
 * Renders a linked timestamp in o2's style: "2 hours ago", "Yesterday at 2:33 pm" or "9:22 pm on November 4, 2026".
 *
 * @param string $url      Link target.
 * @param string $date_gmt Date in GMT, as stored.
 * @return string Timestamp markup.
 */
function render_time( string $url, string $date_gmt ): string {
	$timestamp   = (int) strtotime( $date_gmt . ' UTC' );
	$time_format = (string) get_option( 'time_format' );
	$day         = wp_date( 'Y-m-d', $timestamp );

	if ( wp_date( 'Y-m-d' ) === $day ) {
		/* translators: %s: Human-readable time difference, such as "2 hours". */
		$text = sprintf( __( '%s ago', 'wporg' ), human_time_diff( $timestamp ) );
	} elseif ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
		/* translators: %s: Time of day. */
		$text = sprintf( __( 'Yesterday at %s', 'wporg' ), wp_date( $time_format, $timestamp ) );
	} else {
		/* translators: 1: Time of day. 2: Date. */
		$text = sprintf( __( '%1$s on %2$s', 'wporg' ), wp_date( $time_format, $timestamp ), wp_date( (string) get_option( 'date_format' ), $timestamp ) );
	}

	return sprintf(
		'<a class="wp-block-wporg-make-latest-activity__time" href="%1$s"><time datetime="%2$s">%3$s</time></a>',
		esc_url( $url ),
		esc_attr( mysql_to_rfc3339( $date_gmt ) . 'Z' ),
		esc_html( $text )
	);
}

/**
 * Returns a Genericons icon as inline SVG, so the icon font does not have to be loaded.
 *
 * @param string $name Icon name: reply, expand or collapse.
 * @return string SVG markup.
 */
function get_icon( string $name ): string {
	$paths = array(
		'reply'    => 'M1920 832l-640 -640v448h-1024v704l384 384v-704h640v448z',
		'expand'   => 'M1408 1280l128 -128l-576 -576l-576 576l128 128l448 -448z',
		'collapse' => 'M1408 640l-448 448l-448 -448l-128 128l576 576l576 -576z',
	);

	// Font glyphs have their y-axis pointing up, so they are flipped into SVG coordinates.
	return sprintf(
		'<svg class="wp-block-wporg-make-latest-activity__icon" viewBox="0 0 2048 2048" width="16" height="16" aria-hidden="true" focusable="false"><path transform="matrix(1 0 0 -1 0 2048)" d="%s" /></svg>',
		esc_attr( $paths[ $name ] )
	);
}
