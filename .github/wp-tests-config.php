<?php
/**
 * WordPress test-suite configuration for CI.
 *
 * Used by the unit-tests workflow, which runs PHPUnit directly on the runner
 * against a WordPress nightly build and the runner's MySQL service.
 *
 * @package WordPressdotorg\Theme\Make_2024
 */

declare( strict_types = 1 );

$wp_core_dir = getenv( 'WP_CORE_DIR' );

if ( ! is_string( $wp_core_dir ) || '' === $wp_core_dir ) {
	fwrite( STDERR, 'WP_CORE_DIR must point at the WordPress build under test.' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

define( 'ABSPATH', rtrim( $wp_core_dir, '/' ) . '/' );

// Load mu-plugins, plugins and themes from the repository, as wp-env does by mapping them into its WordPress.
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/source/wp-content' );

/**
 * Reads a setting from the environment, falling back to the runner default.
 *
 * @param string $name     Environment variable to read.
 * @param string $fallback Value to use when it is unset or empty.
 * @return string The resolved value.
 */
function wporg_make_test_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return is_string( $value ) && '' !== $value ? $value : $fallback;
}

define( 'DB_NAME', wporg_make_test_env( 'WP_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', wporg_make_test_env( 'WP_DB_USER', 'root' ) );
define( 'DB_PASSWORD', wporg_make_test_env( 'WP_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', wporg_make_test_env( 'WP_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
