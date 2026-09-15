<?php
/**
 * Post-submit actions: mail templates, mailer, action runner.
 *
 *   docker compose exec -T wordpress php -r "define('DOING_AJAX', true); \
 *     define('WP_ADMIN', true); define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-actions.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

$t       = new CFS_Test_Runner();
$db      = new CFS_DB();
$created = array();
$forms   = array();

/**
 * Captured wp_mail() calls.
 *
 * @var array
 */
$GLOBALS['cfs_sent_mail'] = array();

// The test container has no MTA; intercept before PHPMailer is involved.
add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) {
		$GLOBALS['cfs_sent_mail'][] = $atts;
		return true;
	},
	10,
	2
);

/**
 * Build a form plus a matching submission payload.
 *
 * @param array  $forms    Registry of form IDs.
 * @param string $template Template text.
 * @param array  $groups   Settings groups.
 * @return CFS_Form
 */
function cfs_actions_form( array &$forms, string $template, array $groups = array() ): CFS_Form {
	$form = CFS_Form::create( 'Действия: тест', $template );
	if ( ! $form ) {
		throw new RuntimeException( 'form creation failed' );
	}
	$forms[] = $form->get_id();

	foreach ( $groups as $key => $values ) {
		$form->set_group( $key, array_replace_recursive( $form->get_group( $key ), $values ) );
	}
	$form->save();

	return $form;
}

/**
 * A submission payload shaped like the AJAX handler produces.
 *
 * @param CFS_Form $form   Form.
 * @param array    $values name => value.
 * @return array
 */
function cfs_actions_data( CFS_Form $form, array $values ): array {
	$fields = array();
	$roles  = array(
		'name'    => '',
		'email'   => '',
		'phone'   => '',
		'comment' => '',
	);

	foreach ( $form->get_fields() as $name => $field ) {
		if ( empty( $field['submits'] ) ) {
			continue;
		}
		$value    = $values[ $name ] ?? '';
		$fields[] = array(
			'name'    => $name,
			'type'    => (string) $field['type'],
			'label'   => wp_strip_all_tags( (string) $field['label'] ),
			'value'   => $value,
			'display' => CFS_Field_Types::display( $field, $value ),
		);

		$role = (string) $field['role'];
		if ( '' !== $role && isset( $roles[ $role ] ) && '' === $roles[ $role ] ) {
			$roles[ $role ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}
	}

	return array_merge(
		$roles,
		array(
			'_v'           => 2,
			'form'         => array( 'id' => $form->get_id(), 'title' => $form->get_title() ),
			'form_id'      => (string) $form->get_id(),
			'form_post_id' => $form->get_id(),
			'fields'       => $fields,
			'page_url'     => 'https://example.test/page/',
			'ip_address'   => '203.0.113.9',
			'user_agent'   => 'TestAgent/1.0',
		)
	);
}

echo "\nCFS post-submit actions\n";
echo str_repeat( '─', 60 ) . "\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Mail templates
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'подстановки заменяются значениями полей', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$form    = cfs_actions_form( $forms, '[name* who label="Имя"][email mail][submit]' );
	$data    = cfs_actions_data( $form, array( 'who' => 'Иван', 'mail' => 'ivan@example.com' ) );
	$context = CFS_Mail_Template::context( $data, 42, $form );

	$a->same( 'Иван', $context['who'] );
	$a->same( 'Имя', $context['who_label'] );
	$a->same( '42', $context['submission_id'] );

	$rendered = CFS_Mail_Template::render( 'Здравствуйте, {who}! Ваш email: {mail}', $context, CFS_Mail_Template::MODE_TEXT, $data );
	$a->same( 'Здравствуйте, Иван! Ваш email: ivan@example.com', $rendered );
} );

$t->test( 'неизвестная подстановка остаётся видимой', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$form    = cfs_actions_form( $forms, '[name* who][submit]' );
	$data    = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$context = CFS_Mail_Template::context( $data, 1, $form );

	$a->same( 'Привет {опечатка}', CFS_Mail_Template::render( 'Привет {опечатка}', $context, CFS_Mail_Template::MODE_TEXT, $data ) );
	$a->same( 'Привет {nosuchfield}', CFS_Mail_Template::render( 'Привет {nosuchfield}', $context, CFS_Mail_Template::MODE_TEXT, $data ) );
} );

$t->test( '{all_fields} строит таблицу и текстовый список', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$form    = cfs_actions_form( $forms, '[name* who label="Имя"][select topic label="Тема" options="Консультация:consult"][hidden utm][submit]' );
	$data    = cfs_actions_data( $form, array( 'who' => 'Иван', 'topic' => 'consult', 'utm' => 'google' ) );
	$context = CFS_Mail_Template::context( $data, 1, $form );

	$html = CFS_Mail_Template::render( '{all_fields}', $context, CFS_Mail_Template::MODE_HTML, $data );
	$a->contains( '<table', $html );
	$a->contains( 'Консультация', $html );
	$a->lacks( 'google', $html, 'hidden fields stay out of the letter' );

	$text = CFS_Mail_Template::render( '{all_fields}', $context, CFS_Mail_Template::MODE_TEXT, $data );
	$a->contains( 'Имя: Иван', $text );
	$a->lacks( '<table', $text );
} );

$t->test( 'значение экранируется в HTML-письме', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$form    = cfs_actions_form( $forms, '[textarea note][submit]' );
	$data    = cfs_actions_data( $form, array( 'note' => '<script>alert(1)</script>' ) );
	$context = CFS_Mail_Template::context( $data, 1, $form );

	$html = CFS_Mail_Template::render( '{note}', $context, CFS_Mail_Template::MODE_HTML, $data );
	$a->lacks( '<script>', $html );
	$a->contains( '&lt;script&gt;', $html );
} );

$t->test( 'перевод строки не может подделать заголовок', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$form    = cfs_actions_form( $forms, '[text who][submit]' );
	$data    = cfs_actions_data( $form, array( 'who' => "Иван\r\nBcc: attacker@example.com" ) );
	$context = CFS_Mail_Template::context( $data, 1, $form );

	$header = CFS_Mail_Template::render( '{who}', $context, CFS_Mail_Template::MODE_HEADER, $data );
	$a->lacks( "\n", $header );
	$a->lacks( "\r", $header );

	$subject = CFS_Mail_Template::render( 'Заявка от {who}', $context, CFS_Mail_Template::MODE_SUBJECT, $data );
	$a->lacks( "\n", $subject );
} );

$t->test( 'список получателей берёт только валидные адреса', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$form    = cfs_actions_form( $forms, '[email mail][submit]' );
	$data    = cfs_actions_data( $form, array( 'mail' => 'user@example.com' ) );
	$context = CFS_Mail_Template::context( $data, 1, $form );

	$a->same(
		array( 'user@example.com', 'second@example.com' ),
		CFS_Mail_Template::recipients( '{mail}, second@example.com, мусор', $context )
	);
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Mailer
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'письмо администратору уходит по шаблону формы', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$GLOBALS['cfs_sent_mail'] = array();

	$form = cfs_actions_form(
		$forms,
		'[name* who label="Имя"][email mail][submit]',
		array(
			CFS_Form::META_MAIL => array(
				'admin' => array(
					'enabled' => true,
					'to'      => 'sales@example.com',
					'subject' => 'Заявка от {who}',
					'body'    => '<p>{all_fields}</p>',
					'html'    => true,
				),
			),
		)
	);

	$data   = cfs_actions_data( $form, array( 'who' => 'Иван', 'mail' => 'ivan@example.com' ) );
	$result = ( new CFS_Mailer() )->send( $form, 'admin', $data, 7 );

	$a->same( true, $result->is_ok(), $result->message );
	$a->same( 1, count( $GLOBALS['cfs_sent_mail'] ) );

	$sent = $GLOBALS['cfs_sent_mail'][0];
	$a->same( array( 'sales@example.com' ), (array) $sent['to'] );
	$a->same( 'Заявка от Иван', $sent['subject'] );
	$a->contains( 'Иван', $sent['message'] );
	$a->contains( 'text/html', implode( ' ', (array) $sent['headers'] ) );
	$a->contains( 'Reply-To: "Иван" <ivan@example.com>', implode( ' ', (array) $sent['headers'] ) );
} );

$t->test( 'без явного получателя письмо идёт админу сайта', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$GLOBALS['cfs_sent_mail'] = array();

	$form = cfs_actions_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_MAIL => array( 'admin' => array( 'enabled' => true ) ) )
	);

	$data = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	( new CFS_Mailer() )->send( $form, 'admin', $data, 0 );

	$a->same( 1, count( $GLOBALS['cfs_sent_mail'] ) );
	$a->same( array( (string) get_option( 'admin_email' ) ), (array) $GLOBALS['cfs_sent_mail'][0]['to'] );
} );

$t->test( 'автоответ уходит на email из формы', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$GLOBALS['cfs_sent_mail'] = array();

	$form = cfs_actions_form(
		$forms,
		'[name* who][email mail][submit]',
		array(
			CFS_Form::META_MAIL => array(
				'autoreply' => array(
					'enabled' => true,
					'to'      => '{mail}',
					'subject' => 'Спасибо, {who}',
					'body'    => 'Мы получили вашу заявку.',
					'html'    => false,
				),
			),
		)
	);

	$data   = cfs_actions_data( $form, array( 'who' => 'Иван', 'mail' => 'ivan@example.com' ) );
	$result = ( new CFS_Mailer() )->send( $form, 'autoreply', $data, 0 );

	$a->same( true, $result->is_ok(), $result->message );
	$a->same( array( 'ivan@example.com' ), (array) $GLOBALS['cfs_sent_mail'][0]['to'] );
	$a->same( 'Спасибо, Иван', $GLOBALS['cfs_sent_mail'][0]['subject'] );
	$a->contains( 'text/plain', implode( ' ', (array) $GLOBALS['cfs_sent_mail'][0]['headers'] ) );
} );

$t->test( 'выключенное письмо не отправляется', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$GLOBALS['cfs_sent_mail'] = array();

	$form   = cfs_actions_form( $forms, '[name* who][submit]' );
	$data   = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$result = ( new CFS_Mailer() )->send( $form, 'autoreply', $data, 0 );

	$a->same( CFS_Action_Result::STATUS_SKIPPED, $result->status );
	$a->same( 0, count( $GLOBALS['cfs_sent_mail'] ) );
} );

$t->test( 'подделка Bcc через поле не проходит в заголовки', function ( CFS_Test_Runner $a ) use ( &$forms ) {
	$GLOBALS['cfs_sent_mail'] = array();

	$form = cfs_actions_form(
		$forms,
		'[text who][email mail][submit]',
		array(
			CFS_Form::META_MAIL => array(
				'admin' => array(
					'enabled'   => true,
					'to'        => 'sales@example.com',
					'from_name' => '{who}',
					'subject'   => 'От {who}',
				),
			),
		)
	);

	$data = cfs_actions_data(
		$form,
		array(
			'who'  => "Иван\r\nBcc: attacker@example.com",
			'mail' => 'ivan@example.com',
		)
	);

	( new CFS_Mailer() )->send( $form, 'admin', $data, 0 );

	$headers = (array) $GLOBALS['cfs_sent_mail'][0]['headers'];

	// The attack is a *new header line*, not the text itself: the visitor's
	// name legitimately ends up inside the From display name.
	foreach ( $headers as $header ) {
		$a->lacks( "\r", (string) $header, 'no CR in a header' );
		$a->lacks( "\n", (string) $header, 'no LF in a header' );
		$a->not( 0 === stripos( (string) $header, 'bcc:' ), 'no injected Bcc header' );
	}

	$a->same( 1, count( array_filter( $headers, function ( $h ) {
		return 0 === stripos( (string) $h, 'from:' );
	} ) ), 'exactly one From header' );

	$a->lacks( "\n", (string) $GLOBALS['cfs_sent_mail'][0]['subject'] );
	$a->lacks( "\r", (string) $GLOBALS['cfs_sent_mail'][0]['subject'] );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Action runner
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'результаты действий пишутся в журнал заявки', function ( CFS_Test_Runner $a ) use ( $db, &$forms, &$created ) {
	$GLOBALS['cfs_sent_mail'] = array();

	$form = cfs_actions_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_MAIL => array( 'admin' => array( 'enabled' => true, 'to' => 'sales@example.com' ) ) )
	);

	$data          = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$submission_id = (int) $db->insert_submission( $data );
	$created[]     = $submission_id;

	( new CFS_Action_Runner( $db ) )->run( $form, $data, $submission_id );

	$log = $db->get_meta( $submission_id, CFS_Action_Runner::META_LOG, array() );
	$a->ok( isset( $log['mail_admin'] ), 'admin mail logged' );
	$a->same( CFS_Action_Result::STATUS_OK, $log['mail_admin']['status'] ?? '' );
	$a->not( isset( $log['mail_autoreply'] ), 'a disabled letter leaves no entry' );
} );

$t->test( 'интеграция выполняется и попадает в журнал', function ( CFS_Test_Runner $a ) use ( $db, &$forms, &$created ) {
	$seen = array();

	$register = function ( array $items ) use ( &$seen ): array {
		$items['demo_crm'] = array(
			'label'  => 'Демо CRM',
			'fields' => array( 'webhook_url' => array( 'type' => 'url', 'label' => 'Вебхук' ) ),
			'run'    => function ( $data, $settings ) use ( &$seen ) {
				$seen = array( 'data' => $data, 'settings' => $settings );
				return CFS_Action_Result::success( 'Лид #500 создан', array( 'lead' => 500 ) );
			},
		);
		return $items;
	};

	add_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$form = cfs_actions_form(
		$forms,
		'[name* who][submit]',
		array(
			CFS_Form::META_INTEGRATIONS => array(
				'demo_crm' => array(
					'enabled'  => true,
					'settings' => array( 'webhook_url' => 'https://crm.example/hook' ),
				),
			),
		)
	);

	$data          = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$submission_id = (int) $db->insert_submission( $data );
	$created[]     = $submission_id;

	( new CFS_Action_Runner( $db ) )->run( $form, $data, $submission_id );

	remove_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$log = $db->get_meta( $submission_id, CFS_Action_Runner::META_LOG, array() );
	$a->same( CFS_Action_Result::STATUS_OK, $log['demo_crm']['status'] ?? '' );
	$a->same( 'Лид #500 создан', $log['demo_crm']['message'] ?? '' );
	$a->same( 500, $log['demo_crm']['data']['lead'] ?? 0 );
	$a->same( 'https://crm.example/hook', $seen['settings']['webhook_url'] ?? '' );
	$a->same( 'Иван', $seen['data']['name'] ?? '' );
} );

$t->test( 'исключение в интеграции не ломает заявку', function ( CFS_Test_Runner $a ) use ( $db, &$forms, &$created ) {
	$register = function ( array $items ): array {
		$items['broken'] = array(
			'label' => 'Сломанная',
			'run'   => function () {
				throw new RuntimeException( 'CRM недоступна' );
			},
		);
		return $items;
	};

	add_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$form = cfs_actions_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_INTEGRATIONS => array( 'broken' => array( 'enabled' => true ) ) )
	);

	$data          = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$submission_id = (int) $db->insert_submission( $data );
	$created[]     = $submission_id;

	$threw = false;
	try {
		( new CFS_Action_Runner( $db ) )->run( $form, $data, $submission_id );
	} catch ( Throwable $e ) {
		$threw = true;
	}

	remove_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$a->same( false, $threw, 'the runner must swallow the exception' );

	$log = $db->get_meta( $submission_id, CFS_Action_Runner::META_LOG, array() );
	$a->same( CFS_Action_Result::STATUS_FAILED, $log['broken']['status'] ?? '' );
	$a->contains( 'CRM недоступна', (string) ( $log['broken']['message'] ?? '' ) );
} );

$t->test( 'отложенная интеграция ставится в очередь', function ( CFS_Test_Runner $a ) use ( $db, &$forms, &$created ) {
	$ran = false;

	$register = function ( array $items ) use ( &$ran ): array {
		$items['slow_crm'] = array(
			'label'    => 'Медленная CRM',
			'deferred' => true,
			'run'      => function () use ( &$ran ) {
				$ran = true;
				return CFS_Action_Result::success( 'ок' );
			},
		);
		return $items;
	};

	add_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$form = cfs_actions_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_INTEGRATIONS => array( 'slow_crm' => array( 'enabled' => true ) ) )
	);

	$data          = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$submission_id = (int) $db->insert_submission( $data );
	$created[]     = $submission_id;

	$runner = new CFS_Action_Runner( $db );
	$runner->run( $form, $data, $submission_id );

	$a->same( false, $ran, 'a deferred action must not run inline' );

	$scheduled = wp_next_scheduled( CFS_Action_Runner::CRON_HOOK, array( $submission_id, 'slow_crm', 1 ) );
	$a->ok( $scheduled, 'cron event scheduled' );

	$log = $db->get_meta( $submission_id, CFS_Action_Runner::META_LOG, array() );
	$a->same( CFS_Action_Result::STATUS_SKIPPED, $log['slow_crm']['status'] ?? '' );

	// Now run it the way cron would.
	$runner->run_deferred( $submission_id, 'slow_crm', 1 );

	$a->same( true, $ran, 'the deferred action runs when cron fires' );

	$log = $db->get_meta( $submission_id, CFS_Action_Runner::META_LOG, array() );
	$a->same( CFS_Action_Result::STATUS_OK, $log['slow_crm']['status'] ?? '' );

	if ( $scheduled ) {
		wp_unschedule_event( (int) $scheduled, CFS_Action_Runner::CRON_HOOK, array( $submission_id, 'slow_crm', 1 ) );
	}

	remove_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();
} );

$t->test( 'неудачная отложенная попытка планирует повтор', function ( CFS_Test_Runner $a ) use ( $db, &$forms, &$created ) {
	$register = function ( array $items ): array {
		$items['flaky'] = array(
			'label'    => 'Ненадёжная',
			'deferred' => true,
			'run'      => function () {
				return CFS_Action_Result::failure( 'таймаут', true );
			},
		);
		return $items;
	};

	add_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();

	$form = cfs_actions_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_INTEGRATIONS => array( 'flaky' => array( 'enabled' => true ) ) )
	);

	$data          = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$submission_id = (int) $db->insert_submission( $data );
	$created[]     = $submission_id;

	( new CFS_Action_Runner( $db ) )->run_deferred( $submission_id, 'flaky', 1 );

	$retry = wp_next_scheduled( CFS_Action_Runner::CRON_HOOK, array( $submission_id, 'flaky', 2 ) );
	$a->ok( $retry, 'a retry is scheduled' );

	$log = $db->get_meta( $submission_id, CFS_Action_Runner::META_LOG, array() );
	$a->same( CFS_Action_Result::STATUS_FAILED, $log['flaky']['status'] ?? '' );
	$a->contains( 'таймаут', (string) ( $log['flaky']['message'] ?? '' ) );

	if ( $retry ) {
		wp_unschedule_event( (int) $retry, CFS_Action_Runner::CRON_HOOK, array( $submission_id, 'flaky', 2 ) );
	}

	remove_filter( 'cfs_integrations', $register );
	CFS_Integrations::flush_cache();
} );

$t->test( 'журнал выводится панелью в карточке заявки', function ( CFS_Test_Runner $a ) use ( $db, &$forms, &$created ) {
	$form = cfs_actions_form(
		$forms,
		'[name* who][submit]',
		array( CFS_Form::META_MAIL => array( 'admin' => array( 'enabled' => true, 'to' => 'sales@example.com' ) ) )
	);

	$data          = cfs_actions_data( $form, array( 'who' => 'Иван' ) );
	$submission_id = (int) $db->insert_submission( $data );
	$created[]     = $submission_id;

	$runner = new CFS_Action_Runner( $db );
	$runner->run( $form, $data, $submission_id );

	$panels = $runner->register_panel( array(), $db->get_submission( $submission_id ) );

	$a->same( 1, count( $panels ) );
	$a->same( 'cfs-actions', $panels[0]['id'] ?? '' );
	$a->ok( ! empty( $panels[0]['rows'] ), 'panel has rows' );
	$a->contains( 'Выполнено', (string) $panels[0]['rows'][0]['value'] );
} );

$t->test( 'действие может изменить ответ клиенту', function ( CFS_Test_Runner $a ) use ( $db, &$forms ) {
	$form = cfs_actions_form( $forms, '[name* who][submit]' );
	$data = cfs_actions_data( $form, array( 'who' => 'Иван' ) );

	$filter = function ( array $overrides ): array {
		$overrides['message'] = 'Переопределено действием';
		return $overrides;
	};

	add_filter( 'cfs_action_response', $filter );
	$overrides = ( new CFS_Action_Runner( $db ) )->run( $form, $data, 0 );
	remove_filter( 'cfs_action_response', $filter );

	$a->same( 'Переопределено действием', $overrides['message'] ?? '' );
} );

$t->test( 'CFS_Action_Result нормализует любой ответ обработчика', function ( CFS_Test_Runner $a ) {
	$a->same( true, CFS_Action_Result::normalize( true )->is_ok() );
	$a->same( true, CFS_Action_Result::normalize( false )->is_failed() );
	$a->same( true, CFS_Action_Result::normalize( new WP_Error( 'x', 'сломалось' ) )->is_failed() );
	$a->same( 'сломалось', CFS_Action_Result::normalize( new WP_Error( 'x', 'сломалось' ) )->message );
	$a->same( true, CFS_Action_Result::normalize( array( 'ok' => false, 'message' => 'нет' ) )->is_failed() );
	$a->same( 'нет', CFS_Action_Result::normalize( array( 'ok' => false, 'message' => 'нет' ) )->message );
	$a->same( true, CFS_Action_Result::normalize( array( 'message' => 'готово' ) )->is_ok() );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ( array_unique( $created ) as $submission_id ) {
	$db->delete_submission( (int) $submission_id );
}
foreach ( array_unique( $forms ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

exit( $t->summary() );
