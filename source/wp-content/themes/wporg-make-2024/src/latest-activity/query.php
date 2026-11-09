<?php
/**
 * Data access for the Latest activity block.
 *
 * Reads the newest posts from every allowed team blog on the network in a
 * single query, then loads each post's comments.
 *
 * @package WordPressdotorg\Theme\Make_2024
 */

declare( strict_types = 1 );

namespace WordPressdotorg\Theme\Make_2024\Latest_Activity;

use WP_Post;

/**
 * Posts shown per page.
 *
 * @var int
 */
const PER_PAGE = 20;

/**
 * Deepest page served.
 *
 * Caps the rows each branch of the query reads, and the URLs crawlers can walk.
 *
 * @var int
 */
const MAX_PAGES = 10;

/**
 * Object cache group.
 *
 * @var string
 */
const CACHE_GROUP = 'wporg-make-latest-activity';

/**
 * Object cache group for results kept only for the current request.
 *
 * @var string
 */
const REQUEST_CACHE_GROUP = 'wporg-make-latest-activity-request';

/**
 * Returns the team blogs whose activity is shown, keyed by blog ID.
 *
 * A blog is included only when it is listed: a published `make_site` post
 * points at it, or its path is one of the extras. Detecting private blogs
 * instead would fail open.
 *
 * @return array<int, array{slug: string, name: string, url: string}> Allowed blogs.
 */
function get_allowed_sites(): array {
	if ( ! is_multisite() ) {
		return array();
	}

	$sites = wp_cache_get( 'allowed-sites', CACHE_GROUP );
	if ( is_array( $sites ) ) {
		return $sites;
	}

	$listed     = array();
	$make_sites = get_posts(
		array(
			'post_type'   => 'make_site',
			'post_status' => 'publish',
			'numberposts' => -1,
		)
	);

	foreach ( $make_sites as $make_site ) {
		$blog_id = (int) get_post_meta( $make_site->ID, 'make_site_id', true );
		if ( $blog_id > 0 ) {
			$listed[ $blog_id ] = array(
				'slug' => $make_site->post_name,
				'name' => $make_site->post_title,
			);
		}
	}

	/**
	 * Filters the blogs that are shown without a `make_site` post.
	 *
	 * @param array<string, string> $extras Site paths mapped to display names.
	 */
	$extras = (array) apply_filters(
		'wporg_make_latest_activity_extra_sites',
		array(
			'/updates/' => __( 'Team Updates', 'wporg' ),
			'/project/' => __( 'Project Updates', 'wporg' ),
		)
	);

	$candidates = get_sites(
		array(
			'network_id'   => get_current_network_id(),
			'domain'       => get_site()->domain,
			'site__not_in' => array( get_main_site_id() ),
			'public'       => 1,
			'archived'     => 0,
			'deleted'      => 0,
			'spam'         => 0,
			'number'       => 0,
		)
	);

	$sites = array();
	foreach ( $candidates as $site ) {
		$blog_id = (int) $site->blog_id;

		if ( isset( $listed[ $blog_id ] ) ) {
			$label = $listed[ $blog_id ];
		} elseif ( isset( $extras[ $site->path ] ) ) {
			$label = array(
				'slug' => trim( $site->path, '/' ),
				'name' => (string) $extras[ $site->path ],
			);
		} else {
			continue;
		}

		if ( is_members_only( $blog_id ) ) {
			continue;
		}

		$sites[ $blog_id ] = $label + array(
			'url' => set_url_scheme( 'http://' . $site->domain . $site->path ),
		);
	}

	wp_cache_set( 'allowed-sites', $sites, CACHE_GROUP, 15 * MINUTE_IN_SECONDS );

	return $sites;
}

/**
 * Whether a blog is locked to its members by the private `subscribers-only.php` plugin.
 *
 * @param int $blog_id Blog ID.
 * @return bool True when the blog is members-only.
 */
function is_members_only( int $blog_id ): bool {
	foreach ( (array) get_blog_option( $blog_id, 'active_plugins', array() ) as $plugin ) {
		if ( 'subscribers-only.php' === basename( (string) $plugin ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns one page of activity rows from the allowed blogs, newest first.
 *
 * Results are kept for the request, so the redirect check and the block
 * share one query. A failed query is not kept.
 *
 * @param int $page Page number, 1 to MAX_PAGES.
 * @return array{rows: array<int, array{blog_id: int, post_id: int, date_gmt: string}>, has_older: bool, failed: bool} The page's rows, whether an older page exists, and whether the query failed.
 */
function get_activity_page( int $page ): array {
	wp_cache_add_non_persistent_groups( REQUEST_CACHE_GROUP );

	$blog_ids = array_keys( get_allowed_sites() );
	$key      = $page . ':' . implode( ',', $blog_ids );
	$result   = wp_cache_get( $key, REQUEST_CACHE_GROUP );

	if ( ! is_array( $result ) ) {
		$rows = query_rows( $blog_ids, $page );
		if ( null === $rows ) {
			return array(
				'rows'      => array(),
				'has_older' => false,
				'failed'    => true,
			);
		}

		$result = array(
			'rows'      => array_slice( $rows, 0, PER_PAGE ),
			'has_older' => count( $rows ) > PER_PAGE && $page < MAX_PAGES,
			'failed'    => false,
		);
		wp_cache_set( $key, $result, REQUEST_CACHE_GROUP );
	}

	return $result;
}

/**
 * Queries one page of posts across the given blogs, newest first.
 *
 * Each blog contributes one branch; the branches are combined with UNION ALL
 * and sorted once. Each branch returns as many rows as the page reaches into,
 * so no blog can come up short. One extra row tells whether an older page
 * exists.
 *
 * @param int[] $blog_ids Blog IDs to read.
 * @param int   $page     Page number.
 * @return array<int, array{blog_id: int, post_id: int, date_gmt: string}>|null Up to PER_PAGE + 1 rows, or null when the query failed.
 */
function query_rows( array $blog_ids, int $page ): ?array {
	global $wpdb;

	if ( ! $blog_ids ) {
		return array();
	}

	$offset     = ( $page - 1 ) * PER_PAGE;
	$limit      = $offset + PER_PAGE + 1;
	$xpost_like = $wpdb->esc_like( 'xpost-' ) . '%';
	$branches   = array();
	$args       = array();

	foreach ( $blog_ids as $blog_id ) {
		$blog_id = (int) $blog_id;
		$prefix  = $wpdb->get_blog_prefix( $blog_id );

		// Sorting by post_date, not post_date_gmt, lets MySQL walk the type_status_date index.
		$branches[] = "( SELECT {$blog_id} AS blog_id, p.ID AS post_id, p.post_date_gmt AS date_gmt
			FROM {$prefix}posts p
			WHERE " . get_post_rules_sql( $prefix ) . ' AND ' . get_active_user_sql( 'p.post_author' ) . '
			ORDER BY p.post_date DESC, p.ID DESC
			LIMIT %d )';
		array_push( $args, $xpost_like, $limit );
	}

	$sql = implode( ' UNION ALL ', $branches ) . ' ORDER BY date_gmt DESC, blog_id DESC, post_id DESC LIMIT %d OFFSET %d';
	array_push( $args, PER_PAGE + 1, $offset );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names come from get_blog_prefix() for integer blog IDs; every value is a placeholder.
	$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

	if ( ! is_array( $results ) || '' !== $wpdb->last_error ) {
		return null;
	}

	return array_map(
		static fn( array $row ): array => array(
			'blog_id'  => (int) $row['blog_id'],
			'post_id'  => (int) $row['post_id'],
			'date_gmt' => (string) $row['date_gmt'],
		),
		$results
	);
}

/**
 * Returns the SQL conditions a post must meet to appear, on the alias `p`.
 *
 * The result holds one placeholder, for the xpost slug pattern.
 *
 * @param string $prefix Table prefix of the blog.
 * @return string SQL conditions.
 */
function get_post_rules_sql( string $prefix ): string {
	return "p.post_type = 'post'
		AND p.post_status = 'publish'
		AND p.post_password = ''
		AND p.post_author > 0
		AND p.post_name NOT LIKE %s
		AND NOT EXISTS ( SELECT 1 FROM {$prefix}postmeta pm WHERE pm.post_id = p.ID AND pm.meta_key = '_xpost_original_permalink' )";
}

/**
 * Returns an SQL condition requiring the user in a column to exist and be in good standing.
 *
 * @param string $column Column holding the user ID.
 * @return string SQL condition.
 */
function get_active_user_sql( string $column ): string {
	global $wpdb;

	return "EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = {$column} AND u.spam = 0 AND u.deleted = 0 )";
}

/**
 * Loads posts and their comments for display, switching into each blog once.
 *
 * Rows whose post can no longer be loaded are left out.
 *
 * @param array<int, array{blog_id: int, post_id: int, date_gmt: string}> $rows  Post rows.
 * @param array<int, array{slug: string, name: string, url: string}>      $sites Allowed blogs.
 * @return array<int, array<string, mixed>> Posts in row order.
 */
function get_items( array $rows, array $sites ): array {
	$ids = array();
	foreach ( $rows as $row ) {
		$ids[ $row['blog_id'] ][] = $row['post_id'];
	}

	$loaded = array();
	foreach ( $ids as $blog_id => $post_ids ) {
		switch_to_blog( $blog_id );

		// finally guarantees the restore; an unrestored switch leaks into the rest of the page.
		try {
			$loaded += load_posts( $blog_id, $post_ids );
		} finally {
			restore_current_blog();
		}
	}

	$items = array();
	foreach ( $rows as $row ) {
		$key = "{$row['blog_id']}:{$row['post_id']}";
		if ( isset( $loaded[ $key ], $sites[ $row['blog_id'] ] ) ) {
			$items[] = $loaded[ $key ] + array( 'team' => $sites[ $row['blog_id'] ] );
		}
	}

	return $items;
}

/**
 * Loads posts and their comments from the current blog.
 *
 * @param int   $blog_id  Current blog ID, used in the keys.
 * @param int[] $post_ids Post IDs.
 * @return array<string, array<string, mixed>> Posts keyed by "blog:id".
 */
function load_posts( int $blog_id, array $post_ids ): array {
	$posts = get_posts(
		array(
			'include'     => $post_ids,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => count( $post_ids ),
		)
	);

	$comments = get_post_comments( $post_ids );
	$items    = array();

	foreach ( $posts as $post ) {
		$url = get_permalink( $post );
		if ( ! $url ) {
			continue;
		}

		$post_comments = $comments[ $post->ID ] ?? array();
		foreach ( $post_comments as &$comment ) {
			// Built by hand: get_comment_link() would read comment paging options that o2 overrides only on its own blogs.
			$comment['url'] = $url . '#comment-' . $comment['id'];
		}
		unset( $comment );

		$items[ "{$blog_id}:{$post->ID}" ] = array(
			'id'       => $post->ID,
			'user_id'  => (int) $post->post_author,
			'date_gmt' => $post->post_date_gmt,
			'title'    => get_title( $post ),
			'content'  => get_post_html( $post ),
			'url'      => $url,
			'comments' => $post_comments,
		);
	}

	return $items;
}

/**
 * Loads the comments that may be shown on the given posts of the current blog, oldest first.
 *
 * Only approved comments by logged-in users in good standing count, and o2
 * xcomments (copies of a comment made elsewhere) are left out.
 *
 * @param int[] $post_ids Post IDs.
 * @return array<int, array<int, array<string, mixed>>> Comments grouped by post ID.
 */
function get_post_comments( array $post_ids ): array {
	$comments = get_comments(
		array(
			'post__in'   => $post_ids,
			'status'     => 'approve',
			'type'       => 'comment',
			'orderby'    => array(
				'comment_date_gmt' => 'ASC',
				'comment_ID'       => 'ASC',
			),
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by one page of posts.
				array(
					'key'     => 'xcomment_origin',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	cache_users( array_unique( array_map( static fn( \WP_Comment $comment ): int => (int) $comment->user_id, $comments ) ) );

	$grouped = array();
	foreach ( $comments as $comment ) {
		$user = get_userdata( (int) $comment->user_id );
		if ( ! $user || ! empty( $user->spam ) || ! empty( $user->deleted ) ) {
			continue;
		}

		$grouped[ (int) $comment->comment_post_ID ][] = array(
			'id'       => (int) $comment->comment_ID,
			'parent'   => (int) $comment->comment_parent,
			'user_id'  => (int) $comment->user_id,
			'date_gmt' => $comment->comment_date_gmt,
			'content'  => get_comment_html( $comment->comment_content ),
		);
	}

	return $grouped;
}

/**
 * Returns a post's title as plain text, falling back to the start of its content.
 *
 * @param WP_Post $post Post.
 * @return string Title.
 */
function get_title( WP_Post $post ): string {
	$title = trim( wp_strip_all_tags( $post->post_title ) );

	if ( '' === $title ) {
		$title = get_excerpt( $post->post_content, 8 );
	}

	return '' !== $title ? $title : __( '(no title)', 'wporg' );
}

/**
 * Returns a plain-text excerpt of content, for titles that are empty.
 *
 * @param string $content Raw content.
 * @param int    $words   Maximum number of words.
 * @return string Excerpt.
 */
function get_excerpt( string $content, int $words ): string {
	return trim( wp_trim_words( strip_shortcode_tags( excerpt_remove_blocks( $content ) ), $words ) );
}

/**
 * Renders post content for display, the way `the_content` would on the team blog.
 *
 * The `the_content` filters themselves are not run: in a switched context they
 * are the root site's, and some of them (oEmbed, for one) would write caches
 * keyed to the wrong blog's posts. Blocks render in the team blog's context,
 * with the post as their postId context.
 *
 * @param WP_Post $post Post from the current blog.
 * @return string Post HTML.
 */
function get_post_html( WP_Post $post ): string {
	$content    = $post->post_content;
	$has_blocks = has_blocks( $content );
	$previous   = $GLOBALS['post'] ?? null;

	// render_block() takes the postId context, which footnotes need, from the global post.
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored right after rendering.
	$content         = wptexturize( do_blocks( $content ) );
	$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the root page's post.

	if ( ! $has_blocks ) {
		$content = wpautop( $content );
	}

	return wp_filter_content_tags( strip_shortcode_tags( shortcode_unautop( $content ) ) );
}

/**
 * Renders comment content for display, the way `comment_text` would.
 *
 * @param string $content Raw comment content.
 * @return string Comment HTML.
 */
function get_comment_html( string $content ): string {
	return wpautop( force_balance_tags( make_clickable( wptexturize( $content ) ) ) );
}

/**
 * Removes shortcode tags but keeps what they enclose.
 *
 * Shortcodes are not run: most of a team blog's are not registered on the
 * root, and running the root's own in a switched context is unsafe. Code in
 * `<pre>` and `<code>` is left alone, since `items[i]` there is not a shortcode.
 *
 * @param string $content Content.
 * @return string Content without shortcode tags.
 */
function strip_shortcode_tags( string $content ): string {
	return (string) preg_replace_callback(
		'#<(pre|code)\b.*?</\1>|\[/?[a-z][\w-]*(?:\s[^\]]*)?\]#is',
		static fn( array $matches ): string => isset( $matches[1] ) ? $matches[0] : '',
		$content
	);
}
