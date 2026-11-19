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
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\get_reading_minutes;
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\get_redirect_url;
use function WordPressdotorg\Theme\Make_2024\Latest_Activity\render;

/**
 * Covers which blogs and posts appear, paging across blogs, and the rendered list.
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
		$control = $this->create_post( self::$core );

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

		}

		$shown = array();
		foreach ( get_items( get_activity_page( 1 )['rows'], get_allowed_sites() ) as $item ) {
			$shown[] = 'post:' . $item['id'];
		}

		$this->assertContains( 'post:' . $control, $shown );
		$this->assertNotContains( $ineligible, $shown );
	}

	/**
	 * Data provider: one case per eligibility rule.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_ineligible_content(): array {
		return array(
			'private post'            => array( 'private post' ),
			'password-protected post' => array( 'password-protected post' ),
			'xpost'                   => array( 'xpost' ),
			'post by a spam author'   => array( 'post by a spam author' ),
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

		$this->assertSame( 20, substr_count( $html, '<article class="wp-block-wporg-make-latest-activity__item' ) );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/?activity-page=3#latest-activity' ) ) . '"', $html );
		$this->assertStringContainsString( 'data-range="' . esc_url( home_url( '/?activity-page=3&activity-from=1' ) ) . '"', $html );
	}

	/**
	 * A post pushed onto the next page by one published after the first page was cached shows once in the range.
	 */
	public function test_range_shows_a_post_once_across_pages_cached_apart(): void {
		update_option( 'posts_per_page', 10 );
		for ( $i = 0; $i < 15; $i++ ) {
			$this->create_post( self::$core, array( 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) + $i ) ) );
		}

		$this->go_to( home_url( '/' ) );
		render();
		$this->create_post( self::$core );

		// Not go_to(), which flushes the object cache and with it the cached first page.
		$_GET['activity-page'] = '2';
		$_GET['activity-from'] = '1';
		$html                  = render();
		unset( $_GET['activity-page'], $_GET['activity-from'] );
		preg_match_all( '/__entry"[^>]*data-wp-key="([^"]+)"/', $html, $keys );

		$this->assertSame( 15, count( array_unique( $keys[1] ) ) );
		$this->assertSame( array_values( array_unique( $keys[1] ) ), $keys[1] );
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
	 * Rows link the post and its team, escape the title, and summarize the content as plain text.
	 *
	 * Known shortcodes lose their tags but keep what they enclose; bracketed words that aren't shortcodes stay.
	 * Code is left out of the summary.
	 */
	public function test_render_links_and_escapes_items(): void {
		$post_id = $this->create_post(
			self::$core,
			array(
				'post_title'   => 'Ship <script>alert(1)</script> it',
				'post_content' => '<!-- wp:paragraph --><p>[Proposal] Release [caption id="1"]notes[/caption] are up.</p><!-- /wp:paragraph --><!-- wp:code --><pre class="wp-block-code"><code>secret_code();</code></pre><!-- /wp:code -->',
			)
		);

		$this->go_to( home_url( '/' ) );
		$html = render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_blog_permalink( self::$core, $post_id ) ) . '"', $html );
		$this->assertStringContainsString( '__team" href="' . esc_url( get_allowed_sites()[ self::$core ]['url'] ) . '">Core</a>', $html );
		$this->assertStringContainsString( '__summary">[Proposal] Release notes are up.</p>', $html );
		$this->assertStringNotContainsString( 'secret_code', $html );
	}

	/**
	 * Labels come from tags, categories and titles, and every post keeps its summary.
	 *
	 * @dataProvider data_post_kinds
	 *
	 * @param string   $blog    "core" or "updates".
	 * @param string   $title   Post title.
	 * @param string[] $tags    Tag slugs.
	 * @param string   $label   Expected label.
	 */
	public function test_labels_posts_by_kind( string $blog, string $title, array $tags, string $label ): void {
		$blog_id = 'updates' === $blog ? self::$updates : self::$core;
		$post_id = $this->create_post( $blog_id, array( 'post_title' => $title ) );
		switch_to_blog( $blog_id );
		wp_set_post_tags( $post_id, $tags );
		restore_current_blog();

		$items = get_items( get_activity_page( 1 )['rows'], get_allowed_sites() );
		$item  = current( wp_list_filter( $items, array( 'id' => $post_id ) ) );

		$this->assertSame( $label, $item['label'] );
		$this->assertNotSame( '', $item['summary'] );
	}

	/**
	 * Data provider: posts of each kind.
	 *
	 * @return array<string, array{string, string, string[], string}>
	 */
	public function data_post_kinds(): array {
		return array(
			'dev note by tag'             => array( 'core', 'Media changes in 7.2', array( 'dev-notes' ), 'Dev note' ),
			'call for testing by title'   => array( 'core', 'Call for testing: Xdebug', array(), 'Call for testing' ),
			'label wins over meeting tag' => array( 'core', 'Help Test WordPress 7.2', array( 'meetings' ), 'Call for testing' ),
			'agenda by tag'               => array( 'core', 'Dev Chat, November 17', array( 'agenda' ), 'Agenda' ),
			'meeting notes by title'      => array( 'core', 'Training Team Meeting Recap', array(), 'Meeting notes' ),
			'summary outside a meeting'   => array( 'core', 'Summary of the survey', array(), '' ),
			'team update by blog'         => array( 'updates', 'Themes team update', array(), 'Team update' ),
			'plain post'                  => array( 'core', 'Introducing a new theme', array(), '' ),
		);
	}

	/**
	 * Reading time is the word count at 200 words a minute, rounded up, and at least a minute.
	 */
	public function test_reading_minutes(): void {
		$this->assertSame( 1, get_reading_minutes( '' ) );
		$this->assertSame( 1, get_reading_minutes( '<p>' . str_repeat( 'word ', 200 ) . '</p>' ) );
		$this->assertSame( 2, get_reading_minutes( '<p>' . str_repeat( 'word ', 201 ) . '</p>' ) );
	}

	/**
	 * Each day's first post shows the day's heading, and later posts that day hide theirs.
	 */
	public function test_groups_by_day(): void {
		$now = time();
		$this->create_post( self::$design, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS ) ) );
		$this->create_post( self::$core, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS - HOUR_IN_SECONDS ) ) );
		$this->create_post( self::$core, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now - 4 * DAY_IN_SECONDS ) ) );

		$this->go_to( home_url( '/' ) );
		$html = render();

		preg_match_all( '/<h3 ([^>]*)__day"/', $html, $headings );
		$hidden = array_map( static fn( string $attributes ): bool => str_contains( $attributes, 'hidden' ), $headings[1] );

		$this->assertSame( array( false, true, false ), $hidden );
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
