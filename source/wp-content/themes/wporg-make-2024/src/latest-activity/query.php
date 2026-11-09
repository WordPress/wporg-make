<?php
/**
 * Data access for the Latest activity block.
 *
 * Reads the newest posts and comments from every allowed team blog on the
 * network in a single query.
 *
 * @package WordPressdotorg\Theme\Make_2024
 */

declare( strict_types = 1 );

namespace WordPressdotorg\Theme\Make_2024\Latest_Activity;

use WP_Post;

/**
 * Items shown per page.
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
 * @return array{rows: array<int, array{blog_id: int, type: string, object_id: int, date_gmt: string}>, has_older: bool, failed: bool} The page's rows, whether an older page exists, and whether the query failed.
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
 * Queries one page of activity rows across the given blogs.
 *
 * Each blog contributes two branches, posts and comments, which are combined
 * with UNION ALL and sorted once. Each branch returns as many rows as the page
 * reaches into, so no blog can come up short. One extra row tells whether an
 * older page exists.
 *
 * @param int[] $blog_ids Blog IDs to read.
 * @param int   $page     Page number.
 * @return array<int, array{blog_id: int, type: string, object_id: int, date_gmt: string}>|null Up to PER_PAGE + 1 rows, or null when the query failed.
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
		$blog_id    = (int) $blog_id;
		$prefix     = $wpdb->get_blog_prefix( $blog_id );
		$post_rules = get_post_rules_sql( $prefix );

		// Sorting by post_date, not post_date_gmt, lets MySQL walk the type_status_date index.
		$branches[] = "( SELECT {$blog_id} AS blog_id, 'post' AS type, p.ID AS object_id, p.post_date_gmt AS date_gmt
			FROM {$prefix}posts p
			WHERE {$post_rules} AND " . get_active_user_sql( 'p.post_author' ) . '
			ORDER BY p.post_date DESC, p.ID DESC
			LIMIT %d )';
		array_push( $args, $xpost_like, $limit );

		$branches[] = "( SELECT {$blog_id}, 'comment', c.comment_ID, c.comment_date_gmt
			FROM {$prefix}comments c
			INNER JOIN {$prefix}posts p ON p.ID = c.comment_post_ID
			WHERE c.comment_approved = '1'
				AND c.comment_type = 'comment'
				AND c.user_id > 0
				AND NOT EXISTS ( SELECT 1 FROM {$prefix}commentmeta cm WHERE cm.comment_id = c.comment_ID AND cm.meta_key = 'xcomment_origin' )
				AND " . get_active_user_sql( 'c.user_id' ) . "
				AND {$post_rules}
			ORDER BY c.comment_date_gmt DESC, c.comment_ID DESC
			LIMIT %d )";
		array_push( $args, $xpost_like, $limit );
	}

	$sql = implode( ' UNION ALL ', $branches ) . ' ORDER BY date_gmt DESC, blog_id DESC, type DESC, object_id DESC LIMIT %d OFFSET %d';
	array_push( $args, PER_PAGE + 1, $offset );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names come from get_blog_prefix() for integer blog IDs; every value is a placeholder.
	$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

	if ( ! is_array( $results ) || '' !== $wpdb->last_error ) {
		return null;
	}

	return array_map(
		static fn( array $row ): array => array(
			'blog_id'   => (int) $row['blog_id'],
			'type'      => (string) $row['type'],
			'object_id' => (int) $row['object_id'],
			'date_gmt'  => (string) $row['date_gmt'],
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
 * Loads display data for activity rows, switching into each blog once.
 *
 * Rows whose post or comment can no longer be loaded are left out.
 *
 * @param array<int, array{blog_id: int, type: string, object_id: int, date_gmt: string}> $rows  Activity rows.
 * @param array<int, array{slug: string, name: string, url: string}>                      $sites Allowed blogs.
 * @return array<int, array<string, mixed>> Items in row order.
 */
function get_items( array $rows, array $sites ): array {
	$ids = array();
	foreach ( $rows as $row ) {
		$ids[ $row['blog_id'] ][ $row['type'] ][] = $row['object_id'];
	}

	$loaded = array();
	foreach ( $ids as $blog_id => $blog_ids ) {
		switch_to_blog( $blog_id );

		// finally guarantees the restore; an unrestored switch leaks into the rest of the page.
		try {
			$loaded += load_items( $blog_id, $blog_ids['post'] ?? array(), $blog_ids['comment'] ?? array() );
		} finally {
			restore_current_blog();
		}
	}

	$items = array();
	foreach ( $rows as $row ) {
		$key = "{$row['blog_id']}:{$row['type']}:{$row['object_id']}";
		if ( isset( $loaded[ $key ], $sites[ $row['blog_id'] ] ) ) {
			$items[] = $loaded[ $key ] + array( 'team' => $sites[ $row['blog_id'] ] );
		}
	}

	return $items;
}

/**
 * Loads posts and comments from the current blog.
 *
 * @param int   $blog_id     Current blog ID, used in the keys.
 * @param int[] $post_ids    Post IDs.
 * @param int[] $comment_ids Comment IDs.
 * @return array<string, array<string, mixed>> Items keyed by "blog:type:id".
 */
function load_items( int $blog_id, array $post_ids, array $comment_ids ): array {
	$items = array();

	if ( $post_ids ) {
		$posts = get_posts(
			array(
				'include'     => $post_ids,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => count( $post_ids ),
			)
		);

		foreach ( $posts as $post ) {
			$url = get_permalink( $post );
			if ( ! $url ) {
				continue;
			}

			$items[ "{$blog_id}:post:{$post->ID}" ] = array(
				'type'     => 'post',
				'user_id'  => (int) $post->post_author,
				'date_gmt' => $post->post_date_gmt,
				'title'    => get_title( $post ),
				'content'  => get_post_html( $post->post_content ),
				'url'      => $url,
			);
		}
	}

	if ( $comment_ids ) {
		$comments = get_comments(
			array(
				'comment__in'               => $comment_ids,
				'status'                    => 'approve',
				'type'                      => 'comment',
				'number'                    => count( $comment_ids ),
				'update_comment_post_cache' => true,
			)
		);

		foreach ( $comments as $comment ) {
			$post = get_post( (int) $comment->comment_post_ID );
			$url  = $post ? get_permalink( $post ) : false;
			if ( ! $url ) {
				continue;
			}

			// Built by hand: get_comment_link() would read comment paging options that o2 overrides only on its own blogs.
			$items[ "{$blog_id}:comment:{$comment->comment_ID}" ] = array(
				'type'     => 'comment',
				'user_id'  => (int) $comment->user_id,
				'date_gmt' => $comment->comment_date_gmt,
				'title'    => get_title( $post ),
				'content'  => get_comment_html( $comment->comment_content ),
				'url'      => $url . '#comment-' . $comment->comment_ID,
			);
		}
	}

	return $items;
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
 * keyed to the wrong blog's posts. Blocks render in the team blog's context.
 *
 * @param string $content Raw post content.
 * @return string Post HTML.
 */
function get_post_html( string $content ): string {
	$has_blocks = has_blocks( $content );
	$content    = wptexturize( do_blocks( $content ) );

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
	return wpautop( make_clickable( wptexturize( $content ) ) );
}

/**
 * Removes shortcode tags but keeps what they enclose.
 *
 * Shortcodes are not run: most of a team blog's are not registered on the
 * root, and running the root's own in a switched context is unsafe.
 *
 * @param string $content Content.
 * @return string Content without shortcode tags.
 */
function strip_shortcode_tags( string $content ): string {
	return (string) preg_replace( '/\[\/?[a-z][\w-]*(?:\s[^\]]*)?\]/i', '', $content );
}
