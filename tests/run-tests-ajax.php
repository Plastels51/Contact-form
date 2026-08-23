<?php
/**
 * AJAX intake tests — the nine security steps and the stored payload.
 *
 *   docker compose exec -T wordpress php -r "define('DOING_AJAX', true); \
 *     define('WP_USE_THEMES', false); require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-ajax.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

/**
 * Thrown in place of wp_die() so a handler can be called more than once.
 */
class CFS_Ajax_Halt extends Exception {}

add_filter(
	'wp_die_ajax_handler',
	function () {
		return function () {
			throw new CFS_Ajax_Halt();
		};
	}
);

// Rate limiting is exercised by one dedicated test; everywhere else it would
// simply cut the suite off after five submissions.
add_filter( 'cfs_rate_limit', '__return_false', 1 );

$t         = new CFS_Test_Runner();
$db        = new CFS_DB();
$handler   = new CFS_Ajax_Handler( $db );
$created   = array();
$forms     = array();

/**
 * Create a form for a test.
 *
 * @param array  $forms    Registry of created form IDs.
 * @param string $template Template text.
 * @param array  $groups   Settings groups to override.
 * @return CFS_Form
 */
function cfs_ajax_form( array &$forms, string $template, array $groups = array() ): CFS_Form {
	$form = CFS_Form::create( 'AJAX тест', $template );
	if ( ! $form ) {
		throw new RuntimeException( 'form creation failed' );
	}
	$forms[] = $form->get_id();

	foreach ( $groups as $key => $values ) {
		$form->set_group( $key, array_merge( $form->get_group( $key ), $values ) );
	}
	if ( ! empty( $groups ) ) {
		$form->save();
	}

	return $form;
}

/**
 * Post a submission and return the decoded JSON response.
 *
 * @param CFS_Ajax_Handler $handler  Handler under test.
 * @param CFS_Form         $form     Target form.
 * @param array            $fields   Values keyed by field name.
 * @param array            $override System field overrides.
 * @return array
 */
function cfs_ajax_post( CFS_Ajax_Handler $handler, CFS_Form $form, array $fields, array $override = array() ): array {
	$_POST = array_merge(
		array(
			'action'        => 'cfs_submit_form',
			'nonce'         => wp_create_nonce( 'cfs_submit_form' ),
			'cfs_form_id'   => (string) $form->get_id(),
			'cfs_timestamp' => (string) ( time() - 10 ),
			'cfs_hash'      => $form->get_hash(),
			'cfs_instance'  => '1',
			'cfs_page_url'  => 'https://example.test/contacts/',
			'cfs_hp_w'      => '',
			'cfs_hp_x'      => '',
			'cfs'           => $fields,
		),
		$override
	);
	$_REQUEST = $_POST;

	ob_start();
	try {
		$handler->handle_submission();
	} catch ( CFS_Ajax_Halt $e ) {
		// Expected: wp_send_json_* always terminates.
	}
	$raw = (string) ob_get_clean();

	$_POST    = array();
	$_REQUEST = array();

	return (array) json_decode( $raw, true );
}

/**
 * The most recently stored submission row.
 *
 * @param CFS_DB $db Database handler.
 * @return object|null
 */
function cfs_ajax_last_row( CFS_DB $db ) {
	global $wpdb;
	$table = $db->get_submissions_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );
}

echo "\nCFS ajax intake\n";
echo str_repeat( '─', 60 ) . "\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Happy path
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'корректная заявка сохраняется', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	$form = cfs_ajax_form( $forms, '[name* who label="Имя"][phone* tel][email mail][textarea note][submit]' );

	$res = cfs_ajax_post(
		$handler,
		$form,
		array(
			'who'  => 'Иван',
			'tel'  => '+7 (900) 123-45-67',
			'mail' => 'ivan@example.com',
			'note' => 'Здравствуйте',
		)
	);

	$a->same( true, $res['success'] ?? false, wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );

	$row = cfs_ajax_last_row( $db );
	$a->ok( $row, 'row stored' );
	if ( $row ) {
		$created[] = (int) $row->id;
		$a->same( 'Иван', $row->name );
		$a->same( '79001234567', $row->phone, 'phone stored as digits' );
		$a->same( 'ivan@example.com', $row->email );
		$a->same( 'Здравствуйте', $row->comment );
		$a->same( (string) $form->get_id(), $row->form_id );
		$a->same( (string) $form->get_id(), (string) $row->form_post_id );
		$a->same( 'https://example.test/contacts/', $row->page_url );
	}
} );

$t->test( 'JSON заявки содержит поля, схему и extra', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	$form = cfs_ajax_form( $forms, '[name* who label="Имя"][select* topic label="Тема" options="Консультация:consult,Другое:other"][submit]' );

	cfs_ajax_post( $handler, $form, array( 'who' => 'Пётр', 'topic' => 'consult' ) );

	$row = cfs_ajax_last_row( $db );
	$a->ok( $row, 'row stored' );
	if ( ! $row ) {
		return;
	}
	$created[] = (int) $row->id;

	$json = json_decode( $row->form_data_json, true );
	$a->same( 2, $json['_v'] ?? 0, 'payload version' );
	$a->same( $form->get_id(), $json['form']['id'] ?? 0 );
	$a->same( 2, count( $json['fields'] ), 'both fields recorded' );
	$a->same( 'Консультация', $json['fields'][1]['display'], 'option label resolved for display' );
	$a->same( 'consult', $json['extra']['topic'] ?? '', 'extra keeps raw values' );
	$a->same( 'who', $json['_schema'][0]['token'] ?? '', 'schema snapshot present' );
	$a->same( 'Имя', $json['_schema'][0]['label'] ?? '' );
} );

$t->test( 'multicheck сохраняется списком', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	$form = cfs_ajax_form( $forms, '[multicheck ways options="Телефон:phone,Почта:email,Мессенджер:Москва"][submit]' );

	$res = cfs_ajax_post( $handler, $form, array( 'ways' => array( 'phone', 'Москва' ) ) );
	$a->same( true, $res['success'] ?? false, wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );

	$row = cfs_ajax_last_row( $db );
	if ( $row ) {
		$created[] = (int) $row->id;
		$json      = json_decode( $row->form_data_json, true );
		$a->same( array( 'phone', 'Москва' ), $json['fields'][0]['value'] );
		$a->same( 'phone,Москва', $json['extra']['ways'] );
		$a->same( 'Телефон, Мессенджер', $json['fields'][0]['display'] );
	}
} );

$t->test( 'ответ несёт сообщение и настройки редиректа', function ( CFS_Test_Runner $a ) use ( $handler, &$forms, &$created, $db ) {
	$form = cfs_ajax_form(
		$forms,
		'[name* who][submit]',
		array(
			CFS_Form::META_AFTER => array(
				'mode'           => 'message_redirect',
				'message'        => 'Заявка принята',
				'redirect_url'   => 'https://example.test/thanks/',
				'redirect_delay' => 5,
			),
		)
	);

	$res = cfs_ajax_post( $handler, $form, array( 'who' => 'Анна' ) );
	$row = cfs_ajax_last_row( $db );
	if ( $row ) {
		$created[] = (int) $row->id;
	}

	$a->same( 'Заявка принята', $res['data']['message'] ?? '' );
	$a->same( 'https://example.test/thanks/', $res['data']['redirect']['url'] ?? '' );
	$a->same( 5, $res['data']['redirect']['delay'] ?? 0 );
} );

$t->test( 'сохранение можно отключить', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms ) {
	$form = cfs_ajax_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_SETTINGS => array( 'save_to_db' => false ) )
	);

	$before = cfs_ajax_last_row( $db );
	$res    = cfs_ajax_post( $handler, $form, array( 'who' => 'Ольга' ) );
	$after  = cfs_ajax_last_row( $db );

	$a->same( true, $res['success'] ?? false );
	$a->same(
		$before ? (int) $before->id : 0,
		$after ? (int) $after->id : 0,
		'no new row must appear'
	);
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Whitelisting
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'поля не из схемы игнорируются', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );

	cfs_ajax_post(
		$handler,
		$form,
		array(
			'who'    => 'Иван',
			'hacker' => 'полезная нагрузка',
		)
	);

	$row = cfs_ajax_last_row( $db );
	if ( $row ) {
		$created[] = (int) $row->id;
		$a->lacks( 'полезная нагрузка', (string) $row->form_data_json );
		$a->lacks( 'hacker', (string) $row->form_data_json );
	}
} );

$t->test( 'значение вне списка опций отклоняется', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[select* topic options="Да:1,Нет:2"][submit]' );

	$res = cfs_ajax_post( $handler, $form, array( 'topic' => '99' ) );
	$a->same( false, $res['success'] ?? true );
	$a->same( 'validation', $res['data']['code'] ?? '' );
	$a->ok( ! empty( $res['data']['errors']['topic'] ) );
} );

$t->test( 'значение опции «1» проходит строгую проверку', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	// The 2.x pitfall: PHP turned "1" into an int array key, so the whitelist
	// never matched the string coming from $_POST.
	$form = cfs_ajax_form( $forms, '[radio* answer options="Да:1,Нет:2"][submit]' );

	$res = cfs_ajax_post( $handler, $form, array( 'answer' => '1' ) );
	$a->same( true, $res['success'] ?? false, wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );

	$row = cfs_ajax_last_row( $db );
	if ( $row ) {
		$created[] = (int) $row->id;
	}
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Validation
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'пустое обязательное поле возвращает ошибку по имени', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][phone* tel][submit]' );

	$res = cfs_ajax_post( $handler, $form, array( 'who' => '', 'tel' => '' ) );

	$a->same( false, $res['success'] ?? true );
	$a->same( 'validation', $res['data']['code'] ?? '' );
	$a->ok( ! empty( $res['data']['errors']['who'] ), 'error for who' );
	$a->ok( ! empty( $res['data']['errors']['tel'] ), 'error for tel' );
} );

$t->test( 'неотмеченное обязательное согласие — ошибка', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][agreement* consent][submit]' );

	$res = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ) );
	$a->same( false, $res['success'] ?? true );
	$a->ok( ! empty( $res['data']['errors']['consent'] ) );
} );

$t->test( 'некорректный телефон и email отклоняются', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	$form = cfs_ajax_form( $forms, '[phone* tel][email mail][submit]' );

	$res = cfs_ajax_post( $handler, $form, array( 'tel' => '123', 'mail' => 'не-почта' ) );
	$a->ok( ! empty( $res['data']['errors']['tel'] ), 'short phone' );
	$a->ok( ! empty( $res['data']['errors']['mail'] ), 'malformed email must not be silently emptied' );

	$ok = cfs_ajax_post( $handler, $form, array( 'tel' => '+7 (900) 123-45-67', 'mail' => 'a@b.ru' ) );
	$a->same( true, $ok['success'] ?? false, wp_json_encode( $ok, JSON_UNESCAPED_UNICODE ) );

	$row = cfs_ajax_last_row( $db );
	if ( $row ) {
		$created[] = (int) $row->id;
	}
} );

$t->test( 'фильтр cfs_validate_field может забраковать поле', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );

	$filter = function ( $error, $name ) {
		return 'who' === $name ? 'Нельзя' : $error;
	};
	add_filter( 'cfs_validate_field', $filter, 10, 2 );
	$res = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ) );
	remove_filter( 'cfs_validate_field', $filter, 10 );

	$a->same( 'Нельзя', $res['data']['errors']['who'] ?? '' );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Security steps
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'неверный nonce отдаёт код nonce', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );
	$res  = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ), array( 'nonce' => 'мусор' ) );

	$a->same( false, $res['success'] ?? true );
	$a->same( 'nonce', $res['data']['code'] ?? '' );
} );

$t->test( 'заполненный honeypot отбрасывает заявку', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );
	$res  = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ), array( 'cfs_hp_w' => 'bot' ) );

	$a->same( 'honeypot', $res['data']['code'] ?? '' );
} );

$t->test( 'слишком быстрая и слишком старая отправка отклоняются', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );

	$fast = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ), array( 'cfs_timestamp' => (string) time() ) );
	$a->same( 'timing', $fast['data']['code'] ?? '' );

	$old = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ), array( 'cfs_timestamp' => (string) ( time() - DAY_IN_SECONDS - 60 ) ) );
	$a->same( 'timing', $old['data']['code'] ?? '' );

	$missing = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ), array( 'cfs_timestamp' => '0' ) );
	$a->same( 'timing', $missing['data']['code'] ?? '' );
} );

$t->test( 'несуществующая форма отклоняется', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );
	$res  = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ), array( 'cfs_form_id' => '99999999' ) );

	$a->same( 'unknown_form', $res['data']['code'] ?? '' );
} );

$t->test( 'черновик формы не принимает заявки', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );
	wp_update_post( array( 'ID' => $form->get_id(), 'post_status' => 'draft' ) );

	$res = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ) );
	$a->same( 'unknown_form', $res['data']['code'] ?? '' );

	wp_update_post( array( 'ID' => $form->get_id(), 'post_status' => 'publish' ) );
} );

$t->test( 'превышение лимита отклоняется', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );

	add_filter( 'cfs_rate_limit', '__return_true', 99 );
	$res = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ) );
	remove_filter( 'cfs_rate_limit', '__return_true', 99 );

	$a->same( 'rate_limit', $res['data']['code'] ?? '' );
} );

$t->test( 'запрещённое слово отклоняет заявку', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][textarea note][submit]' );

	update_option( 'cfs_banned_words', "виагра\nказино" );

	// Upper case matters: stripos() folds only ASCII, so a Cyrillic list used
	// to be case-sensitive in practice.
	$upper = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван', 'note' => 'Лучшее КАЗИНО тут' ) );
	$lower = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван', 'note' => 'обычное казино' ) );

	delete_option( 'cfs_banned_words' );

	$a->same( 'spam', $upper['data']['code'] ?? '' );
	$a->same( 'spam', $lower['data']['code'] ?? '' );
} );

$t->test( 'фильтр cfs_spam_check блокирует заявку', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );

	add_filter( 'cfs_spam_check', '__return_true', 10 );
	$res = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ) );
	remove_filter( 'cfs_spam_check', '__return_true', 10 );

	$a->same( 'spam', $res['data']['code'] ?? '' );
} );

$t->test( 'устаревший хеш схемы не блокирует отправку', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );

	$res = cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ), array( 'cfs_hash' => 'устаревший' ) );
	$a->same( true, $res['success'] ?? false, wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );

	$row = cfs_ajax_last_row( $db );
	if ( $row ) {
		$created[] = (int) $row->id;
		$json      = json_decode( $row->form_data_json, true );
		$a->same( true, $json['form']['stale'] ?? false, 'staleness is recorded' );
	}
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Hooks
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'cfs_before_save и cfs_after_save вызываются', function ( CFS_Test_Runner $a ) use ( $handler, $db, &$forms, &$created ) {
	$form = cfs_ajax_form( $forms, '[name* who][submit]' );

	$seen_before = false;
	$seen_after  = 0;

	$before = function ( $data, $form_id ) use ( &$seen_before, $form ) {
		$seen_before = ( (int) $form_id === $form->get_id() );
		return $data;
	};
	$after = function ( $id ) use ( &$seen_after ) {
		$seen_after = (int) $id;
	};

	add_filter( 'cfs_before_save', $before, 10, 2 );
	add_action( 'cfs_after_save', $after, 10, 1 );
	cfs_ajax_post( $handler, $form, array( 'who' => 'Иван' ) );
	remove_filter( 'cfs_before_save', $before, 10 );
	remove_action( 'cfs_after_save', $after, 10 );

	$row = cfs_ajax_last_row( $db );
	if ( $row ) {
		$created[] = (int) $row->id;
	}

	$a->same( true, $seen_before, 'cfs_before_save fired with the form id' );
	$a->ok( $seen_after > 0, 'cfs_after_save fired with the submission id' );
} );

$t->test( 'своё сообщение об ошибке валидации подставляется', function ( CFS_Test_Runner $a ) use ( $handler, &$forms ) {
	$form = cfs_ajax_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_AFTER => array( 'errors' => array( 'validation' => 'Проверьте поля!' ) ) )
	);

	$res = cfs_ajax_post( $handler, $form, array( 'who' => '' ) );
	$a->same( 'Проверьте поля!', $res['data']['message'] ?? '' );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ( array_unique( $created ) as $submission_id ) {
	$db->delete_submission( (int) $submission_id );
}
foreach ( array_unique( $forms ) as $post_id ) {
	wp_delete_post( $post_id, true );
}

exit( $t->summary() );
