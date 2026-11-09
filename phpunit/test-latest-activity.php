<?php
/**
 * Tests for the Latest activity block.
 *
 * @package WordPressdotorg\Theme\Make_2024
 */

declare( strict_types = 1 );

use function WordPressdotorg\Theme\Make_2024\Latest_Activity\get_activity_page;
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\get_allowed_sites;
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\get_items;
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\get_redirect_url;
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\remove_o2_post_actions;
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\render;

/**
 * Covers which blogs, posts and comments appear, paging across blogs, and the rendered markup.
 *
 * @group latest-activity
 */
class Test_Latest_Activity extends WP_UnitTestCase {

	/**
	 * Team blog with a `make_site` post.
	 *
	 * @var int
	 */
	protected static int $core;

	/**
	 * Second team blog with a `make_site` post.
	 *
	 * @var int
	 */
	protected static int $design;

	/**
	 * Blog included through the extras, without a `make_site` post.
	 *
	 * @var int
	 */
	protected static int $updates;

	/**
	 * Public blog that nothing lists.
	 *
	 * @var int
	 */
	protected static int $unlisted;

	/**
	 * Listed blog locked to its members.
	 *
	 * @var int
	 */
	protected static int $members_only;

	/**
	 * Listed blog that is archived.
	 *
	 * @var int
	 */
	protected static int $archived;

	/**
	 * Author of the test content.
	 *
	 * @var int
	 */
	protected static int $author;

	/**
	 * Creates the blogs once; creating tables inside a test would commit its transaction.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$core         = $factory->blog->create( array( 'path' => '/core/' ) );
		self::$design       = $factory->blog->create( array( 'path' => '/design/' ) );
		self::$updates      = $factory->blog->create( array( 'path' => '/updates/' ) );
		self::$unlisted     = $factory->blog->create( array( 'path' => '/unlisted/' ) );
		self::$members_only = $factory->blog->create( array( 'path' => '/members-only/' ) );
		self::$archived     = $factory->blog->create( array( 'path' => '/archived/' ) );
		self::$author       = $factory->user->create();

		$listed = array(
			'core'         => self::$core,
			'design'       => self::$design,
			'members-only' => self::$members_only,
			'archived'     => self::$archived,
		);
		foreach ( $listed as $slug => $blog_id ) {
			$make_site = $factory->post->create(
				array(
					'post_type'  => 'make_site',
					'post_name'  => $slug,
					'post_title' => ucfirst( $slug ),
				)
			);
			update_post_meta( $make_site, 'make_site_id', $blog_id );
		}

		update_blog_option( self::$members_only, 'active_plugins', array( 'subscribers-only.php' ) );
		wp_update_site( self::$archived, array( 'archived' => 1 ) );
	}

	/**
	 * Deletes the blogs and their tables.
	 */
	public static function wpTearDownAfterClass(): void {
		foreach ( array( self::$core, self::$design, self::$updates, self::$unlisted, self::$members_only, self::$archived ) as $blog_id ) {
			wp_delete_site( $blog_id );
		}
	}

	/**
	 * Only listed, public, open blogs are read. Including any public blog would put private team content on the homepage.
	 */
	public function test_allowed_sites_are_listed_open_blogs_only(): void {
		$sites = get_allowed_sites();

		$expected = array( self::$core, self::$design, self::$updates );
		$actual   = array_keys( $sites );
		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual );
		$this->assertSame( 'core', $sites[ self::$core ]['slug'] );
		$this->assertSame( 'Team Updates', $sites[ self::$updates ]['name'] );
	}

	/**
	 * A blog that is archived drops out at once instead of staying listed until the cache expires.
	 *
	 * A deleted blog would otherwise stay in the query, and its dropped tables would fail it for every blog.
	 */
	public function test_allowed_sites_forget_a_changed_site(): void {
		$this->assertArrayHasKey( self::$design, get_allowed_sites() );

		wp_update_site( self::$design, array( 'archived' => 1 ) );

		$this->assertArrayNotHasKey( self::$design, get_allowed_sites() );
	}

	/**
	 * Each eligibility rule keeps its kind of content out, while eligible content still shows.
	 *
	 * @dataProvider data_ineligible_content
	 *
	 * @param string $kind Kind of ineligible content to create.
	 */
	public function test_excludes_ineligible_content( string $kind ): void {
		$control         = $this->create_post( self::$core );
		$control_comment = $this->create_comment( self::$core, $control );

		switch ( $kind ) {
			case 'private post':
				$ineligible = 'post:' . $this->create_post( self::$core, array( 'post_status' => 'private' ) );
				break;

			case 'password-protected post':
				$ineligible = 'post:' . $this->create_post( self::$core, array( 'post_password' => 'secret' ) );
				break;

			case 'xpost':
				$post_id = $this->create_post( self::$core );
				switch_to_blog( self::$core );
				add_post_meta( $post_id, '_xpost_original_permalink', 'https://example.org/design/original/' );
				restore_current_blog();
				$ineligible = 'post:' . $post_id;
				break;

			case 'post by a spam author':
				$spammer = self::factory()->user->create();
				$this->mark_user_as_spam( $spammer );
				$ineligible = 'post:' . $this->create_post( self::$core, array( 'post_author' => $spammer ) );
				break;

			case 'unapproved comment':
				$ineligible = 'comment:' . $this->create_comment( self::$core, $control, array( 'comment_approved' => '0' ) );
				break;

			case 'pingback':
				$ineligible = 'comment:' . $this->create_comment( self::$core, $control, array( 'comment_type' => 'pingback' ) );
				break;

			case 'xcomment':
				$comment_id = $this->create_comment( self::$core, $control );
				switch_to_blog( self::$core );
				add_comment_meta( $comment_id, 'xcomment_origin', self::$design . ':1' );
				restore_current_blog();
				$ineligible = 'comment:' . $comment_id;
				break;

			case 'comment by a spam author':
				$spammer = self::factory()->user->create();
				$this->mark_user_as_spam( $spammer );
				$ineligible = 'comment:' . $this->create_comment( self::$core, $control, array( 'user_id' => $spammer ) );
				break;
		}

		$shown = array();
		foreach ( get_items( get_activity_page( 1 )['rows'], get_allowed_sites() ) as $item ) {
			$shown[] = 'post:' . $item['id'];
			foreach ( $item['comments'] as $comment ) {
				$shown[] = 'comment:' . $comment['id'];
			}
		}

		$this->assertContains( 'post:' . $control, $shown );
		$this->assertContains( 'comment:' . $control_comment, $shown );
		$this->assertNotContains( $ineligible, $shown );
	}

	/**
	 * Data provider: one case per eligibility rule.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_ineligible_content(): array {
		return array(
			'private post'             => array( 'private post' ),
			'password-protected post'  => array( 'password-protected post' ),
			'xpost'                    => array( 'xpost' ),
			'post by a spam author'    => array( 'post by a spam author' ),
			'unapproved comment'       => array( 'unapproved comment' ),
			'pingback'                 => array( 'pingback' ),
			'xcomment'                 => array( 'xcomment' ),
			'comment by a spam author' => array( 'comment by a spam author' ),
		);
	}

	/**
	 * Posts with a team's excluded tags stay out, as Polyglots' locale requests do, while its other posts show.
	 */
	public function test_excludes_posts_with_a_teams_excluded_tags(): void {
		$exclude = static fn(): array => array( 'core' => array( 'locale-requests' ) );
		add_filter( 'wporg_make_latest_activity_excluded_tags', $exclude );

		$shown   = $this->create_post( self::$core );
		$request = $this->create_post( self::$core );
		switch_to_blog( self::$core );
		wp_set_post_tags( $request, array( 'locale-requests' ) );
		restore_current_blog();

		$keys = $this->get_keys( get_activity_page( 1 )['rows'] );
		remove_filter( 'wporg_make_latest_activity_excluded_tags', $exclude );

		$this->assertContains( self::$core . ':' . $shown, $keys );
		$this->assertNotContains( self::$core . ':' . $request, $keys );
	}

	/**
	 * Consecutive pages cover every post exactly once, in date order, even when one blog is much busier.
	 *
	 * If a branch returned only one page's worth of rows instead of offset plus a page, page 2 would
	 * lose the busy blog's older posts.
	 */
	public function test_pages_merge_blogs_without_gaps_or_repeats(): void {
		$now      = time();
		$expected = array();

		$newest     = $this->create_post( self::$design, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now - 30 * MINUTE_IN_SECONDS ) ) );
		$quiet_post = $this->create_post( self::$design, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now - 40 * HOUR_IN_SECONDS ) ) );
		$expected[] = self::$design . ':' . $newest;

		for ( $hours = 1; $hours <= 30; $hours++ ) {
			$post_id    = $this->create_post( self::$core, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now - $hours * HOUR_IN_SECONDS ) ) );
			$expected[] = self::$core . ':' . $post_id;
		}

		$expected[] = self::$design . ':' . $quiet_post;

		// 32 posts at 10 per page: three full pages and a fourth with two.
		update_option( 'posts_per_page', 10 );

		$shown = array();
		$pages = 0;
		do {
			$result = get_activity_page( ++$pages );
			$shown  = array_merge( $shown, $this->get_keys( $result['rows'] ) );
		} while ( $result['has_older'] );

		$this->assertSame( $expected, $shown );
		$this->assertSame( 4, $pages );
	}

	/**
	 * A range renders every page in it, and Load more links to the next page and to the range extended by it.
	 *
	 * Without the range, Load more would swap in only the next page and drop the posts already shown.
	 */
	public function test_range_renders_every_page_and_extends_with_load_more(): void {
		update_option( 'posts_per_page', 10 );
		for ( $i = 0; $i < 25; $i++ ) {
			$this->create_post( self::$core );
		}

		$this->go_to( home_url( '/?activity-page=2&activity-from=1' ) );
		$html = render();

		$this->assertSame( 20, substr_count( $html, '<article class="wp-block-wporg-make-latest-activity__item ' ) );
		$this->assertStringNotContainsString( '__newer', $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/?activity-page=3#latest-activity' ) ) . '"', $html );
		$this->assertStringContainsString(
			wp_interactivity_data_wp_context(
				array(
					'href'      => home_url( '/?activity-page=3&activity-from=1' ),
					'isLoading' => false,
				)
			),
			$html
		);
	}

	/**
	 * Requests for pages that cannot be served redirect instead of being clamped, which would cache duplicates.
	 *
	 * Only pages that can never be served redirect permanently; browsers keep a 301.
	 *
	 * @dataProvider data_redirects
	 *
	 * @param string   $value  Value of the page parameter.
	 * @param int      $posts  Number of posts to create.
	 * @param int|null $status Expected redirect status, or null for no redirect.
	 */
	public function test_redirects_pages_that_cannot_be_served( string $value, int $posts, ?int $status ): void {
		for ( $i = 0; $i < $posts; $i++ ) {
			$this->create_post( self::$core );
		}

		$this->go_to( home_url( '/?activity-page=' . $value ) );

		$expected = null;
		if ( null !== $status ) {
			$expected = array(
				'url'    => '/',
				'status' => $status,
			);
		}

		$this->assertSame( $expected, get_redirect_url() );
	}

	/**
	 * Data provider: page parameter values.
	 *
	 * @return array<string, array{string, int, int|null}>
	 */
	public function data_redirects(): array {
		return array(
			'page 2 with content'         => array( '2', 21, null ),
			'page 2 past the end for now' => array( '2', 1, 302 ),
			'page 1, a duplicate'         => array( '1', 1, 301 ),
			'above the page cap'          => array( '11', 1, 301 ),
			'not written plainly'         => array( '02', 1, 301 ),
		);
	}

	/**
	 * Posts render their content as the team blog would, titles are escaped, and replies nest under their
	 * comment with a Reply link to that reply on the team blog.
	 *
	 * Known shortcodes lose their tags but keep what they enclose; bracketed words that aren't shortcodes stay.
	 */
	public function test_render_links_and_escapes_items(): void {
		$post_id    = $this->create_post(
			self::$core,
			array(
				'post_title'   => 'Ship <script>alert(1)</script> it',
				'post_content' => '<!-- wp:paragraph --><p>[Proposal] Release [caption id="1"]notes[/caption] are up.</p><!-- /wp:paragraph -->',
			)
		);
		$comment_id = $this->create_comment( self::$core, $post_id );
		$reply_id   = $this->create_comment( self::$core, $post_id, array( 'comment_parent' => $comment_id ) );

		$this->go_to( home_url( '/' ) );
		$html = render();

		$reply_link = preg_quote( esc_url( get_blog_permalink( self::$core, $post_id ) . '#comment-' . $reply_id ), '/' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '>[Proposal] Release notes are up.</p>', $html );
		$this->assertMatchesRegularExpression( '/__children">.*__comment-reply" href="' . $reply_link . '"/s', $html );
	}

	/**
	 * Paged views are noindex, follow, while the front page itself stays indexable.
	 *
	 * Without it, up to nine extra URLs would be indexed with the same posts as their team blogs.
	 */
	public function test_paged_views_are_noindexed(): void {
		$this->create_post( self::$core );

		$this->go_to( home_url( '/' ) );
		$this->assertArrayNotHasKey( 'noindex', apply_filters( 'wp_robots', array() ) );

		$this->go_to( home_url( '/?activity-page=2' ) );
		$this->assertSame(
			array(
				'noindex' => true,
				'follow'  => true,
			),
			array_intersect_key( apply_filters( 'wp_robots', array() ), array_flip( array( 'noindex', 'follow' ) ) )
		);
	}

	/**
	 * The "Login to Reply" and To-do bar is removed from team-blog HTML as o2 prints it, while o2's tag line stays.
	 */
	public function test_removes_o2_post_actions(): void {
		// Markup as o2's modules/post-actions/load.php prints it, single quotes included.
		$html = "<p>Body</p><nav class='o2-post-footer-actions'><ul class='o2-post-footer-action-row'><li class='o2-post-footer-action'><a href='#'>Login to Reply</a></li></ul><div class='o2-post-footer-action-likes'></div><ul class='o2-post-footer-action-row'><li class='o2-post-footer-action'><span class=\"o2-resolve-wrap\"><a href=\"#\" class=\"o2-resolve-link\" data-action=\"resolvedposts\">To-do</a><ul></ul></span></li></ul></nav><p class=\"o2-appended-tags\">#core</p>";

		$this->assertSame( '<p>Body</p><p class="o2-appended-tags">#core</p>', remove_o2_post_actions( $html ) );
	}

	/**
	 * SyntaxHighlighter code shortcodes show as preformatted code when rendered locally, not as raw shortcode text.
	 */
	public function test_renders_code_shortcodes_as_code(): void {
		$post_id = $this->create_post( self::$core );
		$this->create_comment( self::$core, $post_id, array( 'comment_content' => "Try this:\n\n[code]add_action( &#039;init&#039;, &#039;x&#039; );[/code]" ) );

		$this->go_to( home_url( '/' ) );

		$this->assertStringContainsString( '<pre><code>add_action( &#039;init&#039;, &#039;x&#039; );</code></pre>', render() );
	}

	/**
	 * @mentions of real users link to their profiles with Jetpack Mentions' markup; anything else stays as typed.
	 */
	public function test_links_mentions_of_users(): void {
		self::factory()->user->create( array( 'user_login' => 'jdoe' ) );
		$this->create_post(
			self::$core,
			array( 'post_content' => '<p>Props @jdoe, @nobody-here and mail@jdoe.example for <code>@jdoe</code>.</p>' )
		);

		$this->go_to( home_url( '/' ) );
		$html = render();

		$this->assertStringContainsString( 'Props <a href="https://profiles.wordpress.org/jdoe/" class="mention"><span class="mentions-prefix">@</span>jdoe</a>, @nobody-here and mail@jdoe.example for <code>@jdoe</code>.', $html );
	}

	/**
	 * Follow uses o2's own link, with its query arguments and nonce, and only for logged-in viewers as on o2.
	 *
	 * A link without the nonce, or one pointing at the root, would do nothing on the team blog.
	 */
	public function test_follow_link_matches_o2(): void {
		$post_id = $this->create_post( self::$core );

		$this->go_to( home_url( '/' ) );
		$this->assertStringNotContainsString( 'post-comment-subscribe', render() );

		wp_set_current_user( self::$author );
		$expected = wp_nonce_url(
			add_query_arg(
				array(
					'post-id' => $post_id,
					'action'  => 'post-comment-subscribe',
				),
				get_allowed_sites()[ self::$core ]['url']
			),
			'post-comment-subscribe-' . $post_id
		);

		$this->assertStringContainsString( 'href="' . esc_url( $expected ) . '"', render() );
	}

	/**
	 * Creates a published post on a blog.
	 *
	 * @param int                  $blog_id Blog ID.
	 * @param array<string, mixed> $args    Post arguments.
	 * @return int Post ID.
	 */
	private function create_post( int $blog_id, array $args = array() ): int {
		switch_to_blog( $blog_id );

		try {
			return self::factory()->post->create( $args + array( 'post_author' => self::$author ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Creates an approved comment by the test author on a blog.
	 *
	 * @param int                  $blog_id Blog ID.
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $args    Comment arguments.
	 * @return int Comment ID.
	 */
	private function create_comment( int $blog_id, int $post_id, array $args = array() ): int {
		switch_to_blog( $blog_id );

		try {
			return self::factory()->comment->create(
				$args + array(
					'comment_post_ID'  => $post_id,
					'user_id'          => self::$author,
					'comment_approved' => '1',
					'comment_type'     => 'comment',
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Flags a user as spam on the network.
	 *
	 * @param int $user_id User ID.
	 */
	private function mark_user_as_spam( int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Core has no non-deprecated API for flagging a network user as spam.
		$wpdb->update( $wpdb->users, array( 'spam' => 1 ), array( 'ID' => $user_id ) );
		clean_user_cache( $user_id );
	}

	/**
	 * Turns post rows into "blog:id" keys.
	 *
	 * @param array<int, array<string, mixed>> $rows Post rows.
	 * @return string[] Keys in row order.
	 */
	private function get_keys( array $rows ): array {
		return array_map(
			static fn( array $row ): string => "{$row['blog_id']}:{$row['post_id']}",
			$rows
		);
	}
}
