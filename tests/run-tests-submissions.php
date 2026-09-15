<?php
/**
 * Submission model, detail card and CSV export.
 *
 *   docker compose exec -T wordpress php -r "define('DOING_AJAX', true); \
 *     define('WP_ADMIN', true); define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-submissions.php';"
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

$t       = new CFS_Test_Runner();
$db      = new CFS_DB();
$created = array();
$forms   = array();

$cfs_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
if ( ! empty( $cfs_admins ) ) {
	wp_set_current_user( (int) $cfs_admins[0]->ID );
}

/**
 * Insert a raw submission row.
 *
 * @param CFS_DB $db      Database handler.
 * @param array  $created Registry of created IDs.
 * @param array  $data    Payload.
 * @return object Stored row.
 */
function cfs_sub_insert( CFS_DB $db, array &$created, array $data ) {
	$id = (int) $db->insert_submission( $data );
	if ( ! $id ) {
		throw new RuntimeException( 'insert failed' );
	}
	$created[] = $id;

	return $db->get_submission( $id );
}

echo "\nCFS submissions\n";
echo str_repeat( '─', 60 ) . "\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Model — v2 payloads
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'v2: поля читаются в порядке формы', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'      => 2,
			'form_id' => '5',
			'name'    => 'Иван',
			'fields'  => array(
				array( 'name' => 'who', 'type' => 'name', 'label' => 'Имя', 'value' => 'Иван', 'display' => 'Иван' ),
				array( 'name' => 'tel', 'type' => 'phone', 'label' => 'Телефон', 'value' => '79001234567', 'display' => '+7 (900) 123-45-67' ),
				array( 'name' => 'topic', 'type' => 'select', 'label' => 'Тема', 'value' => 'a', 'display' => 'Консультация' ),
				array( 'name' => 'utm', 'type' => 'hidden', 'label' => 'utm', 'value' => 'google', 'display' => 'google' ),
			),
		)
	);

	$item = CFS_Submission::from_row( $row );

	$a->same( 2, $item->get_version() );
	$a->same( array( 'who', 'tel', 'topic', 'utm' ), wp_list_pluck( $item->get_fields(), 'name' ) );
	$a->same( array( 'who', 'tel' ), wp_list_pluck( $item->get_contact_fields(), 'name' ) );
	$a->same( array( 'topic' ), wp_list_pluck( $item->get_data_fields(), 'name' ) );
	$a->same( array( 'utm' ), wp_list_pluck( $item->get_hidden_fields(), 'name' ) );
	$a->same( '+7 (900) 123-45-67', $item->get_display( 'tel' ) );
} );

$t->test( 'v2: имя для списка собирается из ФИО', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'     => 2,
			'fields' => array(
				array( 'name' => 'n', 'type' => 'name', 'label' => 'Имя', 'value' => 'Иван', 'display' => 'Иван' ),
				array( 'name' => 's', 'type' => 'surname', 'label' => 'Фамилия', 'value' => 'Петров', 'display' => 'Петров' ),
				array( 'name' => 'p', 'type' => 'patronymic', 'label' => 'Отчество', 'value' => 'Сергеевич', 'display' => 'Сергеевич' ),
			),
		)
	);

	$a->same( 'Петров Иван Сергеевич', CFS_Submission::from_row( $row )->get_display_name() );
} );

$t->test( 'v2: без ФИО берётся первый контакт', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'     => 2,
			'fields' => array(
				array( 'name' => 'tel', 'type' => 'phone', 'label' => 'Телефон', 'value' => '79001234567', 'display' => '+7 (900) 123-45-67' ),
			),
		)
	);

	$a->same( '+7 (900) 123-45-67', CFS_Submission::from_row( $row )->get_display_name() );
} );

$t->test( 'v2: название формы берётся из заявки', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'     => 2,
			'form'   => array( 'id' => 999999, 'title' => 'Удалённая форма' ),
			'fields' => array(),
		)
	);

	// The form is long gone; the title recorded at submit time still names it.
	$a->same( 'Удалённая форма', CFS_Submission::from_row( $row )->get_form_title() );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Model — v1 payloads
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'v1: заявка 2.x читается по снимку схемы', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'form_id'    => 'cfs_ab12cd34',
			'name'       => 'Иван',
			'phone'      => '79001234567',
			'email'      => 'ivan@example.com',
			'comment'    => 'Здравствуйте',
			'surname'    => 'Петров',
			'extra'      => array(
				'comment_2' => 'Второй комментарий',
				'utm_source' => 'google',
			),
			'_schema'    => array(
				array( 'token' => 'surname', 'type' => 'surname', 'label' => 'Фамилия' ),
				array( 'token' => 'name', 'type' => 'name', 'label' => 'Имя' ),
				array( 'token' => 'phone', 'type' => 'phone', 'label' => 'Телефон' ),
				array( 'token' => 'comment_2', 'type' => 'comment', 'label' => 'Доп. комментарий' ),
			),
		)
	);

	$item = CFS_Submission::from_row( $row );

	$a->same( 1, $item->get_version() );
	$a->same( 'Петров Иван', $item->get_display_name() );
	$a->same( 'Доп. комментарий', $item->get_labels()['comment_2'] ?? '' );
	$a->same( 'Второй комментарий', $item->get_display( 'comment_2' ) );
	$a->same( '+7 (900) 123-45-67', $item->get_display( 'phone' ), 'phone digits are re-formatted' );
	// Fields outside the snapshot still show up.
	$a->contains( 'utm_source', implode( ',', wp_list_pluck( $item->get_fields(), 'name' ) ) );
} );

$t->test( 'v1: заявка без снимка схемы тоже читается', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'form_id' => 'cfs_old',
			'name'    => 'Иван',
			'email'   => 'ivan@example.com',
			'extra'   => array( 'select' => 'consult' ),
		)
	);

	$item  = CFS_Submission::from_row( $row );
	$names = wp_list_pluck( $item->get_fields(), 'name' );

	$a->contains( 'name', implode( ',', $names ) );
	$a->contains( 'select', implode( ',', $names ) );
	$a->same( 'Иван', $item->get_display( 'name' ) );
	$a->same( 'Имя', $item->get_labels()['name'] ?? '' );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Detail card
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'карточка заявки печатает метки, ссылки и техданные', function ( CFS_Test_Runner $a ) use ( $db, &$created, &$forms ) {
	$form      = CFS_Form::create( 'Карточка', '[name* who label="Имя"][phone* tel label="Телефон"][email mail][textarea note label="Комментарий"][hidden utm][submit]' );
	$forms[]   = $form->get_id();

	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'           => 2,
			'form'         => array( 'id' => $form->get_id(), 'title' => $form->get_title() ),
			'form_id'      => (string) $form->get_id(),
			'form_post_id' => $form->get_id(),
			'name'         => 'Иван',
			'phone'        => '79001234567',
			'email'        => 'ivan@example.com',
			'fields'       => array(
				array( 'name' => 'who', 'type' => 'name', 'label' => 'Имя', 'value' => 'Иван', 'display' => 'Иван' ),
				array( 'name' => 'tel', 'type' => 'phone', 'label' => 'Телефон', 'value' => '79001234567', 'display' => '+7 (900) 123-45-67' ),
				array( 'name' => 'mail', 'type' => 'email', 'label' => 'Email', 'value' => 'ivan@example.com', 'display' => 'ivan@example.com' ),
				array( 'name' => 'note', 'type' => 'textarea', 'label' => 'Комментарий', 'value' => "Строка\nВторая", 'display' => "Строка\nВторая" ),
				array( 'name' => 'utm', 'type' => 'hidden', 'label' => 'utm', 'value' => 'google', 'display' => 'google' ),
			),
			'page_url'     => 'https://example.test/contacts/',
		)
	);

	$_GET['action'] = 'view';
	$_GET['id']     = (string) $row->id;

	ob_start();
	( new CFS_Admin( $db ) )->page_submissions();
	$html = (string) ob_get_clean();

	unset( $_GET['action'], $_GET['id'] );

	$a->contains( 'Заявитель', $html );
	$a->contains( 'Данные формы', $html );
	$a->contains( 'Технические данные', $html );
	$a->contains( 'tel:79001234567', $html );
	$a->contains( 'mailto:ivan@example.com', $html );
	$a->contains( '+7 (900) 123-45-67', $html );
	$a->contains( 'Комментарий', $html );
	$a->contains( 'Строка<br />', $html, 'newlines survive as <br>' );
	$a->contains( 'Карточка', $html, 'the form title is shown' );
	$a->contains( 'page=cfs-form&#038;id=' . $form->get_id(), $html, 'and links to the editor' );
} );

$t->test( 'карточка старой заявки не падает без формы', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$row = cfs_sub_insert(
		$db,
		$created,
		array(
			'form_id' => 'cfs_legacy1',
			'name'    => 'Пётр',
			'phone'   => '79005554433',
			'extra'   => array( 'comment_2' => 'Текст' ),
		)
	);

	$_GET['action'] = 'view';
	$_GET['id']     = (string) $row->id;

	ob_start();
	( new CFS_Admin( $db ) )->page_submissions();
	$html = (string) ob_get_clean();

	unset( $_GET['action'], $_GET['id'] );

	$a->contains( 'Пётр', $html );
	$a->contains( 'cfs_legacy1', $html );
	$a->lacks( 'Fatal error', $html );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * CSV export
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'экспорт по форме даёт колонку на каждое поле', function ( CFS_Test_Runner $a ) use ( $db, &$created, &$forms ) {
	$form    = CFS_Form::create( 'Экспорт', '[name* who label="Имя"][select topic label="Тема" options="Консультация:a,Расчёт:b"][textarea note label="Комментарий"][submit]' );
	$forms[] = $form->get_id();

	cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'           => 2,
			'form'         => array( 'id' => $form->get_id(), 'title' => 'Экспорт' ),
			'form_id'      => (string) $form->get_id(),
			'form_post_id' => $form->get_id(),
			'name'         => 'Иван',
			'fields'       => array(
				array( 'name' => 'who', 'type' => 'name', 'label' => 'Имя', 'value' => 'Иван', 'display' => 'Иван' ),
				array( 'name' => 'topic', 'type' => 'select', 'label' => 'Тема', 'value' => 'a', 'display' => 'Консультация' ),
				array( 'name' => 'note', 'type' => 'textarea', 'label' => 'Комментарий', 'value' => '', 'display' => '' ),
			),
		)
	);

	$table = ( new CFS_Exporter( $db ) )->build_table( array( 'form_id' => (string) $form->get_id() ) );

	$a->same( 2, count( $table ), 'header plus one row' );

	$header = $table[0];
	$a->same( 'ID', $header[0] );
	$a->contains( 'Имя', implode( '|', $header ) );
	$a->contains( 'Тема', implode( '|', $header ) );
	$a->contains( 'Комментарий', implode( '|', $header ) );
	$a->same( 'User Agent', $header[ count( $header ) - 1 ] );

	$row = $table[1];
	$a->same( 'Экспорт', $row[1] );
	$a->same( 'Новая', $row[3] );
	$a->same( 'Иван', $row[4] );
	$a->same( 'Консультация', $row[5], 'the label, not the stored value' );
	$a->same( '', $row[6], 'an empty field keeps its column' );
} );

$t->test( 'экспорт без фильтра объединяет колонки разных форм', function ( CFS_Test_Runner $a ) use ( $db, &$created ) {
	$before = count( ( new CFS_Exporter( $db ) )->build_table( array( 'status' => 'spam' ) ) );

	cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'     => 2,
			'status' => 'spam',
			'fields' => array(
				array( 'name' => 'alpha', 'type' => 'text', 'label' => 'Альфа', 'value' => '1', 'display' => '1' ),
			),
		)
	);
	cfs_sub_insert(
		$db,
		$created,
		array(
			'_v'     => 2,
			'status' => 'spam',
			'fields' => array(
				array( 'name' => 'beta', 'type' => 'text', 'label' => 'Бета', 'value' => '2', 'display' => '2' ),
			),
		)
	);

	$table  = ( new CFS_Exporter( $db ) )->build_table( array( 'status' => 'spam' ) );
	$header = implode( '|', $table[0] );

	$a->same( $before + 2, count( $table ) );
	$a->contains( 'Альфа', $header );
	$a->contains( 'Бета', $header );
} );

$t->test( 'формулы в CSV обезвреживаются', function ( CFS_Test_Runner $a ) {
	$a->same( "'=1+1", CFS_Exporter::escape_cell( '=1+1' ) );
	$a->same( "'+79001234567", CFS_Exporter::escape_cell( '+79001234567' ) );
	$a->same( "'@SUM(A1)", CFS_Exporter::escape_cell( '@SUM(A1)' ) );
	$a->same( 'Иван', CFS_Exporter::escape_cell( 'Иван' ) );
	$a->same( '', CFS_Exporter::escape_cell( '' ) );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ( array_unique( $created ) as $submission_id ) {
	$db->delete_submission( (int) $submission_id );
}
foreach ( array_unique( $forms ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

exit( $t->summary() );
