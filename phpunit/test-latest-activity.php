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
	 * Team-blog shortcodes are unknown on the root, so their tags go while what they enclose stays.
	 */
	public function test_render_links_and_escapes_items(): void {
		$post_id    = $this->create_post(
			self::$core,
			array(
				'post_title'   => 'Ship <script>alert(1)</script> it',
				'post_content' => '<!-- wp:paragraph --><p>Release [team-only-shortcode id="1"]notes[/team-only-shortcode] are up.</p><!-- /wp:paragraph -->',
			)
		);
		$comment_id = $this->create_comment( self::$core, $post_id );
		$reply_id   = $this->create_comment( self::$core, $post_id, array( 'comment_parent' => $comment_id ) );

		$this->go_to( home_url( '/' ) );
		$html = render();

		$reply_link = preg_quote( esc_url( get_blog_permalink( self::$core, $post_id ) . '#comment-' . $reply_id ), '/' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '>Release notes are up.</p>', $html );
		$this->assertMatchesRegularExpression( '/__children">.*__comment-reply" href="' . $reply_link . '"/s', $html );
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
