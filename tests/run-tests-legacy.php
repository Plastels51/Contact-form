<?php
/**
 * Compatibility module: adapter, scanner, migration wizard and isolation.
 *
 * Skips itself when includes/legacy/ has been deleted — that is the expected
 * end state, not a failure.
 *
 *   docker compose exec -T wordpress php -r "define('DOING_AJAX', true); \
 *     define('WP_ADMIN', true); define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-legacy.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

$t = new CFS_Test_Runner();

echo "\nCFS legacy module\n";
echo str_repeat( '─', 60 ) . "\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Isolation — this part runs whether or not the module is installed
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'ядро не ссылается на legacy-классы', function ( CFS_Test_Runner $a ) {
	$root  = dirname( __DIR__ );
	$files = array_merge(
		(array) glob( $root . '/includes/*.php' ),
		(array) glob( $root . '/includes/admin/*.php' ),
		array( $root . '/contact-form-submissions.php', $root . '/uninstall.php' )
	);

	$offenders = array();

	foreach ( $files as $file ) {
		$contents = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$name     = basename( (string) $file );

		foreach ( array( 'CFS_Form_Builder', 'CFS_Legacy_Adapter', 'CFS_Legacy_Scanner', 'CFS_Legacy_Wizard' ) as $class ) {
			if ( false !== strpos( $contents, $class ) ) {
				$offenders[] = $name . ' → ' . $class;
			}
		}

		// CFS_Legacy itself may appear exactly once, inside the guarded block
		// of the main plugin file.
		if ( 'contact-form-submissions.php' !== $name && false !== strpos( $contents, 'CFS_Legacy' ) ) {
			$offenders[] = $name . ' → CFS_Legacy';
		}
	}

	$a->same( array(), $offenders, 'core must not name the compatibility module' );
} );

$t->test( 'подключение legacy защищено проверкой файла', function ( CFS_Test_Runner $a ) {
	$main = (string) file_get_contents( dirname( __DIR__ ) . '/contact-form-submissions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$a->contains( "file_exists( CFS_PLUGIN_DIR . 'includes/legacy/class-cfs-legacy.php' )", $main );
	$a->lacks( "require_once CFS_PLUGIN_DIR . 'includes/class-cfs-form-builder.php'", $main );
} );

if ( ! class_exists( 'CFS_Legacy_Adapter' ) ) {
	echo "\nмодуль совместимости удалён — остальные проверки пропущены\n";
	exit( $t->summary() );
}

$created = array();
$forms   = array();

/**
 * IDs of every form that currently exists.
 *
 * Migration creates forms as a side effect, and the cleanup below must delete
 * only those — an earlier version of this file collected every form on the
 * site and wiped the ones the developer had made by hand.
 *
 * @return int[]
 */
function cfs_legacy_form_ids(): array {
	$ids = array();

	foreach ( CFS_Form::all() as $form ) {
		$ids[] = $form->get_id();
	}

	return $ids;
}

/* ─────────────────────────────────────────────────────────────────────────
 * Adapter
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'простой шорткод превращается в рабочий шаблон', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields'      => 'name*,phone*,email',
			'title'       => 'Обратный звонок',
			'button_text' => 'Жду звонка',
		)
	);

	$a->same( 'Обратный звонок', $converted['title'] );
	$a->contains( '<h3 class="cfs-form-title">Обратный звонок</h3>', $converted['template'] );
	$a->contains( '[submit "Жду звонка"]', $converted['template'] );

	$form = CFS_Form::from_template( $converted['template'] );

	$a->same( array( 'name', 'phone', 'email' ), array_keys( $form->get_fields() ) );
	$a->same( true, $form->get_field( 'name' )['required'] );
	$a->same( true, $form->get_field( 'phone' )['required'] );
	$a->same( false, $form->get_field( 'email' )['required'], 'star notation makes the rest optional' );
	$a->same( false, $form->has_fatal_errors(), wp_json_encode( $form->get_errors(), JSON_UNESCAPED_UNICODE ) );
} );

$t->test( 'без звёздочек действуют умолчания 2.x', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert( array( 'fields' => 'name,phone,email,comment' ) );
	$form      = CFS_Form::from_template( $converted['template'] );

	$a->same( true, $form->get_field( 'name' )['required'], 'name was required by default in 2.x' );
	$a->same( true, $form->get_field( 'phone' )['required'] );
	$a->same( false, $form->get_field( 'email' )['required'] );
	$a->same( false, $form->get_field( 'comment' )['required'] );
} );

$t->test( 'метки, опции, строки и иконки переносятся', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields'         => 'name*,select*,comment',
			'name_label'     => 'Ваше имя',
			'name_icon'      => 'user',
			'select_label'   => 'Тема',
			'select_options' => 'Консультация:consult,Расчёт:calc',
			'comment_rows'   => '6',
		)
	);

	$form = CFS_Form::from_template( $converted['template'] );

	$a->same( 'Ваше имя', $form->get_field( 'name' )['label'] );
	$a->same( 'user', $form->get_field( 'name' )['attrs']['icon'] );
	$a->same( 'Тема', $form->get_field( 'select' )['label'] );
	$a->same( array( 'consult', 'calc' ), CFS_Field_Types::option_values( $form->get_field( 'select' ) ) );
	$a->same( 'textarea', $form->get_field( 'comment' )['type'], 'comment became textarea' );
	$a->same( '6', $form->get_field( 'comment' )['attrs']['rows'] );
} );

$t->test( 'индексированные поля сохраняют имена', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields'           => 'name,name_2,comment_2',
			'name_2_label'     => 'Второе имя',
			'comment_2_label'  => 'Доп. комментарий',
		)
	);

	$form = CFS_Form::from_template( $converted['template'] );

	$a->same( array( 'name', 'name_2', 'comment_2' ), array_keys( $form->get_fields() ) );
	$a->same( 'Второе имя', $form->get_field( 'name_2' )['label'] );
	$a->same( 'textarea', $form->get_field( 'comment_2' )['type'] );
} );

$t->test( 'скрытое поле берёт имя из hidden_name', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields'       => 'name*,hidden',
			'hidden_name'  => 'utm_source',
			'hidden_value' => 'google',
		)
	);

	$form = CFS_Form::from_template( $converted['template'] );

	$a->ok( null !== $form->get_field( 'utm_source' ), 'hidden field named after hidden_name' );
	$a->same( 'google', $form->get_field( 'utm_source' )['attrs']['value'] );
} );

$t->test( 'статический текст становится HTML', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields'     => 'text,name*',
			'text_label' => 'Заполните форму для <strong>консультации</strong>',
		)
	);

	$a->contains( '<p>Заполните форму для <strong>консультации</strong></p>', $converted['template'] );

	$form = CFS_Form::from_template( $converted['template'] );
	$a->same( array( 'name' ), array_keys( $form->get_fields() ) );
} );

$t->test( 'шаги переносятся вместе с подписями', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields' => 'name*,phone*|comment',
			'steps'  => 'Контакты|Детали',
		)
	);

	$form   = CFS_Form::from_template( $converted['template'] );
	$schema = $form->get_schema();

	$a->same( true, $form->is_multi_step() );
	$a->same( array( 'Контакты', 'Детали' ), $schema['step_labels'] );
	$a->same( array( array( 'name', 'phone' ), array( 'comment' ) ), $schema['steps'] );
} );

$t->test( 'модал и редирект переезжают в настройки', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields'            => 'name*,phone*',
			'container'         => 'dialog',
			'modal_button_text' => 'Заказать звонок',
			'redirect_url'      => 'https://example.test/thanks/',
			'redirect_delay'    => '4',
			'success_message'   => 'Готово!',
			'class'             => 'cfs-form--cols-2',
		)
	);

	$settings = $converted['groups'][ CFS_Form::META_SETTINGS ];
	$after    = $converted['groups'][ CFS_Form::META_AFTER ];

	$a->same( 'dialog', $settings['container'] );
	$a->same( 'Заказать звонок', $settings['modal_button_text'] );
	$a->same( 'cfs-form--cols-2', $settings['css_class'] );
	$a->same( true, $after['close_modal'], '2.x always closed the modal' );
	$a->same( 'message_redirect', $after['mode'] );
	$a->same( 'https://example.test/thanks/', $after['redirect_url'] );
	$a->same( 4, $after['redirect_delay'] );
	$a->same( 'Готово!', $after['message'] );
} );

$t->test( 'кавычки в метке не ломают тег', function ( CFS_Test_Runner $a ) {
	$converted = CFS_Legacy_Adapter::convert(
		array(
			'fields'     => 'name*',
			'name_label' => 'Скажите "как вас зовут"',
		)
	);

	$form = CFS_Form::from_template( $converted['template'] );
	$a->same( 'Скажите "как вас зовут"', $form->get_field( 'name' )['label'] );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Scanner
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'сканер находит шорткоды и группирует одинаковые', function ( CFS_Test_Runner $a ) {
	$content = "<p>Раз</p>[contact_form fields=\"name*,phone*\"]\n"
		. "<p>Два</p>[contact_form fields=\"name*,phone*\"]\n"
		. "<p>Три</p>[contact_form fields=\"email\" title=\"Другая\"]\n"
		. '<p>Уже мигрировано</p>[contact_form id="42"]';

	$found = CFS_Legacy_Scanner::extract( $content );

	$a->same( 3, count( $found ), 'the migrated shortcode is skipped' );

	$groups = CFS_Legacy_Scanner::group( $found );
	$a->same( 2, count( $groups ), 'identical shortcodes collapse into one group' );
} );

$t->test( 'порядок атрибутов не влияет на группировку', function ( CFS_Test_Runner $a ) {
	$one = CFS_Legacy_Scanner::hash( array( 'fields' => 'name*', 'title' => 'A' ) );
	$two = CFS_Legacy_Scanner::hash( array( 'title' => 'A', 'fields' => 'name*' ) );

	$a->same( $one, $two );
} );

$t->test( 'сканер обходит контент сайта', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$post_id   = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Страница со старой формой',
			'post_status'  => 'publish',
			'post_content' => 'Текст [contact_form fields="name*,phone*" title="Старая"] дальше',
		)
	);
	$created[] = (int) $post_id;

	$found = CFS_Legacy_Scanner::scan();
	$ours  = array_filter(
		$found,
		function ( array $item ) use ( $post_id ): bool {
			return 'post' === $item['source'] && (int) $item['id'] === (int) $post_id;
		}
	);

	$a->same( 1, count( $ours ) );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Migration
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'миграция создаёт форму, заменяет шорткод и делает бэкап', function ( CFS_Test_Runner $a ) use ( &$created, &$forms ) {
	$original  = 'До [contact_form fields="name*,phone*" title="Мигрируемая"] после';
	$post_id   = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Мигрируемая страница',
			'post_status'  => 'publish',
			'post_content' => $original,
		)
	);
	$created[] = (int) $post_id;

	$before = cfs_legacy_form_ids();
	$wizard = new CFS_Legacy_Wizard();
	$result = $wizard->migrate();

	$a->ok( $result['forms'] >= 1, 'at least one form created' );
	$a->ok( $result['replacements'] >= 1, 'at least one shortcode replaced' );

	foreach ( array_diff( cfs_legacy_form_ids(), $before ) as $new_id ) {
		$forms[] = (int) $new_id;
	}

	$content = (string) get_post_field( 'post_content', (int) $post_id );
	$a->contains( '[contact_form id="', $content );
	$a->lacks( 'fields="name*,phone*"', $content );
	$a->contains( 'До ', $content, 'surrounding text is untouched' );
	$a->contains( ' после', $content );

	$a->same( $original, (string) get_post_meta( (int) $post_id, CFS_Legacy_Wizard::META_BACKUP, true ) );

	// And the replacement actually renders.
	$rendered = do_shortcode( $content );
	$a->contains( '<form class="cfs-form"', $rendered );
} );

$t->test( 'откат возвращает исходный текст', function ( CFS_Test_Runner $a ) use ( &$created, &$forms ) {
	$original  = 'Текст [contact_form fields="email*" title="Откатываемая"] конец';
	$post_id   = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Откатываемая страница',
			'post_status'  => 'publish',
			'post_content' => $original,
		)
	);
	$created[] = (int) $post_id;

	$before = cfs_legacy_form_ids();
	$wizard = new CFS_Legacy_Wizard();
	$wizard->migrate();

	foreach ( array_diff( cfs_legacy_form_ids(), $before ) as $new_id ) {
		$forms[] = (int) $new_id;
	}

	$a->lacks( 'fields="email*"', (string) get_post_field( 'post_content', (int) $post_id ) );

	$restored = $wizard->rollback();

	$a->ok( $restored >= 1 );
	$a->same( $original, (string) get_post_field( 'post_content', (int) $post_id ) );
	$a->same( '', (string) get_post_meta( (int) $post_id, CFS_Legacy_Wizard::META_BACKUP, true ) );
} );

$t->test( 'привязка старых заявок проставляет form_post_id', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$db   = new CFS_DB();
	$form = CFS_Form::create( 'Цель привязки', '[name* who][submit]' );
	$forms[] = $form->get_id();

	$submission_id = (int) $db->insert_submission(
		array(
			'form_id' => 'cfs_oldform1',
			'name'    => 'Иван',
			'extra'   => array(),
		)
	);

	$_POST['cfs_relink'] = array( 'cfs_oldform1' => (string) $form->get_id() );
	$updated             = ( new CFS_Legacy_Wizard() )->relink();
	unset( $_POST['cfs_relink'] );

	$a->ok( $updated >= 1 );

	$row = $db->get_submission( $submission_id );
	$a->same( $form->get_id(), (int) $row->form_post_id );

	$db->delete_submission( $submission_id );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Rendering the old syntax
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'старый шорткод всё ещё выводит форму', function ( CFS_Test_Runner $a ) {
	$html = do_shortcode( '[contact_form fields="name*,phone*" title="Ещё не мигрировано"]' );

	$a->contains( '<form', $html );
	$a->contains( 'cfs-form', $html );
	$a->contains( 'Ещё не мигрировано', $html );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
foreach ( array_unique( $forms ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
delete_option( CFS_Legacy::OPTION_MIGRATED );

exit( $t->summary() );
