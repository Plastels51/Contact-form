<?php
/**
 * Run every test suite in one process.
 *
 *   docker compose exec -T wordpress php -r "define('DOING_AJAX', true); \
 *     define('WP_USE_THEMES', false); require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-all.php';"
 *
 * DOING_AJAX must be defined by the caller: the intake suite needs wp_die() to
 * take its AJAX path, and the constant cannot be set once WordPress is loaded.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cfs_suites = array(
	'run-tests.php',
	'run-tests-form.php',
	'run-tests-render.php',
	'run-tests-ajax.php',
	'run-tests-admin.php',
	'run-tests-actions.php',
	'run-tests-submissions.php',
	'run-tests-legacy.php',
	'run-tests-bitrix24.php',
);

$cfs_failed = 0;

foreach ( $cfs_suites as $cfs_suite ) {
	$cfs_command = sprintf(
		'%s -r %s 2>&1',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg(
			"define('DOING_AJAX', true); define('WP_ADMIN', true); define('WP_USE_THEMES', false);"
			. " require '" . ABSPATH . "wp-load.php';"
			. " require '" . __DIR__ . '/' . $cfs_suite . "';"
		)
	);

	$cfs_output = array();
	$cfs_status = 0;
	exec( $cfs_command, $cfs_output, $cfs_status );

	foreach ( $cfs_output as $cfs_line ) {
		// wp_mail() has no MTA in the test container; its noise is not a result.
		if ( false !== strpos( $cfs_line, 'sendmail' ) || false !== strpos( $cfs_line, 'CFS Mailer' ) ) {
			continue;
		}
		echo $cfs_line . "\n";
	}

	if ( 0 !== $cfs_status ) {
		++$cfs_failed;
	}
}

echo "\n" . str_repeat( '═', 60 ) . "\n";
echo 0 === $cfs_failed
	? "ALL SUITES PASSED\n"
	: sprintf( "%d SUITE(S) FAILED\n", $cfs_failed );

exit( $cfs_failed > 0 ? 1 : 0 );
