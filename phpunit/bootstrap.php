<?php
/**
 * PHPUnit bootstrap file.
 *
 * Runs in the wp-env tests container, where this directory is mapped into the WordPress root,
 * and in CI on the runner, where .github/wp-tests-config.php points WordPress at this repository.
 *
 * @package WordPressdotorg\Theme\Make_2024
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// The WP test suite reads this as a constant, so bridge it from the environment the workflow sets.
$_config_file = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );
if ( is_string( $_config_file ) && '' !== $_config_file && ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $_config_file );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require WP_CONTENT_DIR . '/plugins/wporg-make-sites-cpt/make_sites_cpt.php';
		require WP_CONTENT_DIR . '/themes/wporg-make-2024/src/latest-activity/index.php';
	}
);

// Test blogs have no web server behind them, so render posts and comments locally instead of through their REST API.
tests_add_filter( 'wporg_make_latest_activity_rest_base', '__return_empty_string' );

require $_tests_dir . '/includes/bootstrap.php';
