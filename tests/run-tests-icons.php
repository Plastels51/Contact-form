<?php
/**
 * Icon library tests: the directory option, its containment rules and the
 * SVG sanitiser that stands between a file on disk and the page.
 *
 *   docker compose exec -T wordpress php -r "define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-icons.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

$t = new CFS_Test_Runner();

$cfs_icon_option_before = get_option( CFS_Icons::OPTION_DIR, '' );

// Fixtures live under uploads because that is reliably writable and reliably
// inside wp-content, which is what the containment rule is about.
$cfs_upload  = wp_upload_dir();
$cfs_dir     = rtrim( (string) $cfs_upload['basedir'], '/\\' ) . '/cfs-icon-tests';
$cfs_written = array();

/**
 * Write one fixture file and remember it for cleanup.
 *
 * @param array  $written  Registry of created paths.
 * @param string $dir      Directory to write into.
 * @param string $name     File name.
 * @param string $contents File body.
 */
function cfs_icon_fixture( array &$written, string $dir, string $name, string $contents ): void {
	$path = $dir . '/' . $name;
	file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
	$written[] = $path;
}

/**
 * Point the plugin at a directory and clear every cache in the way.
 *
 * @param string $relative Path relative to wp-content.
 */
function cfs_icon_use_dir( string $relative ): void {
	update_option( CFS_Icons::OPTION_DIR, $relative );
	CFS_Icons::flush_cache();
}

wp_mkdir_p( $cfs_dir );

$cfs_relative = ltrim(
	str_replace( str_replace( '\\', '/', WP_CONTENT_DIR ), '', str_replace( '\\', '/', $cfs_dir ) ),
	'/'
);

echo "\nCFS icon library\n";
echo str_repeat( '─', 60 ) . "\n";

// ── The path option ────────────────────────────────────────────────────────

$t->test( 'путь наружу wp-content отбивается', function ( $a ) {
	foreach ( array( '../../../etc', '../wp-admin', 'themes/../../..', '../../' ) as $bad ) {
		$a->same( '', CFS_Icons::sanitize_option( $bad ), $bad );
	}
} );

$t->test( 'несуществующая директория не сохраняется', function ( $a ) {
	$a->same( '', CFS_Icons::sanitize_option( 'themes/нет-такой-темы/icons' ) );
} );

$t->test( 'корень wp-content не считается директорией иконок', function ( $a ) {
	$a->same( '', CFS_Icons::sanitize_option( '.' ), 'точка' );
	$a->same( '', CFS_Icons::sanitize_option( 'themes/..' ), 'путь, ведущий в корень' );
} );

$t->test( 'путь нормализуется и хранится относительно wp-content', function ( $a ) use ( $cfs_dir, $cfs_relative ) {
	$messy = 'uploads/../uploads/cfs-icon-tests/';
	$a->same( $cfs_relative, CFS_Icons::sanitize_option( $messy ), 'петля через ..' );
	$a->same( $cfs_relative, CFS_Icons::sanitize_option( '/' . $cfs_relative ), 'ведущий слеш' );
	$a->ok( is_dir( $cfs_dir ), 'фикстурная директория на месте' );
} );

// ── Reading the directory ──────────────────────────────────────────────────

$t->test( 'директория дополняет встроенный набор', function ( $a ) use ( &$cfs_written, $cfs_dir, $cfs_relative ) {
	cfs_icon_fixture( $cfs_written, $cfs_dir, 'flag.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 2v20"/></svg>' );
	cfs_icon_fixture( $cfs_written, $cfs_dir, 'bell.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>' );

	cfs_icon_use_dir( $cfs_relative );

	$keys = CFS_Icons::keys();

	$a->ok( in_array( 'flag', $keys, true ), 'файл из директории попал в набор' );
	$a->contains( '<path', CFS_Icons::render( 'flag' ) );

	// The per-name fallback: everything the directory did not name keeps the
	// icon it had. `close` and the chevrons matter most — they are chosen by
	// the plugin, not by the template, so their loss is silent.
	$a->ok( '' !== CFS_Icons::render( 'user' ), 'встроенная иконка на месте' );
	$a->ok( '' !== CFS_Icons::render( 'close' ), 'крестик модала на месте' );
	$a->ok( '' !== CFS_Icons::render( 'angle-small-right' ), 'шеврон мастера на месте' );
} );

$t->test( 'файл с именем встроенной иконки заменяет её', function ( $a ) use ( &$cfs_written, $cfs_dir, $cfs_relative ) {
	$builtin = CFS_Icons::render( 'user' );

	cfs_icon_fixture( $cfs_written, $cfs_dir, 'user.svg', '<svg viewBox="0 0 24 24"><path d="M3 3h18"/></svg>' );
	cfs_icon_use_dir( $cfs_relative );

	$a->contains( 'M3 3h18', CFS_Icons::render( 'user' ), 'отдаётся файл из директории' );
	$a->not( CFS_Icons::render( 'user' ) === $builtin, 'встроенная версия вытеснена' );
	$a->ok( in_array( 'user', CFS_Icons::builtin_keys(), true ), 'имя действительно из встроенного набора' );

	unlink( $cfs_dir . '/user.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- test fixture.
	cfs_icon_use_dir( $cfs_relative );

	$a->same( $builtin, CFS_Icons::render( 'user' ), 'после удаления файла вернулась встроенная' );
} );

$t->test( 'пустая директория откатывает на встроенный набор', function ( $a ) use ( $cfs_dir ) {
	$empty = $cfs_dir . '-empty';
	wp_mkdir_p( $empty );

	$relative = ltrim( str_replace( str_replace( '\\', '/', WP_CONTENT_DIR ), '', str_replace( '\\', '/', $empty ) ), '/' );
	cfs_icon_use_dir( $relative );

	$a->same( array(), CFS_Icons::directory_icons(), 'директория ничего не дала' );
	$a->ok( '' !== CFS_Icons::render( 'user' ), 'встроенные иконки на месте' );

	rmdir( $empty ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
} );

$t->test( 'опция, записанная мимо санитайзера, всё равно проверяется', function ( $a ) {
	// A path written straight into the database, the way WP-CLI or a migration
	// would do it. directory() has to refuse it on its own.
	cfs_icon_use_dir( '../../../etc' );

	$a->same( '', CFS_Icons::directory(), 'путь наружу не резолвится' );
	$a->ok( '' !== CFS_Icons::render( 'user' ), 'работает встроенный набор' );
} );

$t->test( 'имя файла становится именем иконки, негодные пропускаются', function ( $a ) use ( &$cfs_written, $cfs_dir, $cfs_relative ) {
	cfs_icon_fixture( $cfs_written, $cfs_dir, 'иконка.svg', '<svg viewBox="0 0 24 24"><path d="M0 0"/></svg>' );
	cfs_icon_fixture( $cfs_written, $cfs_dir, 'arrow-up.svg', '<svg viewBox="0 0 24 24"><path d="M12 4v16"/></svg>' );

	cfs_icon_use_dir( $cfs_relative );
	$keys = CFS_Icons::keys();

	$a->ok( in_array( 'arrow-up', $keys, true ), 'дефис в имени сохраняется' );
	$a->not( in_array( 'иконка', $keys, true ), 'кириллическое имя не попадает в набор' );
} );

$t->test( 'слишком большой файл не читается', function ( $a ) use ( &$cfs_written, $cfs_dir, $cfs_relative ) {
	$huge = '<svg viewBox="0 0 24 24"><path d="' . str_repeat( 'M0 0', 20000 ) . '"/></svg>';
	$a->ok( strlen( $huge ) > CFS_Icons::MAX_FILE_SIZE, 'фикстура действительно крупная' );

	cfs_icon_fixture( $cfs_written, $cfs_dir, 'huge.svg', $huge );
	cfs_icon_use_dir( $cfs_relative );

	$a->not( in_array( 'huge', CFS_Icons::keys(), true ) );
} );

$t->test( 'правка файла сбрасывает кэш сама', function ( $a ) use ( &$cfs_written, $cfs_dir, $cfs_relative ) {
	cfs_icon_fixture( $cfs_written, $cfs_dir, 'mark.svg', '<svg viewBox="0 0 24 24"><path d="M1 1"/></svg>' );
	cfs_icon_use_dir( $cfs_relative );
	$a->contains( 'M1 1', CFS_Icons::render( 'mark' ), 'первое чтение' );

	// Same directory, new contents and a new mtime: only the static cache is
	// cleared here, so the transient has to notice the change by itself.
	touch( $cfs_dir . '/mark.svg', time() + 5 );
	cfs_icon_fixture( $cfs_written, $cfs_dir, 'mark.svg', '<svg viewBox="0 0 24 24"><path d="M2 2"/></svg>' );
	touch( $cfs_dir . '/mark.svg', time() + 10 );

	CFS_Icons::flush_cache();
	delete_transient( 'nothing' ); // keep the transient in place on purpose.

	$a->contains( 'M2 2', CFS_Icons::render( 'mark' ), 'новое содержимое подхвачено' );
} );

// ── The sanitiser ──────────────────────────────────────────────────────────

$t->test( 'исполняемое содержимое вырезается', function ( $a ) {
	$evil = '<?xml version="1.0"?><!DOCTYPE svg>'
		. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)">'
		. '<script>alert(2)</script>'
		. '<a href="javascript:alert(3)"><path d="M0 0h24v24H0z" fill="currentColor"/></a>'
		. '<use href="http://evil/x.svg"/>'
		. '<foreignObject><iframe src="//evil"></iframe></foreignObject>'
		. '<image href="http://evil/track.png"/>'
		. '<style>*{background:url(//evil)}</style>'
		. '</svg><script>alert(4)</script>';

	$clean = CFS_Icons::sanitize_svg( $evil );

	foreach ( array( 'onload', 'script', 'javascript:', '<use', 'foreignObject', 'iframe', 'DOCTYPE', '<image', '<style', 'alert', '<a ' ) as $bad ) {
		$a->same( false, stripos( $clean, $bad ), $bad . ' удалено' );
	}

	$a->contains( 'M0 0h24v24H0z', $clean, 'геометрия сохранена' );
} );

$t->test( 'текстовые узлы не выживают', function ( $a ) {
	// wp_kses() drops a forbidden tag but keeps what was inside it. For an
	// icon that means attacker-controlled text left sitting in the markup.
	$clean = CFS_Icons::sanitize_svg( '<svg viewBox="0 0 24 24"><script>payload_marker</script><path d="M1 1"/>наружный текст</svg>' );

	$a->same( false, strpos( $clean, 'payload_marker' ), 'тело script' );
	$a->same( false, strpos( $clean, 'наружный текст' ), 'текст между тегами' );
	$a->contains( 'M1 1', $clean );
} );

$t->test( 'безобидная иконка проходит без потерь', function ( $a ) {
	$good  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v8"/></svg>';
	$clean = CFS_Icons::sanitize_svg( $good );

	$a->contains( '<circle', $clean );
	$a->contains( '<path', $clean );
	$a->contains( 'currentColor', $clean, 'цвет остаётся наследуемым' );
	$a->contains( 'aria-hidden', $clean );
	$a->ok( false !== stripos( $clean, 'viewbox' ), 'viewBox остаётся' );
} );

$t->test( 'градиенты и обтравка переживают чистку', function ( $a ) {
	$grad  = '<svg viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient>'
		. '<clipPath id="c"><rect x="0" y="0" width="24" height="24"/></clipPath></defs>'
		. '<path d="M0 0" fill="url(#g)" clip-path="url(#c)"/></svg>';
	$clean = CFS_Icons::sanitize_svg( $grad );

	$a->ok( false !== stripos( $clean, 'lineargradient' ), 'градиент на месте' );
	$a->ok( false !== stripos( $clean, 'clippath' ), 'обтравка на месте' );
	$a->contains( 'url(#g)', $clean, 'ссылка на градиент цела' );
} );

$t->test( 'мусор без корневого элемента отбрасывается', function ( $a ) {
	$a->same( '', CFS_Icons::sanitize_svg( 'просто текст' ) );
	$a->same( '', CFS_Icons::sanitize_svg( '<html><body>нет свг</body></html>' ) );
} );

// ── The settings screen ────────────────────────────────────────────────────

$t->test( 'экран настроек показывает поле и состояние набора', function ( $a ) use ( $cfs_relative ) {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	if ( empty( $admins ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/template.php';
	wp_set_current_user( (int) $admins[0] );

	/**
	 * Render the settings screen into a string.
	 *
	 * @return string
	 */
	$render = function () {
		ob_start();
		( new CFS_Admin( new CFS_DB() ) )->page_settings();

		return (string) ob_get_clean();
	};

	// Built-in set: the screen has to say so and count it.
	cfs_icon_use_dir( '' );
	$html = $render();
	$a->contains( 'name="cfs_icon_dir"', $html, 'поле выведено' );
	$a->contains( 'встроенный набор', $html );

	// A directory that works: the screen has to report what it gave and what
	// stayed, since the library itself no longer shows where an icon came from.
	cfs_icon_use_dir( $cfs_relative );
	$html = $render();
	$a->contains( 'value="' . $cfs_relative . '"', $html, 'путь подставлен в поле' );
	$a->contains( 'Прочитано из директории', $html );
	$a->contains( 'всего доступно иконок', $html, 'размер итогового набора назван' );

	wp_set_current_user( 0 );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────

foreach ( array_unique( $cfs_written ) as $cfs_path ) {
	if ( file_exists( $cfs_path ) ) {
		unlink( $cfs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- test fixture.
	}
}

if ( is_dir( $cfs_dir ) ) {
	rmdir( $cfs_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
}

update_option( CFS_Icons::OPTION_DIR, $cfs_icon_option_before );
CFS_Icons::flush_cache();

exit( $t->summary() );
