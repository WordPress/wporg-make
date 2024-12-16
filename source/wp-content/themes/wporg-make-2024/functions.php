<?php

namespace WordPressdotorg\Theme\Make_2024;

/**
 * Blocks.
 */
require_once __DIR__ . '/src/meeting-time/index.php';

/**
 * Actions and filters.
 */
add_action( 'after_setup_theme', __NAMESPACE__ . '\make_setup_theme' );
add_action( 'pre_get_posts', __NAMESPACE__ . '\make_query_mods' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\make_enqueue_scripts' );

add_filter( 'document_title_parts', __NAMESPACE__ . '\make_add_frontpage_name_to_title' );
add_filter( 'next_post_link', __NAMESPACE__ . '\get_adjacent_handbook_post_link', 10, 5 );
add_filter( 'post_class', __NAMESPACE__ . '\make_home_site_classes', 10, 3 );
add_filter( 'post_type_link', __NAMESPACE__ . '\replace_make_site_permalink', 10, 2 );
add_filter( 'previous_post_link', __NAMESPACE__ . '\get_adjacent_handbook_post_link', 10, 5 );
add_filter( 'render_block_core/search', __NAMESPACE__ . '\modify_handbook_search_block_action', 10, 2 );
add_filter( 'render_block_data', __NAMESPACE__ . '\modify_header_template_part' );
add_filter( 'single_template_hierarchy', __NAMESPACE__ . '\add_handbook_templates' );
add_filter( 'the_content', __NAMESPACE__ . '\style_tables' );
add_filter( 'the_posts', __NAMESPACE__ . '\make_handle_non_post_routes', 10, 2 );
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_site_navigation_menus' );
add_filter( 'wporg_block_site_breadcrumbs', __NAMESPACE__ . '\set_site_breadcrumbs' );
add_filter( 'wporg_handbook_toc_should_add_toc', '__return_false' );
add_filter( 'wporg_noindex_request', __NAMESPACE__ . '\make_noindex' );
add_action( 'wp_loaded', __NAMESPACE__ . '\remove_github_edit_link_from_title' );

remove_filter( 'the_title', array( 'WPOrg_Cli\Handbook', 'filter_the_title_edit_link' ), 10, 2 );

/**
 * Remove the WordPressdotorg\Markdown filter that adds the GitHub edit link to the title.
 */
function remove_github_edit_link_from_title() {
	global $wp_filter;
	$filters = isset( $wp_filter['the_title'] ) ? $wp_filter['the_title'] : array();

	foreach ( $filters as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if (
				is_array( $callback )
				&& is_array( $callback['function'] )
				&& $callback['function'][0] instanceof \WordPressdotorg\Markdown\Editor
				&& str_ends_with( $callback['function'][1], 'filter_the_title_edit_link' )
			) {
				remove_filter( 'the_title', $callback['function'], $priority );
			}
		}
	}
}

/**
 * Enqueue theme styles.
 */
function make_enqueue_scripts() {
	// On Rosetta sites when switching to this theme for a block handbook layout, the parent theme styles
	// may not be enqueued. We need to explicitly enqueue them before enqueuing the child theme styles.
	if ( ! wp_style_is( 'wporg-parent-2021-style', 'enqueued' ) ) {
		wp_enqueue_style(
			'wporg-parent-2021-style',
			get_theme_root_uri() . '/wporg-parent-2021/build/style.css',
			[ 'wporg-global-fonts' ],
			filemtime( get_theme_root() . '/wporg-parent-2021/build/style.css' )
		);

		wp_enqueue_style(
			'wporg-parent-2021-block-styles',
			get_theme_root_uri() . '/wporg-parent-2021/build/block-styles.css',
			[ 'wporg-global-fonts' ],
			filemtime( get_theme_root() . '/wporg-parent-2021/build/block-styles.css' )
		);
	}

	$style_path = get_stylesheet_directory() . '/build/style/style-index.css';
	$style_uri = get_stylesheet_directory_uri() . '/build/style/style-index.css';
	wp_enqueue_style(
		'wporg-make-2024-style',
		$style_uri,
		array( 'wporg-parent-2021-style', 'wporg-global-fonts', 'dashicons' ),
		filemtime( $style_path )
	);
	wp_style_add_data( 'wporg-make-2024-style', 'path', $style_path );

	$rtl_file = str_replace( '.css', '-rtl.css', $style_path );
	if ( is_rtl() && file_exists( $rtl_file ) ) {
		wp_style_add_data( 'wporg-make-2024-style', 'rtl', 'replace' );
		wp_style_add_data( 'wporg-make-2024-style', 'path', $rtl_file );
	}

	// Preload the heading font(s).
	if ( is_callable( 'global_fonts_preload' ) ) {
		/* translators: Subsets can be any of cyrillic, cyrillic-ext, greek, greek-ext, vietnamese, latin, latin-ext. */
		$subsets = _x( 'Latin', 'Heading font subsets, comma separated', 'make-wporg' );
		// All headings.
		global_fonts_preload( 'EB Garamond, Inter', $subsets );
	}
}

/**
 * Sets up theme defaults and registers support for various WordPress features.
 */
function make_setup_theme() {
	register_nav_menu( 'primary', __( 'Navigation Menu', 'make-wporg' ) );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'title-tag' );
}

/**
 * Gets the navigation menu items for the handbook section.
 *
 * @return array Array of menu items with 'label' and 'url' keys.
 */
function get_handbook_navigation_menu() {
	$local_nav_menu_locations = get_nav_menu_locations();
	$local_nav_menu_object = isset( $local_nav_menu_locations['primary'] )
		? wp_get_nav_menu_object( $local_nav_menu_locations['primary'] )
		: false;

	if ( ! $local_nav_menu_object ) {
		return array();
	}

	$menu_items = wp_get_nav_menu_items( $local_nav_menu_object->term_id );

	if ( ! $menu_items || empty( $menu_items ) ) {
		return array();
	}

	return array_map(
		function( $menu_item ) {
			global $wp;
			$is_current_page = trailingslashit( $menu_item->url ) === trailingslashit( home_url( $wp->request ) );

			return array(
				'label' => esc_html( $menu_item->title ),
				'url' => esc_url( $menu_item->url ),
				'className' => $is_current_page ? 'current-menu-item' : '',
			);
		},
		// Limit local nav items to 6
		array_slice( $menu_items, 0, 6 )
	);
}

/**
 * Add a login link to the local nav if there is no logged in user.
 */
function _maybe_add_login_item_to_menu( $menu ) {
	if ( is_user_logged_in() ) {
		return $menu;
	}

	global $wp;
	$redirect_url = home_url( $wp->request );
	$login_item = array(
		'label' => __( 'Log in', 'make-wporg' ),
		'url' => wp_login_url( $redirect_url ),
	);

	if ( $menu ) {
		$login_item['className'] = 'has-separator';
		$menu[] = $login_item;
	} else {
		$menu = array( $login_item );
	}

	return $menu;
}

/**
 * Provide a list of local navigation menus.
 */
function add_site_navigation_menus( $menus ) {
	if ( function_exists( 'wporg_is_handbook' ) && wporg_is_handbook() ) {
		$menus['breathe'] = _maybe_add_login_item_to_menu( get_handbook_navigation_menu() );
	} else {
		$menus['make'] = _maybe_add_login_item_to_menu(
			array(
				array(
					'label' => __( 'Meetings', 'make-wporg' ),
					'url'   => site_url( '/meetings/' ),
					'className' => is_page( 'meetings' ) ? 'current-menu-item' : '',
				),
				array(
					'label' => __( 'Team Updates', 'make-wporg' ),
					'url'   => site_url( '/updates/' ),
				),
				array(
					'label' => __( 'Project Updates', 'make-wporg' ),
					'url'   => site_url( '/project/' ),
				),
				array(
					'label' => __( 'Five for the Future', 'make-wporg' ),
					'url'   => 'https://wordpress.org/five-for-the-future/',
				),
				array(
					'label' => __( 'Contributor Handbook', 'make-wporg' ),
					'url'   => site_url( '/handbook/' ),
				),
				array(
					'label' => __( 'Communicate', 'make-wporg' ),
					'url'   => 'https://make.wordpress.org/chat/',
				),
			)
		);
	}

	return $menus;
}

/**
 * Modify the main query on the home and search pages.
 *
 * @param WP_Query $query The WP_Query instance (passed by reference).
 */
function make_query_mods( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_home() ) {
		$query->set( 'posts_per_page', 1 );
	}

	// There's nothing worth searching for on this site, unless it's a handbook search.
	if ( ! is_admin() && $query->is_main_query() && $query->is_search() && function_exists( 'wporg_is_handbook' ) && ! wporg_is_handbook() ) {
		$query->set_404();
	}
}

/**
 * Filters content for the github handbooks to wrap tables with block markup for styles.
 *
 * @param string $content
 * @return string
 */
function style_tables( $content ) {
	if ( function_exists( 'wporg_is_handbook' ) && ! wporg_is_handbook() ) {
		return $content;
	}

	// Find table elements in the content and wrap with figure.wp-block-table
	$content = preg_replace_callback(
		'!<table.*?</table>!is',
		function( $matches ) {
			return do_blocks(
				'<!-- wp:table {"className":"is-style-borderless"} --><figure class="wp-block-table is-style-borderless">' .
				$matches[0] .
				'</figure><!-- /wp:table -->'
			);
		},
		$content
	);

	return $content;
}

/**
 * Ensure all non-post and non-handbook routes 404, as this site isn't like most others.
 *
 * @param array    $posts The array of posts.
 * @param WP_Query $query The WP_Query instance (passed by reference).
 * @return array The filtered posts array.
 */
function make_handle_non_post_routes( $posts, $query ) {
	// Return early if admin, not main query, or handbook search
	if (
		is_admin() ||
		! $query->is_main_query() ||
		( $query->is_search() && function_exists( 'wporg_is_handbook' ) && wporg_is_handbook() )
	) {
		return $posts;
	}

	// Set 404 for empty results or paginated meeting archives
	if (
		( ! $query->is_robots() && ! $posts ) ||
		( $query->is_post_type_archive( 'meeting' ) && $query->get( 'paged' ) > 1 )
	) {
		$query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	return $posts;
}

/**
 * Adds custom classes to the array of post classes.
 *
 * @param array  $classes An array of post class names.
 * @param string $class   A comma-separated list of additional classes added to the post.
 * @param int    $id      The post ID.
 * @return array The filtered post class array.
 */
function make_home_site_classes( $classes, $class, $id ) {
	$classes[] = sanitize_html_class( 'make-' . get_post( $id )->post_name );
	return $classes;
}

/**
 * Set page name for front page title.
 *
 * @param array $parts The document title parts.
 * @return array The document title parts.
 */
function make_add_frontpage_name_to_title( $parts ) {
	if ( is_front_page() ) {
		$parts['title'] = 'Get Involved';
		$parts['site']  = 'WordPress.org';
	}

	return $parts;
}

/**
 * Noindex the post_type behind the site listing.
 */
function make_noindex( $noindex ) {
	if ( is_singular( 'make_site' ) ) {
		$noindex = true;
	}

	return $noindex;
}

/**
 * Filters to replace '/make_site/' permalinks with the corresponding Make site URL.
 *
 * @param string  $permalink The post's permalink.
 * @param WP_Post $post The post object.
 * @return string The filtered permalink.
 */
function replace_make_site_permalink( $permalink, $post ) {
	if ( false !== strpos( $permalink, 'make_site' ) ) {
		$makesites = make_site_get_network_sites();

		$make_site_id = get_post_meta( $post->ID, 'make_site_id', true );
		$url = isset( $makesites[ $make_site_id ] ) ? $makesites[ $make_site_id ] : '';

		if ( $url ) {
			$permalink = $url;
		}
	}

	return $permalink;
}

/**
 * Modify search block action URL for handbook pages
 */
function modify_handbook_search_block_action( $block_content, $block ) {
	if ( function_exists( 'wporg_is_handbook' ) && wporg_is_handbook() ) {
		$block_content = preg_replace(
			'/<form[^>]*action="[^"]*"/',
			'<form action="' . esc_url( wporg_get_current_handbook_home_url() ) . '"',
			$block_content
		);
	}

	return $block_content;
}

/**
 * Only display the 'Last updated' if the modified date is later than the publishing date.
 */
add_shortcode(
	'last_updated',
	function() {
		global $post;
		if ( get_the_modified_date( 'Ymdhi', $post->ID ) > get_the_date( 'Ymdhi', $post->ID ) ) {
			return '<p style="font-style:normal;font-weight:700">' . esc_html__( 'Last updated', 'make-wporg' ) . '</p>';
		}
		return '';
	}
);

/**
 * Switch out the destination for next/prev links to mirror the Chapter List order.
 *
 * @param string  $output   The adjacent post link.
 * @param string  $format   Link anchor format.
 * @param string  $link     Link permalink format.
 * @param WP_Post $post     The adjacent post.
 * @param string  $adjacent Whether the post is previous or next.
 *
 * @return string Updated link tag.
 */
function get_adjacent_handbook_post_link( $output, $format, $link, $post, $adjacent ) {
	if ( function_exists( 'wporg_is_handbook' ) && ! wporg_is_handbook() ) {
		return $output;
	}

	$post_id = get_the_ID();
	$pages   = get_pages(
		array(
			'sort_column' => 'menu_order, title',
			'post_type'   => get_post_type( $post_id ),
		)
	);
	$is_previous = 'previous' === $adjacent;

	foreach ( $pages as $i => $page ) {
		if ( $page->ID === $post_id ) {
			$adj_index = $is_previous ? $i - 1 : $i + 1;
			break;
		}
	}

	if ( $adj_index < count( $pages ) && $adj_index > 0 ) {
		$post = $pages[ $adj_index ];
	} else {
		return '';
	}

	$title = apply_filters( 'the_title', $post->post_title, $post->ID );
	$url   = get_permalink( $post );

	$screen_reader_content = sprintf(
		$is_previous
			? /* translators: %s: post title */
			__( 'Previous: %s', 'make-wporg' )
			: /* translators: %s: post title */
			__( 'Next: %s', 'make-wporg' ),
		$title
	);

	$content = str_replace(
		'%title',
		sprintf(
			'<span aria-hidden="true" class="post-navigation-link__label">%1$s</span>
			<span aria-hidden="true" class="post-navigation-link__title">%2$s</span>
			<span class="screen-reader-text">%3$s</span>',
			$is_previous ? __( 'Previous', 'make-wporg' ) : __( 'Next', 'make-wporg' ),
			$title,
			$screen_reader_content,
		),
		$link
	);

	$inlink = sprintf(
		'<a href="%1$s" rel="%2$s">%3$s</a>',
		$url,
		$is_previous ? 'prev' : 'next',
		$content
	);

	$output = str_replace( '%link', $inlink, $format );

	return $output;
}

/**
 * Update header template based on current query.
 *
 * @param array $parsed_block The block being rendered.
 *
 * @return array The updated block.
 */
function modify_header_template_part( $parsed_block ) {
	if (
		'core/template-part' === $parsed_block['blockName'] &&
		! empty( $parsed_block['attrs']['slug'] ) &&
		str_starts_with( $parsed_block['attrs']['slug'], 'header' )
	) {
		if (
			function_exists( 'wporg_is_handbook' ) &&
			wporg_is_handbook() &&
			! wporg_is_handbook_landing_page()
		) {
			$parsed_block['attrs']['slug'] = 'header-handbook-child';
		}
	}

	return $parsed_block;
}

/**
 * Filters breadcrumb items for the site-breadcrumb block.
 * Breadcrumbs are only displayed on the handbook pages, so this logic is specific to that usage.
 *
 * @param array $breadcrumbs The current breadcrumbs.
 *
 * @return array The modified breadcrumbs.
 */
function set_site_breadcrumbs( $breadcrumbs ) {
	if ( empty( $breadcrumbs ) ) {
		return $breadcrumbs;
	}

	$handbook_home_url = wporg_get_current_handbook_home_url();

	// Change the title of the first breadcrumb to 'Home'.
	$breadcrumbs[0]['title'] = __( 'Home', 'make-wporg' );

	// Insert the handbook home page as the second breadcrumb.
	$handbook_home_breadcrumb = array(
		'url' => $handbook_home_url,
		'title' => __( 'Handbook', 'make-wporg' ),
	);
	array_splice( $breadcrumbs, 1, 0, array( $handbook_home_breadcrumb ) );

	if ( is_search() ) {
		// Remove all the breadcrumbs after the handbook home breadcrumb.
		$breadcrumbs = array_slice( $breadcrumbs, 0, 2 );
		$current_page = get_query_var( 'paged' );
		$is_paged = $current_page > 1;
		$unpaged_search_url = $handbook_home_url . '?s=' . get_search_query();

		// Add a search results breadcrumb.
		$breadcrumbs[] = array(
			'url' => $is_paged ? $unpaged_search_url : false,
			'title' => __( 'Search results', 'make-wporg' ),
		);

		if ( $is_paged ) {
			$breadcrumbs[] = array(
				'url' => false,
				/* translators: %s: Page number */
				'title' => sprintf( __( 'Page %s', 'make-wporg' ), $current_page ),
			);
		}
	} else {
		// Add the ancestors of the current page to the breadcrumbs.
		$post_id = get_the_ID();
		$ancestors = get_post_ancestors( $post_id );

		if ( ! empty( $ancestors ) ) {
			foreach ( $ancestors as $ancestor ) {
				$ancestor_post = get_post( $ancestor );

				$ancestor_breadcrumb = array(
					'url' => get_permalink( $ancestor_post ),
					'title' => get_the_title( $ancestor_post ),
				);

				// Insert the ancestor breadcrumb after the handbook home breadcrumb.
				array_splice( $breadcrumbs, 2, 0, array( $ancestor_breadcrumb ) );
			}
		}
	}

	// Ensure breadcrumbs are displayed only when there are at least 3 levels.
	$breadcrumb_level = count( $breadcrumbs );
	if ( $breadcrumb_level < 3 ) {
		$breadcrumbs = array();
	}

	return $breadcrumbs;
}

/**
 * Filter the template heiarchy to add a github handbook template.
 *
 * @param string[] $templates A list of template candidates, in descending order of priority.
 * @return string[] Updated list of templates.
 */
function add_handbook_templates( $templates ) {
	$is_github_source = ! empty( get_post_meta( get_the_ID(), 'wporg_cli_markdown_source', true ) ) || ! empty( get_post_meta( get_the_ID(), 'wporg_markdown_source', true ) );

	if ( $is_github_source ) {
		array_unshift( $templates, 'single-handbook-github.php' );
	}

	return $templates;
}


/**
 * Get the link to edit the page.
 */
add_shortcode(
	'article_edit_link',
	function() {
		global $post;
		$markdown_source = get_markdown_edit_link( $post->ID );
		if ( $markdown_source ) {
			return esc_url( $markdown_source );
		}
		return is_user_logged_in() ? get_edit_post_link() : wp_login_url( get_permalink() );
	}
);

/**
 * Get the link to the GH commit history.
 */
add_shortcode(
	'article_changelog_link',
	function() {
		global $post;
		$markdown_source = get_markdown_edit_link( $post->ID );
		// If this is a github page, use the edit URL to generate the
		// commit history URL
		if ( str_contains( $markdown_source, 'github.com' ) ) {
			return str_replace( '/edit/', '/commits/', $markdown_source );
		}
		return '#';
	}
);

/**
 * Get the markdown link.
 *
 * @param int $post_id Post ID.
 */
function get_markdown_edit_link( $post_id ) {
	$markdown_source = get_post_meta( $post_id, 'wporg_cli_markdown_source', true );
	if ( ! $markdown_source ) {
		return;
	}

	if ( 'github.com' !== parse_url( $markdown_source, PHP_URL_HOST ) ) {
		return $markdown_source;
	}

	if ( preg_match( '!^https?://github.com/(?P<repo>[^/]+/[^/]+)/(?P<editblob>blob|edit)/(?P<branchfile>.*)$!i', $markdown_source, $m ) ) {
		if ( 'edit' === $m['editblob'] ) {
			return $markdown_source;
		}

		$markdown_source = "https://github.com/{$m['repo']}/edit/{$m['branchfile']}";
	}

	return $markdown_source;
}
