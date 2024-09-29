<?php

add_filter( 'wporg_block_navigation_menus', 'add_site_navigation_menus' );

/**
 * Enqueue theme styles.
 */
function make_enqueue_scripts() {
	// The parent style is registered as `wporg-parent-2021-style`, and will be loaded unless
	// explicitly unregistered. We can load any child-theme overrides by declaring the parent
	// stylesheet as a dependency.
	$style_path = get_stylesheet_directory() . '/build/style/style-index.css';
	$style_uri = get_stylesheet_directory_uri() . '/build/style/style-index.css';
	wp_enqueue_style(
		'wporg-make-2024-style',
		$style_uri,
		array( 'wporg-parent-2021-style', 'wporg-global-fonts' ),
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
add_action( 'wp_enqueue_scripts', 'make_enqueue_scripts' );

/**
 * Sets up theme defaults and registers support for various WordPress features.
 */
function make_setup_theme() {
	register_nav_menu( 'primary', __( 'Navigation Menu', 'make-wporg' ) );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'title-tag' );
}
add_action( 'after_setup_theme', 'make_setup_theme' );

/**
 * Provide a list of local navigation menus.
 */
function add_site_navigation_menus( $menus ) {
	$menu = array(
		array(
			'label' => __( 'Meetings', 'make-wporg' ),
			'url'   => site_url( '/meetings/' ),
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
			'label'     => __( 'Five for the Future', 'make-wporg' ),
			'url'       => 'https://wordpress.org/five-for-the-future/',
		),
		array(
			'label' => __( 'Contributor Handbook', 'make-wporg' ),
			'url'   => site_url( '/handbook/' ),
		),
	);

	if ( ! is_user_logged_in() ) {
		global $wp;
		$redirect_url = home_url( $wp->request );
		$menu[] = array(
			'label' => __( 'Log in', 'make-wporg' ),
			'url' => wp_login_url( $redirect_url ),
			'className' => 'has-separator',
		);
	}

	$menus['make'] = $menu;

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

	// There's nothing worth searching for on this site.
	if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
		$query->set_404();
	}
}
add_action( 'pre_get_posts', 'make_query_mods' );

add_filter(
	'the_posts',
	function( $posts, $query ) {
		// Ensure all non-post routes 404, as this site isn't like most others.
		if (
			( ! is_admin() && $query->is_main_query() && ! $query->is_robots() && ! $posts ) ||
			( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'meeting' ) && $query->get( 'paged' ) > 1 ) // Pagination on the query is explicitly disabled, so this doens't 404
		) {
			$query->set_404();
			status_header( 404 );
			nocache_headers();
		}

		return $posts;
	},
	10,
	2
);

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
add_filter( 'post_class', 'make_home_site_classes', 10, 3 );

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
add_filter( 'document_title_parts', 'make_add_frontpage_name_to_title' );

/**
 * Noindex the post_type behind the site listing.
 */
function make_noindex( $noindex ) {
	if ( is_singular( 'make_site' ) ) {
		$noindex = true;
	}

	return $noindex;
}
add_filter( 'wporg_noindex_request', 'make_noindex' );

