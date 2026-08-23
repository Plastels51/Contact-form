<?php
/**
 * Admin screen tests: the forms list, the editor and saving.
 *
 *   docker compose exec -T wordpress php -r "define('WP_ADMIN', true); \
 *     define('WP_USE_THEMES', false); require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-admin.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Thrown in place of a redirect so a handler can be inspected after the fact.
 */
class CFS_Admin_Redirect extends Exception {

	/**
	 * Target URL.
	 *
	 * @var string
	 */
	public $url = '';

	/**
	 * Constructor.
	 *
	 * @param string $url Redirect target.
	 */
	public function __construct( string $url ) {
		parent::__construct( 'redirect' );
		$this->url = $url;
	}
}

/**
 * Thrown in place of wp_die() so one refusal does not end the whole run.
 */
class CFS_Admin_Died extends Exception {}

add_filter(
	'wp_redirect',
	function ( $location ) {
		throw new CFS_Admin_Redirect( (string) $location );
	},
	1
);

// Both handlers: wp_send_json() takes the AJAX path, wp_die() from a screen
// takes the default one. Without DOING_AJAX, wp_send_json() would call a bare
// die() that no filter can intercept — hence the constant in the run command.
foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler' ) as $cfs_die_filter ) {
	add_filter(
		$cfs_die_filter,
		function () {
			return function ( $message ) {
				throw new CFS_Admin_Died( is_string( $message ) ? $message : 'died' );
			};
		}
	);
}

$t       = new CFS_Test_Runner();
$db      = new CFS_DB();
$created = array();

// Every admin screen is capability-gated; run as a real administrator.
$cfs_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);

if ( empty( $cfs_admins ) ) {
	echo "no administrator on this site — cannot run admin tests\n";
	exit( 1 );
}

wp_set_current_user( (int) $cfs_admins[0]->ID );

/**
 * Capture the output of a callable.
 *
 * @param callable $body Callable to run.
 * @return string
 */
function cfs_admin_capture( callable $body ): string {
	ob_start();
	try {
		$body();
	} catch ( CFS_Admin_Redirect $e ) {
		ob_end_clean();
		return 'REDIRECT:' . $e->url;
	} catch ( Throwable $e ) {
		// Discard the buffer before rethrowing, or a wp_die() page leaks into
		// the test output and hides the real result.
		ob_end_clean();
		throw $e;
	}
	return (string) ob_get_clean();
}

echo "\nCFS admin screens\n";
echo str_repeat( '─', 60 ) . "\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Forms list
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'список форм показывает форму, шорткод и счётчики', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$form      = CFS_Form::create( 'Список: тестовая', '[name* who][phone tel][submit]' );
	$created[] = $form->get_id();

	$html = cfs_admin_capture(
		function () use ( $db ) {
			( new CFS_Admin_Forms( $db ) )->render();
		}
	);

	$a->contains( 'Список: тестовая', $html );
	$a->contains( esc_html( $form->get_shortcode() ), $html );
	$a->contains( 'cfs-shortcode', $html );
	$a->contains( 'Дублировать', $html );
	$a->contains( 'Импорт формы', $html );
	// Two fields in the template.
	$a->contains( '<td>2</td>', $html );
} );

$t->test( 'форма с ошибками помечена в списке', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$form      = CFS_Form::create( 'Список: сломанная', '[wombat w][submit]' );
	$created[] = $form->get_id();

	$html = cfs_admin_capture(
		function () use ( $db ) {
			( new CFS_Admin_Forms( $db ) )->render();
		}
	);

	$a->contains( 'cfs-badge--error', $html );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Editor
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'редактор рисует все вкладки и шаблон', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Редактор: тест', '[name* who label="Имя"][submit "Ок"]' );
	$created[] = $form->get_id();

	$_GET['id'] = (string) $form->get_id();
	$html       = cfs_admin_capture(
		function () {
			( new CFS_Admin_Form_Editor() )->render();
		}
	);
	unset( $_GET['id'] );

	foreach ( array( 'template', 'after', 'mail', 'integrations', 'settings' ) as $tab ) {
		$a->contains( 'data-tab="' . $tab . '"', $html );
		$a->contains( 'data-panel="' . $tab . '"', $html );
	}

	$a->contains( 'name="cfs_template"', $html );
	$a->contains( '[name* who label=&quot;Имя&quot;]', $html, 'template is escaped into the textarea' );
	$a->contains( 'cfs-tag-btn', $html );
	$a->contains( 'cfs-preview-btn', $html );
	$a->contains( 'name="cfs_after[mode]"', $html );
	$a->contains( 'name="cfs_mail[admin][subject]"', $html );
	$a->contains( 'name="cfs_settings[container]"', $html );
	$a->contains( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
} );

$t->test( 'редактор перечисляет поля и подстановки писем', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Редактор: подстановки', '[name* who label="Имя"][email mail][submit]' );
	$created[] = $form->get_id();

	$_GET['id'] = (string) $form->get_id();
	$html       = cfs_admin_capture(
		function () {
			( new CFS_Admin_Form_Editor() )->render();
		}
	);
	unset( $_GET['id'] );

	$a->contains( '{who}', $html );
	$a->contains( '{mail}', $html );
	$a->contains( '{all_fields}', $html );
	$a->contains( '{submission_id}', $html );
	$a->contains( '<code>who</code>', $html );
} );

$t->test( 'ошибки компиляции видны в редакторе', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Редактор: ошибки', '[select s][submit]' );
	$created[] = $form->get_id();

	$_GET['id'] = (string) $form->get_id();
	$html       = cfs_admin_capture(
		function () {
			( new CFS_Admin_Form_Editor() )->render();
		}
	);
	unset( $_GET['id'] );

	$a->contains( 'cfs-compile-errors', $html );
	$a->contains( 'options', $html );
} );

$t->test( 'нет интеграций — понятное объяснение', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Редактор: интеграции', '[name* who][submit]' );
	$created[] = $form->get_id();

	// Force an empty registry: the answer must not depend on which add-ons
	// happen to be active on the machine running the suite.
	$empty = function (): array {
		return array();
	};

	add_filter( 'cfs_integrations', $empty, 999 );
	CFS_Integrations::flush_cache();

	$_GET['id'] = (string) $form->get_id();
	$html       = cfs_admin_capture(
		function () {
			( new CFS_Admin_Form_Editor() )->render();
		}
	);
	unset( $_GET['id'] );

	remove_filter( 'cfs_integrations', $empty, 999 );
	CFS_Integrations::flush_cache();

	$a->contains( 'Интеграции не установлены', $html );
} );

$t->test( 'зарегистрированная интеграция получает карточку с настройками', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Редактор: с интеграцией', '[name* who][phone tel][submit]' );
	$created[] = $form->get_id();

	$register = function ( array $items ): array {
		$items['demo_crm'] = array(
			'label'       => 'Демо CRM',
			'description' => 'Тестовая интеграция',
			'fields'      => array(
				'webhook_url' => array(
					'type'     => 'url',
					'label'    => 'Вебхук',
					'required' => true,
				),
				'map'         => array(
					'type'    => 'field_map',
					'label'   => 'Сопоставление',
					'targets' => array( 'TITLE' => 'Заголовок', 'PHONE' => 'Телефон' ),
				),
			),
		);
		return $items;
	};

	add_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$_GET['id'] = (string) $form->get_id();
	$html       = cfs_admin_capture(
		function () {
			( new CFS_Admin_Form_Editor() )->render();
		}
	);
	unset( $_GET['id'] );

	remove_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$a->contains( 'Демо CRM', $html );
	$a->contains( 'name="cfs_integrations[demo_crm][enabled]"', $html );
	$a->contains( 'name="cfs_integrations[demo_crm][settings][webhook_url]"', $html );
	$a->contains( 'cfs-field-map', $html );
	// The map offers the form's own fields as sources.
	$a->contains( 'name="cfs_integrations[demo_crm][settings][map][PHONE]"', $html );
	$a->contains( '<option value="tel"', $html );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Saving
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'сохранение записывает шаблон и все группы настроек', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Сохранение', '[name* who][submit]' );
	$form_id   = $form->get_id();
	$created[] = $form_id;

	$_POST = array(
		'cfs_form_id'  => (string) $form_id,
		'_wpnonce'     => wp_create_nonce( 'cfs_save_form_' . $form_id ),
		'cfs_title'    => 'Переименованная',
		'cfs_template' => '[phone* tel label="Телефон"][submit "Отправить"]',
		'cfs_tab'      => 'after',
		'cfs_after'    => array(
			'mode'           => 'redirect',
			'message'        => 'Готово <a href="/">на главную</a><script>evil()</script>',
			'redirect_url'   => 'https://example.test/thanks/',
			'redirect_delay' => '3',
			'reset_form'     => '1',
		),
		'cfs_mail'     => array(
			'admin' => array(
				'enabled' => '1',
				'subject' => 'Заявка {form_title}',
				'body'    => '{all_fields}',
				'html'    => '1',
			),
		),
		'cfs_settings' => array(
			'container'  => 'dialog',
			'save_to_db' => '1',
			'css_class'  => 'my-form',
		),
	);
	$_REQUEST = $_POST;

	$result = cfs_admin_capture(
		function () {
			( new CFS_Admin_Form_Editor() )->handle_save();
		}
	);

	$_POST    = array();
	$_REQUEST = array();

	$a->contains( 'REDIRECT:', $result, 'save must redirect' );
	$a->contains( 'tab=after', $result, 'and return to the tab that was open' );

	$saved = CFS_Form::load( $form_id );
	$a->same( 'Переименованная', $saved->get_title() );
	$a->contains( '[phone* tel', $saved->get_template() );
	$a->same( 'redirect', $saved->get_after()['mode'] );
	$a->same( 3, $saved->get_after()['redirect_delay'] );
	$a->contains( '<a href="/">на главную</a>', $saved->get_after()['message'] );
	$a->lacks( '<script', $saved->get_after()['message'], 'kses must strip scripts from the message' );
	$a->same( true, $saved->get_mail()['admin']['enabled'] );
	$a->same( 'Заявка {form_title}', $saved->get_mail()['admin']['subject'] );
	$a->same( false, $saved->get_mail()['autoreply']['enabled'], 'unchecked box means disabled' );
	$a->same( 'dialog', $saved->get_settings()['container'] );
	$a->same( 'my-form', $saved->get_settings()['css_class'] );
} );

$t->test( 'сохранение чистит HTML шаблона', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Сохранение: kses', '[name* who][submit]' );
	$form_id   = $form->get_id();
	$created[] = $form_id;

	$_POST = array(
		'cfs_form_id'  => (string) $form_id,
		'_wpnonce'     => wp_create_nonce( 'cfs_save_form_' . $form_id ),
		'cfs_title'    => 'Сохранение: kses',
		'cfs_template' => "<p onclick=\"evil()\">Текст</p><script>bad()</script>[name* who label=\"Цена < 100\"][submit]",
		'cfs_tab'      => 'template',
	);
	$_REQUEST = $_POST;

	cfs_admin_capture(
		function () {
			( new CFS_Admin_Form_Editor() )->handle_save();
		}
	);

	$_POST    = array();
	$_REQUEST = array();

	$saved = CFS_Form::load( $form_id );
	$a->lacks( '<script', $saved->get_template() );
	$a->lacks( 'onclick', $saved->get_template() );
	$a->contains( '<p>Текст</p>', $saved->get_template() );
	$a->same( 'Цена < 100', $saved->get_field( 'who' )['label'], 'a bare < in a tag survives saving' );
} );

$t->test( 'сохранение без прав отклоняется', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Сохранение: права', '[name* who][submit]' );
	$form_id   = $form->get_id();
	$created[] = $form_id;

	$original = get_current_user_id();
	$visitor  = wp_insert_user(
		array(
			'user_login' => 'cfs_test_subscriber_' . wp_rand( 1000, 9999 ),
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'subscriber',
		)
	);

	if ( is_wp_error( $visitor ) ) {
		return;
	}

	wp_set_current_user( (int) $visitor );

	$_POST = array(
		'cfs_form_id'  => (string) $form_id,
		'_wpnonce'     => wp_create_nonce( 'cfs_save_form_' . $form_id ),
		'cfs_template' => '[text hacked][submit]',
	);
	$_REQUEST = $_POST;

	$died = false;
	try {
		cfs_admin_capture(
			function () {
				( new CFS_Admin_Form_Editor() )->handle_save();
			}
		);
	} catch ( Throwable $e ) {
		$died = true;
	}

	$_POST    = array();
	$_REQUEST = array();
	wp_set_current_user( $original );
	wp_delete_user( (int) $visitor );

	$a->same( true, $died, 'a subscriber must not be able to save a form' );
	$a->lacks( 'hacked', CFS_Form::load( $form_id )->get_template() );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Preview
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'предпросмотр возвращает html и ошибки', function ( CFS_Test_Runner $a ) {
	$_POST = array(
		'nonce'    => wp_create_nonce( 'cfs_editor' ),
		'template' => '[name* who label="Имя"][wombat w][submit "Ок"]',
	);
	$_REQUEST = $_POST;

	$json = '';
	ob_start();
	try {
		( new CFS_Admin_Form_Editor() )->ajax_preview();
	} catch ( Throwable $e ) {
		// wp_send_json_* ends the request.
	}
	$json = (string) ob_get_clean();

	$_POST    = array();
	$_REQUEST = array();

	$res = (array) json_decode( $json, true );
	$a->same( true, $res['success'] ?? false, $json );
	$a->contains( '<form class="cfs-form"', (string) ( $res['data']['html'] ?? '' ) );
	$a->same( array( 'who' ), $res['data']['fields'] ?? array() );
	$a->ok( ! empty( $res['data']['errors'] ), 'unknown type is reported' );
} );

$t->test( 'предпросмотр модальной формы разворачивается в обычную', function ( CFS_Test_Runner $a ) {
	$_POST = array(
		'nonce'    => wp_create_nonce( 'cfs_editor' ),
		'template' => '[name* who][submit]',
		'settings' => array( 'container' => 'dialog' ),
	);
	$_REQUEST = $_POST;

	ob_start();
	try {
		( new CFS_Admin_Form_Editor() )->ajax_preview();
	} catch ( Throwable $e ) {
		// Expected.
	}
	$json = (string) ob_get_clean();

	$_POST    = array();
	$_REQUEST = array();

	$res  = (array) json_decode( $json, true );
	$html = (string) ( $res['data']['html'] ?? '' );

	$a->lacks( '<dialog', $html, 'a closed dialog would preview as nothing' );
	$a->contains( '<div class="cfs-form-wrap"', $html );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

exit( $t->summary() );
