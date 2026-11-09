<?php
/**
 * PHPUnit bootstrap file.
 *
 * Runs inside the wp-env tests container, where this directory is mapped into the WordPress root.
 *
 * @package WordPressdotorg\Theme\Make_2024
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/wp-content/plugins/wporg-make-sites-cpt/make_sites_cpt.php';
		require dirname( __DIR__ ) . '/wp-content/themes/wporg-make-2024/src/latest-activity/index.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
