<?php
/**
 * Form model integration tests — real posts, real meta.
 *
 *   docker compose exec -T wordpress php -r "define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-form.php';"
 *
 * Every form created here is deleted again at the end of the run.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

$t       = new CFS_Test_Runner();
$created = array();

/**
 * Create a throwaway form and remember it for cleanup.
 *
 * @param array  $created  Registry of created IDs, by reference.
 * @param string $template Template text.
 * @return CFS_Form
 */
function cfs_test_make_form( array &$created, string $template ): CFS_Form {
	$form = CFS_Form::create( 'Тестовая форма', $template );
	if ( ! $form ) {
		throw new RuntimeException( 'CFS_Form::create() returned null' );
	}
	$created[] = $form->get_id();
	return $form;
}

echo "\nCFS form model\n";
echo str_repeat( '─', 60 ) . "\n";

$t->test( 'форма создаётся и перечитывается из базы', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name* n label="Имя"][submit "Ок"]' );
	$a->ok( $form->get_id() > 0, 'post id' );

	$reloaded = CFS_Form::load( $form->get_id() );
	$a->ok( $reloaded instanceof CFS_Form, 'reload' );
	$a->same( '[name* n label="Имя"][submit "Ок"]', $reloaded->get_template() );
	$a->same( 'Имя', $reloaded->get_field( 'n' )['label'] );
} );

$t->test( 'схема кладётся в мету и переиспользуется', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[email* e][submit]' );
	$id   = $form->get_id();

	$stored = get_post_meta( $id, CFS_Form::META_COMPILED, true );
	$a->ok( is_array( $stored ) && ! empty( $stored['fields'] ), 'compiled schema stored' );
	$a->same( md5( $form->get_template() ), (string) get_post_meta( $id, CFS_Form::META_HASH, true ) );
} );

$t->test( 'обратные слэши переживают запись в базу', function ( CFS_Test_Runner $a ) use ( &$created ) {
	// The compiler produced the right pattern all along; what broke it was the
	// trip through post meta. update_post_meta() unslashes whatever it is given,
	// so a value stored without wp_slash() comes back one level of backslashes
	// poorer — "\+7 \(\d{3}\)…" turns into "+7 (d{3})…", which no browser can
	// compile as a regex. Reading the schema from memory hides the damage, so
	// the object cache is dropped first and the form genuinely re-read.
	$form = cfs_test_make_form( $created, "[name n][phone p]\n[text code pattern=\"\\d{6}\"]\n[submit]" );
	$id   = $form->get_id();

	$cache = new ReflectionClass( 'CFS_Form' );
	$prop  = $cache->getProperty( 'cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, array() );
	wp_cache_flush();

	$reloaded = CFS_Form::load( $id );

	// The stored schema is read straight out of post meta, not through the
	// model: a hash mismatch makes the model quietly recompile, which would
	// paper over exactly the corruption under test.
	$stored = get_post_meta( $id, CFS_Form::META_COMPILED, true );
	$stored = (array) ( $stored['fields'] ?? array() );

	$a->same( CFS_Field_Types::LETTERS_PATTERN, (string) ( $stored['n']['attrs']['pattern'] ?? '' ), 'name pattern in meta' );
	$a->same( '\\+7 \\(\\d{3}\\) \\d{3}-\\d{2}-\\d{2}', (string) ( $stored['p']['attrs']['pattern'] ?? '' ), 'phone pattern in meta' );
	$a->same( '\\d{6}', (string) ( $stored['code']['attrs']['pattern'] ?? '' ), 'author pattern in meta' );

	$a->same( '\\d{6}', (string) ( $reloaded->get_field( 'code' )['attrs']['pattern'] ?? '' ), 'author pattern' );
	$a->ok( false !== strpos( (string) $reloaded->get_template(), 'pattern="\\d{6}"' ), 'template keeps its backslashes' );
} );

$t->test( 'схема старого формата пересобирается', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );
	$id   = $form->get_id();

	// A plugin update that adds a key to the compiled field leaves every cached
	// schema without it, and the template hash still matches because nobody
	// edited the template. Only the version says the shape moved on.
	$stale            = get_post_meta( $id, CFS_Form::META_COMPILED, true );
	$stale['version'] = CFS_Form_Compiler::SCHEMA_VERSION - 1;
	unset( $stale['fields']['n']['pattern_from'] );
	update_post_meta( $id, CFS_Form::META_COMPILED, wp_slash( $stale ) );

	$cache = new ReflectionClass( 'CFS_Form' );
	$prop  = $cache->getProperty( 'cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, array() );
	wp_cache_flush();

	$reloaded = CFS_Form::load( $id );
	$a->same( 'type', (string) ( $reloaded->get_field( 'n' )['pattern_from'] ?? '' ), 'recompiled on version mismatch' );
	$a->same(
		CFS_Form_Compiler::SCHEMA_VERSION,
		(int) ( get_post_meta( $id, CFS_Form::META_COMPILED, true )['version'] ?? 0 ),
		'the fresh schema replaced the stale one'
	);
} );

$t->test( 'изменённый напрямую шаблон вызывает перекомпиляцию', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );
	$id   = $form->get_id();

	// Simulate a database edit that bypasses the model.
	update_post_meta( $id, CFS_Form::META_TEMPLATE, '[phone p][submit]' );

	$fresh = new ReflectionClass( 'CFS_Form' );
	$prop  = $fresh->getProperty( 'cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, array() );

	$reloaded = CFS_Form::load( $id );
	$a->ok( null !== $reloaded->get_field( 'p' ), 'recompiled from the new template' );
	$a->same( null, $reloaded->get_field( 'n' ), 'old field must be gone' );
} );

$t->test( 'настройки сохраняются и мержатся с дефолтами', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );

	$after         = $form->get_after();
	$after['mode'] = 'redirect';
	$form->set_group( CFS_Form::META_AFTER, $after );
	$form->save();

	$reloaded = CFS_Form::load( $form->get_id() );
	$a->same( 'redirect', $reloaded->get_after()['mode'] );
	$a->same( 2, $reloaded->get_after()['redirect_delay'], 'untouched default survives' );
	$a->ok( is_array( $reloaded->get_after()['errors'] ), 'nested defaults survive' );
} );

$t->test( 'частично сохранённая группа добирает вложенные дефолты', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );

	// An older version of the plugin might have stored only part of the group.
	update_post_meta( $form->get_id(), CFS_Form::META_MAIL, array( 'admin' => array( 'enabled' => false ) ) );

	$reloaded = CFS_Form::load( $form->get_id() );
	$mail     = $reloaded->get_mail();
	$a->same( false, $mail['admin']['enabled'] );
	$a->ok( array_key_exists( 'subject', $mail['admin'] ), 'missing nested key filled from defaults' );
	$a->ok( array_key_exists( 'autoreply', $mail ), 'missing top-level key filled from defaults' );
} );

$t->test( 'история шаблона пишется при изменении', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );
	$form->set_template( '[phone p][submit]' );
	$form->save();

	$history = CFS_Form::load( $form->get_id() )->get_history();
	$a->same( 1, count( $history ) );
	$a->same( '[name n][submit]', $history[0]['template'] );
} );

$t->test( 'повторное сохранение без правок не плодит историю', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );
	$form->save();
	$form->save();
	$a->same( 0, count( CFS_Form::load( $form->get_id() )->get_history() ) );
} );

$t->test( 'дубликат копирует шаблон и настройки', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form          = cfs_test_make_form( $created, '[email* e][submit]' );
	$after         = $form->get_after();
	$after['message'] = 'Готово!';
	$form->set_group( CFS_Form::META_AFTER, $after );
	$form->save();

	$copy = $form->duplicate();
	$a->ok( $copy instanceof CFS_Form, 'duplicate created' );
	if ( $copy ) {
		$created[] = $copy->get_id();
		$a->same( $form->get_template(), $copy->get_template() );
		$a->same( 'Готово!', $copy->get_after()['message'] );
		$a->contains( 'копия', $copy->get_title() );
	}
} );

$t->test( 'экспорт и импорт восстанавливают форму', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form             = cfs_test_make_form( $created, '[select* s options="Да:1,Нет:2"][submit]' );
	$settings         = $form->get_settings();
	$settings['container'] = 'dialog';
	$form->set_group( CFS_Form::META_SETTINGS, $settings );
	$form->save();

	$json     = wp_json_encode( $form->to_array() );
	$imported = CFS_Form::from_array( json_decode( $json, true ) );

	$a->ok( $imported instanceof CFS_Form, 'imported' );
	if ( $imported ) {
		$created[] = $imported->get_id();
		$a->same( $form->get_template(), $imported->get_template() );
		$a->same( 'dialog', $imported->get_settings()['container'] );
		$a->same( array( '1', '2' ), CFS_Field_Types::option_values( $imported->get_field( 's' ) ) );
	}
} );

$t->test( 'счётчик экземпляров растёт в пределах запроса', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );
	$a->same( 1, $form->next_instance() );
	$a->same( 2, $form->next_instance() );
	$a->same( 3, CFS_Form::load( $form->get_id() )->next_instance(), 'shared across loads of the same form' );
} );

$t->test( 'форма из шаблона работает без записи в базе', function ( CFS_Test_Runner $a ) {
	$form = CFS_Form::from_template( '[phone* p][submit]' );
	$a->same( 0, $form->get_id() );
	$a->ok( null !== $form->get_field( 'p' ) );
	$a->same( 'p', $form->get_role_field( 'phone' ), 'role points at the field name' );
	$a->same( '', $form->get_role_field( 'email' ), 'missing role is empty' );
	$a->same( false, $form->save(), 'unsaved form cannot be persisted' );
} );

$t->test( 'фатальные ошибки шаблона видны модели', function ( CFS_Test_Runner $a ) {
	$broken = CFS_Form::from_template( '<p>совсем без полей</p>' );
	$a->same( true, $broken->has_fatal_errors() );
	$a->same( false, $broken->is_renderable() );

	$fine = CFS_Form::from_template( '[name n][submit]' );
	$a->same( false, $fine->has_fatal_errors() );
	$a->same( true, $fine->is_renderable() );
} );

$t->test( 'многошаговость определяется по [step]', function ( CFS_Test_Runner $a ) {
	$a->same( false, CFS_Form::from_template( '[name n][submit]' )->is_multi_step() );
	$a->same( true, CFS_Form::from_template( '[name n][step][phone p][submit]' )->is_multi_step() );
} );

$t->test( 'форма находится по слагу', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = cfs_test_make_form( $created, '[name n][submit]' );
	$slug = get_post_field( 'post_name', $form->get_id() );
	$a->ok( '' !== $slug, 'slug generated' );

	$found = CFS_Form::load_by_slug( $slug );
	$a->ok( $found instanceof CFS_Form, 'found by slug' );
	if ( $found ) {
		$a->same( $form->get_id(), $found->get_id() );
	}
} );

$t->test( 'удаление формы убирает запись', function ( CFS_Test_Runner $a ) {
	$form = CFS_Form::create( 'Одноразовая', '[name n][submit]' );
	$id   = $form->get_id();
	$a->same( true, $form->delete() );
	$a->same( null, CFS_Form::load( $id ) );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( $post_id, true );
}

exit( $t->summary() );
