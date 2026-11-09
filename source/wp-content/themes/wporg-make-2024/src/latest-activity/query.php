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
 * Deepest page served.
 *
 * Caps the rows each branch of the query reads, and the URLs crawlers can walk.
 *
 * @var int
 */
const MAX_PAGES = 10;

/**
 * Returns how many posts a page shows: the root site's "Blog pages show at most" setting.
 *
 * @return int Posts per page.
 */
function get_per_page(): int {
	return max( 1, (int) get_option( 'posts_per_page', 10 ) );
}

/**
 * Object cache group.
 *
 * @var string
 */
const CACHE_GROUP = 'wporg-make-latest-activity';

/**
 * How long a page's rows and rendered HTML are cached.
 *
 * Logged-in contributors bypass the page cache, so without this every one of
 * their front-page views would query every team blog.
 *
 * @var int
 */
const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

/**
 * Returns the team blogs whose activity is shown, keyed by blog ID.
 *
 * A blog is included only when it is listed: a published `make_site` post
 * points at it, or its path is one of the extras. Detecting private blogs
 * instead would fail open.
 *
 * @return array<int, array{slug: string, name: string, url: string, excluded_terms: int[]}> Allowed blogs.
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

	/**
	 * Filters the tags whose posts are left out, per team slug.
	 *
	 * Polyglots' locale and editor requests are routine and would crowd out
	 * everything else; the team's own front page hides them the same way.
	 *
	 * @param array<string, string[]> $excluded Tag slugs keyed by team slug.
	 */
	$excluded = (array) apply_filters(
		'wporg_make_latest_activity_excluded_tags',
		array(
			'polyglots' => array( 'editor-requests', 'request', 'clpte', 'locale-requests' ),
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
			'url'            => set_url_scheme( 'http://' . $site->domain . $site->path ),
			'excluded_terms' => get_tag_term_ids( $blog_id, (array) ( $excluded[ $label['slug'] ] ?? array() ) ),
		);
	}

	wp_cache_set( 'allowed-sites', $sites, CACHE_GROUP, 15 * MINUTE_IN_SECONDS );

	return $sites;
}

/**
 * Returns the term taxonomy IDs of the given tags on a blog.
 *
 * @param int      $blog_id Blog ID.
 * @param string[] $slugs   Tag slugs.
 * @return int[] Term taxonomy IDs; tags the blog doesn't have are left out.
 */
function get_tag_term_ids( int $blog_id, array $slugs ): array {
	global $wpdb;

	if ( ! $slugs ) {
		return array();
	}

	$prefix = $wpdb->get_blog_prefix( $blog_id );
	$ids    = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cached with the allowed blogs.
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The prefix comes from get_blog_prefix() for an integer blog ID.
			"SELECT tt.term_taxonomy_id FROM {$prefix}term_taxonomy tt INNER JOIN {$prefix}terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'post_tag' AND t.slug IN (" . implode( ',', array_fill( 0, count( $slugs ), '%s' ) ) . ')',
			array_values( $slugs )
		)
	);

	return array_map( 'intval', $ids );
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
 * Results are cached for CACHE_TTL, so the redirect check and the block share
 * one query and later requests skip it. A failed query is not cached.
 *
 * @param int $page Page number, 1 to MAX_PAGES.
 * @return array{rows: array<int, array{blog_id: int, post_id: int, date_gmt: string}>, has_older: bool, failed: bool} The page's rows, whether an older page exists, and whether the query failed.
 */
function get_activity_page( int $page ): array {
	$per_page = get_per_page();
	$key      = get_cache_key( 'rows', $page );
	$result   = wp_cache_get( $key, CACHE_GROUP );

	if ( ! is_array( $result ) ) {
		$rows = query_rows( get_allowed_sites(), $page, $per_page );
		if ( null === $rows ) {
			return array(
				'rows'      => array(),
				'has_older' => false,
				'failed'    => true,
			);
		}

		$result = array(
			'rows'      => array_slice( $rows, 0, $per_page ),
			'has_older' => count( $rows ) > $per_page && $page < MAX_PAGES,
			'failed'    => false,
		);
		wp_cache_set( $key, $result, CACHE_GROUP, CACHE_TTL );
	}

	return $result;
}

/**
 * Returns a cache key for one page, tied to the page size and the allowed blogs.
 *
 * A change to either makes a new key, so stale pages are never served after one.
 *
 * @param string $kind What is cached: "rows" or "items".
 * @param int    $page Page number.
 * @return string Cache key.
 */
function get_cache_key( string $kind, int $page ): string {
	return sprintf( '%1$s:%2$d:%3$d:%4$s', $kind, $page, get_per_page(), md5( implode( ',', array_keys( get_allowed_sites() ) ) ) );
}

/**
 * Forgets the allowed blogs, so a deleted, archived or unlisted blog drops out at once.
 */
function flush_allowed_sites(): void {
	wp_cache_delete( 'allowed-sites', CACHE_GROUP );
}
add_action( 'wp_delete_site', __NAMESPACE__ . '\flush_allowed_sites' );
add_action( 'wp_update_site', __NAMESPACE__ . '\flush_allowed_sites' );
add_action( 'save_post_make_site', __NAMESPACE__ . '\flush_allowed_sites' );

/**
 * Queries one page of posts across the given blogs, newest first.
 *
 * Each blog contributes one branch; the branches are combined with UNION ALL
 * and sorted once. Each branch returns as many rows as the page reaches into,
 * so no blog can come up short. One extra row tells whether an older page
 * exists.
 *
 * @param array<int, array{excluded_terms: int[]}> $sites    Blogs to read, keyed by blog ID, from get_allowed_sites().
 * @param int                                      $page     Page number.
 * @param int                                      $per_page Posts per page.
 * @return array<int, array{blog_id: int, post_id: int, date_gmt: string}>|null Up to $per_page + 1 rows, or null when the query failed.
 */
function query_rows( array $sites, int $page, int $per_page ): ?array {
	global $wpdb;

	if ( ! $sites ) {
		return array();
	}

	$offset     = ( $page - 1 ) * $per_page;
	$limit      = $offset + $per_page + 1;
	$xpost_like = $wpdb->esc_like( 'xpost-' ) . '%';
	$branches   = array();
	$args       = array();

	foreach ( $sites as $blog_id => $site ) {
		$blog_id = (int) $blog_id;
		$prefix  = $wpdb->get_blog_prefix( $blog_id );

		// Excluded tags are checked per post through the term_relationships primary key.
		$excluded_tags = '';
		if ( ! empty( $site['excluded_terms'] ) ) {
			$excluded_tags = "AND NOT EXISTS ( SELECT 1 FROM {$prefix}term_relationships tr WHERE tr.object_id = p.ID AND tr.term_taxonomy_id IN ( " . implode( ',', array_map( 'intval', $site['excluded_terms'] ) ) . ' ) )';
		}

		/*
		 * The plan is pinned: walk this blog's type_status_date index newest first
		 * (sorting by post_date, not post_date_gmt, is what allows that) and look up
		 * each author by primary key. Left to cost estimates, MariaDB can turn a
		 * users check into a join that starts from the network-wide users table.
		 */
		$branches[] = "( SELECT STRAIGHT_JOIN {$blog_id} AS blog_id, p.ID AS post_id, p.post_date_gmt AS date_gmt
			FROM {$prefix}posts p FORCE INDEX (type_status_date)
			INNER JOIN {$wpdb->users} u FORCE INDEX (PRIMARY) ON u.ID = p.post_author
			WHERE " . get_post_rules_sql( $prefix ) . '
				AND u.spam = 0
				AND u.deleted = 0
				' . $excluded_tags . '
			ORDER BY p.post_date DESC, p.ID DESC
			LIMIT %d )';
		array_push( $args, $xpost_like, $limit );
	}

	$sql = implode( ' UNION ALL ', $branches ) . ' ORDER BY date_gmt DESC, blog_id DESC, post_id DESC LIMIT %d OFFSET %d';
	array_push( $args, $per_page + 1, $offset );

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
		AND p.post_name NOT LIKE %s
		AND NOT EXISTS ( SELECT 1 FROM {$prefix}postmeta pm WHERE pm.post_id = p.ID AND pm.meta_key = '_xpost_original_permalink' )";
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

	// Prefer each team blog's own rendering; the local rendering stays for anything it doesn't return.
	$rendered = fetch_rendered_content( $loaded, $sites );
	foreach ( $loaded as $key => $item ) {
		$loaded[ $key ]['content'] = $rendered['posts'][ $key ] ?? $item['content'];

		foreach ( $item['comments'] as $index => $comment ) {
			$loaded[ $key ]['comments'][ $index ]['content'] = $rendered['comments'][ "{$item['blog_id']}:{$comment['id']}" ] ?? $comment['content'];
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
 * Fetches each team blog's own rendering of the given posts and comments from its REST API.
 *
 * Content shown on the root is rendered in the root's request, where the team blogs'
 * plugins (o2, Jetpack Mentions, the glossary, Trac links, the time shortcode) are not
 * loaded. The REST API runs the team blog's full `the_content` and `comment_text`
 * filters. All requests run in parallel; a failed or invalid response leaves those
 * items to the local rendering.
 *
 * @param array<string, array<string, mixed>> $loaded Posts from load_posts(), keyed by "blog:id".
 * @param array<int, array{url: string}>      $sites  Allowed blogs.
 * @return array{posts: array<string, string>, comments: array<string, string>} Rendered HTML keyed by "blog:id".
 */
function fetch_rendered_content( array $loaded, array $sites ): array {
	$rendered = array(
		'posts'    => array(),
		'comments' => array(),
	);

	$ids = array();
	foreach ( $loaded as $item ) {
		$ids[ $item['blog_id'] ]['posts'][] = $item['id'];
		foreach ( $item['comments'] as $comment ) {
			$ids[ $item['blog_id'] ]['comments'][] = $comment['id'];
		}
	}

	$requests = array();
	foreach ( $ids as $blog_id => $blog_ids ) {
		/**
		 * Filters the REST API base URL of a team blog, or turns off fetching with an empty string.
		 *
		 * @param string $base    REST base URL, ending in a slash.
		 * @param int    $blog_id Blog ID.
		 */
		$base = (string) apply_filters( 'wporg_make_latest_activity_rest_base', $sites[ $blog_id ]['url'] . 'wp-json/wp/v2/', $blog_id );
		if ( '' === $base ) {
			continue;
		}

		$chunks = array(
			'posts'    => array_chunk( $blog_ids['posts'], 100 ),
			'comments' => array_chunk( $blog_ids['comments'] ?? array(), 100 ),
		);
		foreach ( $chunks as $type => $type_chunks ) {
			foreach ( $type_chunks as $index => $chunk ) {
				$requests[ "{$type}:{$blog_id}:{$index}" ] = array(
					'url' => add_query_arg(
						array(
							'include'  => implode( ',', $chunk ),
							'per_page' => count( $chunk ),
							'_fields'  => 'id,content',
						),
						$base . $type
					),
				);
			}
		}
	}

	if ( ! $requests || ! class_exists( '\WpOrg\Requests\Requests' ) ) {
		return $rendered;
	}

	/**
	 * Filters the headers sent with the REST requests to the team blogs.
	 *
	 * @param array<string, string> $headers Request headers.
	 */
	$headers = (array) apply_filters( 'wporg_make_latest_activity_rest_headers', array() );
	foreach ( $requests as $key => $request ) {
		$requests[ $key ] += array(
			'headers' => $headers,
			'type'    => 'GET',
		);
	}

	try {
		$responses = \WpOrg\Requests\Requests::request_multiple(
			$requests,
			array(
				'timeout'         => 3,
				'connect_timeout' => 2,
			)
		);
	} catch ( \Exception $exception ) {
		return $rendered;
	}

	foreach ( $responses as $key => $response ) {
		if ( ! $response instanceof \WpOrg\Requests\Response || 200 !== $response->status_code ) {
			continue;
		}

		$data = json_decode( $response->body, true );
		if ( ! is_array( $data ) ) {
			continue;
		}

		list( $type, $blog_id ) = explode( ':', (string) $key );
		foreach ( $data as $row ) {
			if ( isset( $row['id'], $row['content']['rendered'] ) && is_string( $row['content']['rendered'] ) ) {
				$rendered[ $type ][ $blog_id . ':' . (int) $row['id'] ] = remove_o2_post_actions( $row['content']['rendered'] );
			}
		}
	}

	return $rendered;
}

/**
 * Removes o2's action bar from a team blog's rendered post.
 *
 * The o2 plugin appends it to the content, and the REST request is anonymous, so it always reads
 * "Login to Reply". The list renders its own Reply and Follow buttons for the viewer.
 *
 * @param string $html Rendered post HTML.
 * @return string HTML without o2's action bar.
 */
function remove_o2_post_actions( string $html ): string {
	// o2 prints the class in single quotes; match the element whatever the quoting.
	return (string) preg_replace( '#<nav\b[^>]*\bo2-post-footer-actions\b[^>]*>.*?</nav>#s', '', $html );
}

/**
 * Returns the names of the blocks in parsed block content, nested blocks included.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return string[] Block names.
 */
function get_block_names( array $blocks ): array {
	$names = array();
	foreach ( $blocks as $block ) {
		if ( ! empty( $block['blockName'] ) ) {
			$names[] = (string) $block['blockName'];
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$names = array_merge( $names, get_block_names( $block['innerBlocks'] ) );
		}
	}

	return array_values( array_unique( $names ) );
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
			'blog_id'         => $blog_id,
			'id'              => $post->ID,
			// o2 hides titles it generated from the first words of the content.
			'title_generated' => wp_trim_words( $post->post_content, 5 ) === $post->post_title,
			'blocks'          => get_block_names( parse_blocks( $post->post_content ) ),
			'user_id'         => (int) $post->post_author,
			'date_gmt'        => $post->post_date_gmt,
			'title'           => get_title( $post ),
			'content'         => get_post_html( $post ),
			'url'             => $url,
			'comments_open'   => comments_open( $post ),
			'comments'        => $post_comments,
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
			'post__in'       => $post_ids,
			'author__not_in' => array( 0 ),
			'status'         => 'approve',
			'type'           => 'comment',
			'orderby'        => array(
				'comment_date_gmt' => 'ASC',
				'comment_ID'       => 'ASC',
			),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by one page of posts.
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
	$content    = render_code_shortcodes( $post->post_content );
	$has_blocks = has_blocks( $content );
	$previous   = $GLOBALS['post'] ?? null;

	// render_block() takes the postId context, which footnotes need, from the global post.
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored right after rendering.
	$content         = wptexturize( do_blocks( $content ) );
	$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the root page's post.

	if ( ! $has_blocks ) {
		$content = wpautop( $content );
	}

	return link_mentions( wp_filter_content_tags( strip_shortcode_tags( shortcode_unautop( $content ) ) ) );
}

/**
 * Renders comment content for display, the way `comment_text` would.
 *
 * @param string $content Raw comment content.
 * @return string Comment HTML.
 */
function get_comment_html( string $content ): string {
	return link_mentions( wpautop( force_balance_tags( make_clickable( wptexturize( render_code_shortcodes( $content ) ) ) ) ) );
}

/**
 * Turns SyntaxHighlighter's code shortcodes into preformatted code.
 *
 * The plugin isn't loaded on the root, so this keeps code readable when a post or
 * comment falls back to the local rendering: one escaped `<pre><code>` block per
 * shortcode, which wptexturize, make_clickable and wpautop leave alone. The plugin
 * stores the code HTML-encoded, so it's decoded first.
 *
 * @param string $content Raw content.
 * @return string Content with code shortcodes replaced.
 */
function render_code_shortcodes( string $content ): string {
	if ( ! str_contains( $content, '[' ) ) {
		return $content;
	}

	$tags = 'code|sourcecode|php|js|javascript|jscript|css|html|xhtml|xml|bash|shell|sh|sql|python|py|ruby|plain|text|diff|patch|json|java|perl|yaml';

	return (string) preg_replace_callback(
		'#\[(' . $tags . ')(?:\s[^\]]*)?\](.*?)\[/\1\]#is',
		static fn( array $matches ): string => '<pre><code>' . esc_html( trim( html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5 ), "\r\n" ) ) . '</code></pre>',
		$content
	);
}

/**
 * Links @mentions of WordPress.org users to their profiles, with the markup Jetpack Mentions uses on o2.
 *
 * Only handles that belong to a user in good standing are linked. Text inside links, code and
 * preformatted blocks is left alone, as are handles that are part of a word or an email address.
 *
 * @param string $html Rendered HTML.
 * @return string HTML with mentions linked.
 */
function link_mentions( string $html ): string {
	if ( ! str_contains( $html, '@' ) ) {
		return $html;
	}

	$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		return $html;
	}

	// Handles start after the start of the text, whitespace or opening punctuation.
	$pattern = '/(?<=^|[\s(\[{>"\'])@([a-z0-9][a-z0-9_.-]*[a-z0-9_]|[a-z0-9])/i';
	$skip    = 0;
	$texts   = array();

	foreach ( $parts as $index => $part ) {
		if ( '' !== $part && '<' === $part[0] ) {
			if ( preg_match( '#^<(/?)(a|code|pre|script|style)\b#i', $part, $tag ) ) {
				$skip = max( 0, $skip + ( '/' === $tag[1] ? -1 : 1 ) );
			}
		} elseif ( ! $skip && str_contains( $part, '@' ) ) {
			$texts[] = $index;
		}
	}

	$handles = array();
	foreach ( $texts as $index ) {
		if ( preg_match_all( $pattern, $parts[ $index ], $matches ) ) {
			$handles = array_merge( $handles, $matches[1] );
		}
	}

	$users = get_mentionable_users( $handles );
	if ( ! $users ) {
		return $html;
	}

	foreach ( $texts as $index ) {
		$parts[ $index ] = (string) preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $users ): string {
				$login = strtolower( $matches[1] );
				if ( ! isset( $users[ $login ] ) ) {
					return $matches[0];
				}

				return sprintf(
					'<a href="%1$s" class="mention"><span class="mentions-prefix">@</span>%2$s</a>',
					esc_url( 'https://profiles.wordpress.org/' . $users[ $login ] . '/' ),
					esc_html( $matches[1] )
				);
			},
			$parts[ $index ]
		);
	}

	return implode( '', $parts );
}

/**
 * Looks up which handles belong to users in good standing.
 *
 * @param string[] $handles Mentioned handles.
 * @return array<string, string> Profile slugs (user_nicename), keyed by lowercase login.
 */
function get_mentionable_users( array $handles ): array {
	global $wpdb;

	$handles = array_values( array_unique( array_map( 'strtolower', $handles ) ) );
	if ( ! $handles ) {
		return array();
	}

	// One query on the indexed user_login column for all handles in this content.
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Rendered content is cached with the page's items.
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The users table name comes from $wpdb.
			"SELECT user_login, user_nicename FROM {$wpdb->users} WHERE user_login IN (" . implode( ',', array_fill( 0, count( $handles ), '%s' ) ) . ') AND spam = 0 AND deleted = 0',
			$handles
		)
	);

	$users = array();
	foreach ( (array) $rows as $row ) {
		$users[ strtolower( $row->user_login ) ] = $row->user_nicename;
	}

	return $users;
}

/**
 * Removes shortcode tags but keeps what they enclose.
 *
 * Shortcodes are not run: most of a team blog's are not registered on the
 * root, and running the root's own in a switched context is unsafe. Only known
 * shortcode names are removed, so bracketed words like "[Proposal]" stay, and
 * `[[name]]` escapes turn into the literal `[name]` as core shows them. Code in
 * `<pre>` and `<code>` is left alone.
 *
 * @param string $content Content.
 * @return string Content without shortcode tags.
 */
function strip_shortcode_tags( string $content ): string {
	global $shortcode_tags;

	/**
	 * Filters the shortcode names removed from team-blog content, on top of those registered on the root.
	 *
	 * @param string[] $names Shortcode names.
	 */
	$names = (array) apply_filters(
		'wporg_make_latest_activity_shortcodes',
		array( 'youtube', 'vimeo', 'gist', 'tweet', 'slideshare', 'polldaddy', 'crowdsignal' )
	);
	$names = array_filter( array_unique( array_merge( array_keys( $shortcode_tags ), $names ) ), 'is_string' );
	if ( ! $names ) {
		return $content;
	}

	$regex = '/' . get_shortcode_regex( $names ) . '/';

	$parts = preg_split( '#(<(pre|code)\b.*?</\2>)#is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		return $content;
	}

	$output = '';
	for ( $index = 0, $count = count( $parts ); $index < $count; $index += 3 ) {
		$output .= (string) preg_replace_callback(
			$regex,
			// Groups 1 and 6 are the extra brackets of an escaped shortcode; group 5 is the enclosed content.
			static fn( array $matches ): string => '[' === $matches[1] && ']' === $matches[6] ? substr( $matches[0], 1, -1 ) : $matches[5],
			$parts[ $index ]
		);
		$output .= $parts[ $index + 1 ] ?? '';
	}

	return $output;
}
