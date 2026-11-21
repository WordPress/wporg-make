<?php
/**
 * Block: Latest activity.
 *
 * Lists the newest posts from the Make team blogs, grouped by day, each with its
 * team, a summary and who wrote it, with "Load more" over up to MAX_PAGES pages.
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
 * Query string parameter holding the first page of a range, for "Load more".
 *
 * `?activity-page=3&activity-from=1` renders pages 1 to 3, which is what the
 * Interactivity API router swaps in when the next page is loaded in place.
 *
 * @var string
 */
const FROM_ARG = 'activity-from';

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
add_action( 'init', __NAMESPACE__ . '\init' );

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
 * Returns the first page of the requested range.
 *
 * @param int $page Last page of the range.
 * @return int The first page: the requested one when it's a valid page up to $page, otherwise $page.
 */
function get_requested_from( int $page ): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only, and only a canonical integer is accepted.
	$raw = isset( $_GET[ FROM_ARG ] ) ? wp_unslash( $_GET[ FROM_ARG ] ) : '';
	if ( ! is_string( $raw ) || (string) (int) $raw !== $raw ) {
		return $page;
	}

	$from = (int) $raw;

	return $from >= 1 && $from <= $page ? $from : $page;
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
add_action( 'template_redirect', __NAMESPACE__ . '\maybe_redirect' );

/**
 * Marks paged views of the front page noindex, follow.
 *
 * They repeat the team blogs' posts, which are indexed at their own permalinks, and
 * their links should still be followed to reach them. Core's robots API is used
 * because it's output on every site, whichever SEO plugins are loaded.
 *
 * @param array<string, bool|string> $robots Robots directives.
 * @return array<string, bool|string> The filtered directives.
 */
function noindex_paged_views( array $robots ): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging parameter.
	if ( is_front_page() && ( isset( $_GET[ QUERY_ARG ] ) || isset( $_GET[ FROM_ARG ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging parameters.
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}

	return $robots;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\noindex_paged_views' );

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
	$from = get_requested_from( $page );

	$items     = array();
	$failed    = false;
	$has_older = false;
	for ( $number = $from; $number <= $page; $number++ ) {
		$result    = get_activity_page( $number );
		$failed    = $failed || $result['failed'];
		$has_older = $result['has_older'];

		// Pages are cached separately, so a post published in between can push one onto the next page as well.
		foreach ( get_page_items( $number, $result ) as $item ) {
			$items[ "{$item['blog_id']}:{$item['id']}" ] ??= $item;
		}
	}
	$items = array_values( $items );

	wp_interactivity_state(
		STORE,
		array(
			'today'         => __( 'Today', 'wporg' ),
			'yesterday'     => __( 'Yesterday', 'wporg' ),
			'loadedMore'    => __( 'More posts loaded.', 'wporg' ),
			/* translators: 1: Time of day. 2: Date. */
			'dateTime'      => __( '%1$s on %2$s', 'wporg' ),
			'isAutomatic'   => false,
			'isLoadingMore' => false,
			'status'        => '',
		)
	);

	return render_page( $from, $page, $has_older, $failed, $items );
}

/**
 * Returns one page's posts, from the cache when possible.
 *
 * The loaded posts are the same for everyone, so they are cached and shared.
 *
 * @param int                  $page   Page number.
 * @param array<string, mixed> $result Result of get_activity_page() for the page.
 * @return array<int, array<string, mixed>> Posts from get_items().
 */
function get_page_items( int $page, array $result ): array {
	$cache_key = get_cache_key( 'entries', $page );
	$cached    = wp_cache_get( $cache_key, CACHE_GROUP );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$items = get_items( $result['rows'], get_allowed_sites() );

	if ( ! $result['failed'] ) {
		wp_cache_set( $cache_key, $items, CACHE_GROUP, CACHE_TTL );
	}

	return $items;
}

/**
 * Renders a range of pages of posts with their pagination.
 *
 * @param int                              $from      First page of the range.
 * @param int                              $page      Last page of the range.
 * @param bool                             $has_older Whether a page after $page exists.
 * @param bool                             $failed    Whether a query for the range failed.
 * @param array<int, array<string, mixed>> $items     Posts from get_items().
 * @return string Block markup.
 */
function render_page( int $from, int $page, bool $has_older, bool $failed, array $items ): string {
	// The wrapper is a router region, so "Load more" can swap in a longer range without a page load.
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'id'                    => ANCHOR,
			'data-wp-interactive'   => STORE,
			'data-wp-router-region' => 'wporg-make-latest-activity',
		)
	);

	// Keep a failed query out of the page cache, so the front page recovers as soon as the database does.
	if ( $failed && ! headers_sent() ) {
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

	$list     = '';
	$previous = '';
	foreach ( $items as $item ) {
		$day      = wp_date( 'Y-m-d', get_timestamp( $item ) );
		$list    .= render_entry( $item, $day !== $previous );
		$previous = $day;
	}

	$links = '';
	if ( $has_older ) {
		// Without JavaScript this link opens the next page; with it, the link is hidden and the router swaps in this range extended by that page as the reader nears it.
		$range = add_query_arg(
			array(
				QUERY_ARG => $page + 1,
				FROM_ARG  => $from,
			),
			home_url( '/' )
		);

		$links .= sprintf(
			'<a class="wp-block-wporg-make-latest-activity__button wp-block-wporg-make-latest-activity__more" href="%1$s" data-range="%2$s" data-wp-bind--hidden="state.isAutomatic" data-wp-on--click="actions.loadMore">%3$s</a>
			<p class="wp-block-wporg-make-latest-activity__loading" hidden data-wp-bind--hidden="!state.isLoadingMore">%4$s</p>',
			esc_url( get_page_url( $page + 1 ) ),
			esc_url( $range ),
			esc_html__( 'Load more', 'wporg' ),
			esc_html__( 'Loading more posts…', 'wporg' )
		);
	}

	$navigation = '';
	if ( $links ) {
		$navigation = sprintf(
			'<nav class="wp-block-wporg-make-latest-activity__pagination" aria-label="%1$s" data-wp-init="callbacks.observeMore">%2$s</nav>',
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
		'<div %1$s><div class="wp-block-wporg-make-latest-activity__list">%2$s</div>%3$s%4$s<p class="screen-reader-text" role="status" data-wp-text="state.status"></p></div>',
		$wrapper_attributes,
		$list,
		$navigation,
		$end_note
	);
}

/**
 * Renders one post as a row of the list, with the heading of its day before it.
 *
 * Every row carries a day heading. The server shows the first of each day in the site's
 * time zone; the view script works out again which to show, in the viewer's time zone
 * and among the posts of the picked team.
 *
 * @param array<string, mixed> $item         Post from get_items().
 * @param bool                 $is_first_day Whether the post is the first of its day in the site's time zone.
 * @return string Row markup.
 */
function render_entry( array $item, bool $is_first_day ): string {
	$user = get_userdata( (int) $item['user_id'] );
	if ( ! $user ) {
		return '';
	}

	$timestamp = get_timestamp( $item );
	$url       = (string) $item['url'];
	$team      = (string) $item['team']['slug'];

	$label = '';
	if ( '' !== $item['label'] ) {
		$label = sprintf(
			'<span class="wp-block-wporg-make-latest-activity__badge">%s</span>',
			esc_html( (string) $item['label'] )
		);
	}

	// Every post's label sits beside its title, so labels read the same on every row.
	$title = sprintf(
		'<div class="wp-block-wporg-make-latest-activity__line"><h4 class="wp-block-wporg-make-latest-activity__title"><a href="%1$s">%2$s</a></h4>%3$s</div>',
		esc_url( $url ),
		esc_html( (string) $item['title'] ),
		$label
	);

	$summary = '';
	if ( '' !== $item['summary'] ) {
		$summary = sprintf( '<p class="wp-block-wporg-make-latest-activity__summary">%s</p>', esc_html( (string) $item['summary'] ) );
	}

	$body = sprintf(
		'%1$s%2$s
		<p class="wp-block-wporg-make-latest-activity__meta">
			<img class="wp-block-wporg-make-latest-activity__avatar" src="%3$s" alt="" width="24" height="24" loading="lazy" />
			<a class="wp-block-wporg-make-latest-activity__author" href="%4$s">%5$s</a>
			%6$s
			<span class="wp-block-wporg-make-latest-activity__reading">%7$s</span>
			%8$s
		</p>',
		$title,
		$summary,
		esc_url( (string) get_avatar_url( $user->ID, array( 'size' => 48 ) ) ),
		esc_url( get_profile_url( $user ) ),
		esc_html( $user->display_name ),
		render_time( $timestamp ),
		esc_html(
			sprintf(
				/* translators: %s: Number of minutes. */
				_n( '%s min read', '%s min read', (int) $item['minutes'], 'wporg' ),
				number_format_i18n( (int) $item['minutes'] )
			)
		),
		render_comments( $item, $url )
	);

	return sprintf(
		'<div class="wp-block-wporg-make-latest-activity__entry" %1$s data-wp-key="%2$s" data-date="%4$s" data-wp-init="callbacks.localizeDay">
			<h3 class="wp-block-wporg-make-latest-activity__day"%9$s data-wp-bind--hidden="!context.isFirstOfDay" data-wp-text="context.dayLabel">%5$s</h3>
			<article class="wp-block-wporg-make-latest-activity__item make-%3$s">
				<a class="wp-block-wporg-make-latest-activity__team" href="%6$s"><span class="wp-block-wporg-make-latest-activity__team-name">%7$s</span></a>
				<div class="wp-block-wporg-make-latest-activity__body">%8$s</div>
			</article>
		</div>',
		wp_interactivity_data_wp_context(
			array(
				'isFirstOfDay' => $is_first_day,
				'dayLabel'     => get_day_label( $timestamp ),
			)
		),
		esc_attr( $team . '-' . $item['id'] ),
		esc_attr( $team ),
		esc_attr( gmdate( 'c', $timestamp ) ),
		esc_html( get_day_label( $timestamp ) ),
		esc_url( (string) $item['team']['url'] ),
		esc_html( (string) $item['team']['name'] ),
		$body,
		$is_first_day ? '' : ' hidden'
	);
}

/**
 * Renders a post's comment count, linked to where the reader can join in.
 *
 * Team blogs run o2, which redraws the post in the browser and then scrolls to the
 * link's anchor. Its comment thread has no anchor of its own, so:
 *
 * - Logged in, the link goes to the reply box after the comments ("#respond").
 * - Logged out, there's no reply box (commenting needs an account), so it goes to the
 *   first comment instead, or isn't a link when there are none.
 *
 * @param array<string, mixed> $item Post from get_items().
 * @param string               $url  Post URL.
 * @return string Comment count markup.
 */
function render_comments( array $item, string $url ): string {
	$label  = esc_html( get_comments_label( (int) $item['comments'] ) );
	$anchor = '';

	if ( is_user_logged_in() ) {
		$anchor = 'respond';
	} elseif ( ! empty( $item['first'] ) ) {
		$anchor = 'comment-' . (int) $item['first'];
	}

	if ( '' === $anchor ) {
		return '<span class="wp-block-wporg-make-latest-activity__comments">' . $label . '</span>';
	}

	return sprintf(
		'<a class="wp-block-wporg-make-latest-activity__comments" href="%1$s">%2$s</a>',
		esc_url( $url . '#' . $anchor ),
		$label
	);
}

/**
 * Returns how many comments a post has, in words.
 *
 * @param int $count Number of approved comments.
 * @return string Comment count, such as "No comments" or "3 comments".
 */
function get_comments_label( int $count ): string {
	if ( 0 === $count ) {
		return __( 'No comments', 'wporg' );
	}

	/* translators: %s: Number of comments. */
	return sprintf( _n( '%s comment', '%s comments', $count, 'wporg' ), number_format_i18n( $count ) );
}

/**
 * Returns a post's publish time as a Unix timestamp.
 *
 * @param array<string, mixed> $item Post from get_items().
 * @return int Timestamp.
 */
function get_timestamp( array $item ): int {
	return (int) strtotime( $item['date_gmt'] . ' UTC' );
}

/**
 * Returns the heading of a day in the site's time zone: "Today", "Yesterday" or the date.
 *
 * @param int $timestamp Time in the day.
 * @return string Day heading.
 */
function get_day_label( int $timestamp ): string {
	$day = wp_date( 'Y-m-d', $timestamp );

	if ( wp_date( 'Y-m-d' ) === $day ) {
		return __( 'Today', 'wporg' );
	}

	if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
		return __( 'Yesterday', 'wporg' );
	}

	/* translators: Date format for day headings in the Latest activity list, see https://www.php.net/manual/datetime.format.php */
	$format = wp_date( 'Y' ) === wp_date( 'Y', $timestamp ) ? _x( 'l, F j', 'day heading', 'wporg' ) : _x( 'l, F j, Y', 'day heading with year', 'wporg' );

	return wp_date( $format, $timestamp );
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
 * Renders a post's time of day in the site's time zone; the view script switches it to the viewer's.
 *
 * The day comes from the heading above the post, so only the time is shown.
 *
 * @param int $timestamp Publish time.
 * @return string Time markup.
 */
function render_time( int $timestamp ): string {
	$time_format = (string) get_option( 'time_format' );

	/* translators: 1: Time of day. 2: Date. */
	$title = sprintf( __( '%1$s on %2$s', 'wporg' ), wp_date( $time_format . ' (T)', $timestamp ), wp_date( (string) get_option( 'date_format' ), $timestamp ) );
	$text  = wp_date( $time_format, $timestamp );

	return sprintf(
		'<time class="wp-block-wporg-make-latest-activity__time" datetime="%1$s" title="%2$s" %3$s data-wp-text="context.text" data-wp-bind--title="context.title" data-wp-init="callbacks.localizeTime">%4$s</time>',
		esc_attr( gmdate( 'c', $timestamp ) ),
		esc_attr( $title ),
		wp_interactivity_data_wp_context(
			array(
				'text'  => $text,
				'title' => $title,
			)
		),
		esc_html( $text )
	);
}
