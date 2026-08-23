<?php
/**
 * The Bitrix24 add-on seen through the integration registry.
 *
 * Skips itself when the add-on is not installed. No request ever leaves the
 * site: the HTTP layer is stubbed with pre_http_request.
 *
 *   docker compose exec -T wordpress php -r "define('DOING_AJAX', true); \
 *     define('WP_ADMIN', true); define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-bitrix24.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

$t = new CFS_Test_Runner();

echo "\nCFS Bitrix24 add-on\n";
echo str_repeat( '─', 60 ) . "\n";

if ( ! class_exists( 'CFS_B24' ) ) {
	echo "дополнение не установлено — проверки пропущены\n";
	exit( $t->summary() );
}

$db      = new CFS_DB();
$created = array();
$forms   = array();

/**
 * Captured outbound requests.
 *
 * @var array
 */
$GLOBALS['cfs_b24_calls'] = array();

/**
 * Canned responses, keyed by the method in the URL.
 *
 * @var array
 */
$GLOBALS['cfs_b24_responses'] = array();

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, '/rest/' ) ) {
			return $preempt;
		}

		$GLOBALS['cfs_b24_calls'][] = array(
			'url'  => (string) $url,
			'body' => $args['body'] ?? array(),
		);

		foreach ( $GLOBALS['cfs_b24_responses'] as $needle => $response ) {
			if ( false !== strpos( (string) $url, (string) $needle ) ) {
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $response ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => '',
				);
			}
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( array( 'result' => 1 ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => '',
		);
	},
	10,
	3
);

/**
 * A stored submission to deliver.
 *
 * @param CFS_DB $db      Database handler.
 * @param array  $created Registry of created IDs.
 * @return int
 */
function cfs_b24_submission( CFS_DB $db, array &$created ): int {
	$id = (int) $db->insert_submission(
		array(
			'_v'      => 2,
			'form_id' => '1',
			'name'    => 'Иван',
			'phone'   => '79001234567',
			'email'   => 'ivan@example.com',
			'comment' => 'Здравствуйте',
			'extra'   => array(
				'who'         => 'Иван',
				'client_tel'  => '79005554433',
				'note'        => 'Из сопоставления',
			),
			'fields'  => array(
				array( 'name' => 'who', 'type' => 'name', 'label' => 'Имя', 'value' => 'Иван', 'display' => 'Иван' ),
				array( 'name' => 'client_tel', 'type' => 'phone', 'label' => 'Телефон', 'value' => '79005554433', 'display' => '+7 (900) 555-44-33' ),
				array( 'name' => 'note', 'type' => 'textarea', 'label' => 'Комментарий', 'value' => 'Из сопоставления', 'display' => 'Из сопоставления' ),
			),
		)
	);

	$created[] = $id;

	return $id;
}

$t->test( 'дополнение регистрируется в реестре интеграций', function ( CFS_Test_Runner $a ) {
	CFS_Integrations::flush_cache();
	$items = CFS_Integrations::all();

	$a->ok( isset( $items['bitrix24'] ), 'bitrix24 is registered' );

	if ( ! isset( $items['bitrix24'] ) ) {
		return;
	}

	$item = $items['bitrix24'];
	$a->same( true, (bool) $item['deferred'], 'delivery is deferred' );
	$a->ok( is_callable( $item['run'] ), 'run handler is callable' );
	$a->ok( is_callable( $item['test'] ), 'test handler is callable' );
	$a->ok( isset( $item['fields']['map'] ), 'field map offered' );
	$a->same( 'field_map', $item['fields']['map']['type'] );
	$a->ok( isset( $item['fields']['map']['targets']['PHONE'] ), 'phone is a mapping target' );
} );

$t->test( 'старый путь cfs_after_save отключён при новом ядре', function ( CFS_Test_Runner $a ) {
	// Both paths active would push every submission to the CRM twice.
	$a->same( true, CFS_B24::parent_has_registry() );
	$a->same( false, (bool) has_action( 'cfs_after_save', array( CFS_B24::get_instance(), 'on_submission_saved' ) ) );
} );

$t->test( 'без вебхука интеграция сообщает об ошибке, а не падает', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	delete_option( 'cfs_b24_webhook' );

	$submission_id = cfs_b24_submission( $db, $created );
	$result        = CFS_B24::get_instance()->run_integration( array(), array(), $submission_id );

	$a->same( true, $result->is_failed() );
	$a->contains( 'вебхук', $result->message );
} );

$t->test( 'заявка уходит в CRM и возвращает номер лида', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	update_option( 'cfs_b24_webhook', 'https://example.bitrix24.ru/rest/1/tokentoken/' );
	$GLOBALS['cfs_b24_calls']     = array();
	$GLOBALS['cfs_b24_responses'] = array( 'crm.lead.add' => array( 'result' => 4242 ) );

	$submission_id = cfs_b24_submission( $db, $created );

	$result = CFS_B24::get_instance()->run_integration(
		array(),
		array( 'entity' => 'lead', 'source_id' => 'WEB' ),
		$submission_id
	);

	$a->same( true, $result->is_ok(), $result->message );
	$a->same( 4242, (int) ( $result->data['entity_id'] ?? 0 ) );
	$a->contains( '4242', $result->message );

	$a->ok( ! empty( $GLOBALS['cfs_b24_calls'] ), 'a request was made' );
	$a->contains( 'crm.lead.add', (string) $GLOBALS['cfs_b24_calls'][0]['url'] );

	// And the add-on's own bookkeeping still records the entity.
	$a->same( 4242, (int) $db->get_meta( $submission_id, CFS_B24::META_ENTITY_ID, 0 ) );
} );

$t->test( 'сопоставление полей перекрывает автоопределение', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	update_option( 'cfs_b24_webhook', 'https://example.bitrix24.ru/rest/1/tokentoken/' );
	$GLOBALS['cfs_b24_calls']     = array();
	$GLOBALS['cfs_b24_responses'] = array( 'crm.lead.add' => array( 'result' => 77 ) );

	$submission_id = cfs_b24_submission( $db, $created );

	CFS_B24::get_instance()->run_integration(
		array(),
		array(
			'entity' => 'lead',
			'map'    => array(
				'PHONE'    => 'client_tel',
				'COMMENTS' => 'note',
			),
		),
		$submission_id
	);

	$a->ok( ! empty( $GLOBALS['cfs_b24_calls'] ), 'a request was made' );

	$sent  = (array) ( $GLOBALS['cfs_b24_calls'][0]['body']['fields'] ?? array() );
	$phone = (string) ( $sent['PHONE'][0]['VALUE'] ?? '' );

	$a->contains( '5554433', $phone, 'the mapped field wins over the role column' );
	$a->same( 'Из сопоставления', (string) ( $sent['COMMENTS'] ?? '' ) );
} );

$t->test( 'повторная доставка не создаёт дубль', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	update_option( 'cfs_b24_webhook', 'https://example.bitrix24.ru/rest/1/tokentoken/' );
	$GLOBALS['cfs_b24_responses'] = array( 'crm.lead.add' => array( 'result' => 99 ) );

	$submission_id = cfs_b24_submission( $db, $created );

	CFS_B24::get_instance()->run_integration( array(), array( 'entity' => 'lead' ), $submission_id );

	$GLOBALS['cfs_b24_calls'] = array();
	$second                   = CFS_B24::get_instance()->run_integration( array(), array( 'entity' => 'lead' ), $submission_id );

	$a->same( true, $second->is_ok() );
	$a->same( 0, count( $GLOBALS['cfs_b24_calls'] ), 'no second request for an already delivered submission' );
} );

$t->test( 'ошибка CRM возвращается как повторяемая', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	update_option( 'cfs_b24_webhook', 'https://example.bitrix24.ru/rest/1/tokentoken/' );
	$GLOBALS['cfs_b24_responses'] = array(
		'crm.lead.add' => array(
			'error'             => 'INVALID_TOKEN',
			'error_description' => 'Токен недействителен',
		),
	);

	$submission_id = cfs_b24_submission( $db, $created );
	$result        = CFS_B24::get_instance()->run_integration( array(), array( 'entity' => 'lead' ), $submission_id );

	$a->same( true, $result->is_failed() );
	$a->same( true, $result->retryable );

	// The add-on must not queue its own retry on top of the parent's.
	$a->same( false, (bool) wp_next_scheduled( CFS_B24::CRON_HOOK, array( $submission_id ) ) );
} );

$t->test( 'проверка соединения возвращает результат', function ( CFS_Test_Runner $a ) {
	update_option( 'cfs_b24_webhook', 'https://example.bitrix24.ru/rest/1/tokentoken/' );
	$GLOBALS['cfs_b24_responses'] = array( 'profile' => array( 'result' => array( 'NAME' => 'Тестовый портал' ) ) );

	$result = CFS_B24::get_instance()->test_integration();

	$a->same( true, $result->is_ok(), $result->message );
	$a->contains( 'Тестовый портал', $result->message );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
delete_option( 'cfs_b24_webhook' );

foreach ( array_unique( $created ) as $submission_id ) {
	$db->delete_submission( (int) $submission_id );
}
foreach ( array_unique( $forms ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

exit( $t->summary() );
