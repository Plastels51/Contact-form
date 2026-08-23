<?php
/**
 * Renderer and shortcode tests.
 *
 *   docker compose exec -T wordpress php -r "define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/run-tests-render.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/run-tests-runner.php';

// The markup assertions below describe the stock skin. A site that picked
// another one in Settings would otherwise fail the whole suite on a class name
// it is entitled to have.
add_filter( 'pre_option_cfs_style_theme', function () {
	return 'default';
} );

$t       = new CFS_Test_Runner();
$created = array();

/**
 * Render a saved form and return its HTML.
 *
 * @param array  $created  Registry of created post IDs.
 * @param string $template Template text.
 * @param array  $settings Optional presentation settings.
 * @return string
 */
function cfs_render_template( array &$created, string $template, array $settings = array() ): string {
	$form = CFS_Form::create( 'Тест рендера', $template );
	if ( ! $form ) {
		throw new RuntimeException( 'form creation failed' );
	}
	$created[] = $form->get_id();

	if ( ! empty( $settings ) ) {
		$form->set_group( CFS_Form::META_SETTINGS, array_merge( $form->get_settings(), $settings ) );
		$form->save();
	}

	$renderer = new CFS_Form_Renderer( $form );

	return $renderer->render();
}

/**
 * Pull the data-cfs-config JSON out of rendered markup.
 *
 * @param string $html Rendered form.
 * @return array
 */
function cfs_render_config( string $html ): array {
	if ( ! preg_match( '/data-cfs-config="([^"]*)"/', $html, $m ) ) {
		return array();
	}
	return (array) json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
}

echo "\nCFS renderer / shortcode\n";
echo str_repeat( '─', 60 ) . "\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Structure
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'каркас формы и системные поля', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[name* n label="Имя"][submit "Ок"]' );

	$a->contains( '<div class="cfs-form-wrap"', $html );
	$a->contains( '<form class="cfs-form"', $html );
	$a->contains( 'novalidate', $html );
	$a->contains( 'name="cfs_hp_w"', $html );
	$a->contains( 'name="cfs_hp_x"', $html );
	$a->contains( 'name="cfs_timestamp"', $html );
	$a->contains( 'name="cfs_form_id"', $html );
	$a->contains( 'name="cfs_hash"', $html );
	$a->contains( 'value="cfs_submit_form"', $html );
	$a->contains( 'class="cfs-form-message"', $html );
} );

$t->test( 'имена полей лежат в пространстве cfs[]', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[text first_name][multicheck m options="Один:a"][submit]' );
	$a->contains( 'name="cfs[first_name]"', $html );
	$a->contains( 'name="cfs[m][]"', $html );
} );

$t->test( 'два экземпляра одной формы не делят id', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form = CFS_Form::create( 'Двойная', '[name n][submit]' );
	$created[] = $form->get_id();

	$first  = ( new CFS_Form_Renderer( $form ) )->render();
	$second = ( new CFS_Form_Renderer( $form ) )->render();

	preg_match( '/id="(cfs-\d+-\d+-n)"/', $first, $m1 );
	preg_match( '/id="(cfs-\d+-\d+-n)"/', $second, $m2 );

	$a->ok( ! empty( $m1[1] ) && ! empty( $m2[1] ), 'field ids found' );
	$a->ok( ( $m1[1] ?? '' ) !== ( $m2[1] ?? '' ), 'ids must differ between instances' );
	$a->contains( 'data-instance="1"', $first );
	$a->contains( 'data-instance="2"', $second );
} );

$t->test( 'HTML шаблона выводится как есть', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '<p class="intro">Привет</p>[name n]<hr>[submit]' );
	$a->contains( '<p class="intro">Привет</p>', $html );
	$a->contains( '<hr>', $html );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Field markup
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'обязательное поле помечено и в разметке, и в ARIA', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[name* n label="Имя"][submit]' );
	$a->contains( '<span class="cfs-required" aria-hidden="true">*</span>', $html );
	$a->contains( 'required', $html );
	$a->contains( 'aria-required="true"', $html );
	$a->contains( 'aria-describedby=', $html );
	$a->contains( 'class="cfs-error" role="alert" aria-live="polite"', $html );
} );

$t->test( 'телефон получает маску, тип и паттерн', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[phone* p placeholder="+7 (___) ___-__-__"][submit]' );
	$a->contains( 'type="tel"', $html );
	$a->contains( 'cfs-input cfs-input--phone', $html );
	$a->contains( 'autocomplete="tel"', $html );
	$a->contains( 'pattern=', $html );
} );

$t->test( 'иконка выводится голым svg и помечает поле', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[name n icon="user"][submit]' );
	$a->contains( 'cfs-field--has-icon', $html );
	$a->contains( '<svg', $html );
	$a->lacks( '<span class="cfs-field-icon"', $html );
} );

$t->test( 'дата и число стартуют с поднятой меткой', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[date d][number x][submit]' );
	$a->contains( 'cfs-field cfs-field--date focused', $html );
	$a->contains( 'cfs-field cfs-field--number focused', $html );
} );

$t->test( 'ограничения попадают в атрибуты ввода', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[number age min="18" max="99" step="1"][textarea c maxlength="500" rows="6"][submit]' );
	$a->contains( 'min="18"', $html );
	$a->contains( 'max="99"', $html );
	$a->contains( 'step="1"', $html );
	$a->contains( 'maxlength="500"', $html );
	$a->contains( 'rows="6"', $html );
} );

$t->test( 'select выводит подпись как первую опцию', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[select* s label="Тема" options="Консультация:consult,Расчёт:calc"][submit]' );
	$a->contains( '<option value="" disabled selected>— Тема —</option>', $html );
	$a->contains( '<option value="consult">Консультация</option>', $html );
	$a->contains( 'aria-label="Тема"', $html );
	$a->contains( 'class="cfs-input cfs-select"', $html );
} );

$t->test( 'радиогруппа — fieldset с legend', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[radio* r label="Опыт" options="Да:yes,Нет:no"][submit]' );
	$a->contains( '<fieldset class="cfs-field cfs-field--radio"', $html );
	$a->contains( '<legend class="cfs-field-legend">Опыт', $html );
	$a->contains( 'data-required="true"', $html );
	$a->contains( 'type="radio"', $html );
	$a->contains( 'value="yes"', $html );
} );

$t->test( 'кириллические значения multicheck переживают рендер', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[multicheck m options="Москва:Москва,Питер:СПб"][submit]' );
	$a->contains( 'value="Москва"', $html );
	$a->contains( 'value="СПб"', $html );
	$a->contains( 'cfs-multicheck-fieldset', $html );
	// IDs come from the option position, so a non-ASCII value cannot break them.
	$a->contains( '-m-0"', $html );
	$a->contains( '-m-1"', $html );
} );

$t->test( 'чекбокс и согласие', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template(
		$created,
		'[checkbox c label="Подписаться"][agreement* consent label="Согласен с <a href=\'/privacy/\'>политикой</a>"][submit]'
	);
	$a->contains( 'class="cfs-checkbox-label"', $html );
	$a->contains( '<span>Подписаться</span>', $html );
	$a->contains( 'cfs-field--agreement', $html );
	$a->contains( '<a href="/privacy/">политикой</a>', $html );
} );

$t->test( 'скрытое поле с источником помечается для JS', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[hidden utm source="query:utm_source"][hidden page source="page:url"][submit]' );
	$a->contains( 'data-cfs-source="query:utm_source"', $html );
	$a->lacks( 'data-cfs-source="page:url"', $html );
	$a->contains( 'name="cfs[page]"', $html );
} );

$t->test( 'значение по умолчанию подставляется', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[text a default="Иван"][select s options="Один:a,Два:b" default="b"][submit]' );
	$a->contains( 'value="Иван"', $html );
	$a->contains( '<option value="b" selected>Два</option>', $html );
} );

$t->test( 'ширина поля превращается в класс', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[text a width="1/2"][text b width="1/3"][submit]' );
	$a->contains( 'cfs-field--w-1-2', $html );
	$a->contains( 'cfs-field--w-1-3', $html );
} );

$t->test( 'подсказка выводится под полем', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[textarea c help="Не более 1000 символов"][submit]' );
	$a->contains( '<p class="cfs-field-hint">Не более 1000 символов</p>', $html );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Buttons, steps, dialog
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'кнопка отправки с иконками', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[name n][submit "Отправить" icon_after="arrow-right"]' );
	$a->contains( '<div class="cfs-field cfs-field--submit">', $html );
	$a->contains( 'class="cfs-btn cfs-btn--submit"', $html );
	$a->contains( 'Отправить', $html );
	$a->contains( '<svg', $html );
} );

$t->test( 'кнопка добавляется, если тега [submit] нет', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[name n]' );
	$a->contains( 'cfs-btn--submit', $html );
} );

$t->test( 'многошаговая форма получает степпер и навигацию', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template(
		$created,
		'[step label="Контакты"][name* n][step label="Детали"][textarea c][submit "Готово"]'
	);
	$a->contains( '<ol class="cfs-stepper"', $html );
	$a->contains( 'Контакты', $html );
	$a->contains( 'Детали', $html );
	$a->contains( '<div class="cfs-step" data-step="0">', $html );
	$a->contains( '<div class="cfs-step" data-step="1" hidden>', $html );
	$a->contains( '<div class="cfs-step-nav">', $html );
	$a->contains( 'cfs-btn--back', $html );
	$a->contains( 'cfs-btn--next', $html );
	// The submit button lives in the nav bar and starts hidden.
	$a->contains( 'cfs-btn cfs-btn--submit" id=', $html );
	$a->lacks( 'cfs-field cfs-field--submit', $html );
} );

$t->test( 'без подписей шагов степпер компактный', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template( $created, '[name n][step][phone p][submit]' );
	$a->contains( 'cfs-stepper cfs-stepper--compact', $html );
} );

$t->test( 'модальный контейнер даёт кнопку и dialog', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html = cfs_render_template(
		$created,
		'[name n][submit]',
		array(
			'container'         => 'dialog',
			'modal_button_text' => 'Заказать звонок',
		)
	);
	$a->contains( '<button type="button" class="cfs-modal-btn"', $html );
	$a->contains( 'Заказать звонок', $html );
	$a->contains( '<dialog class="cfs-form-wrap cfs-form-wrap--dialog"', $html );
	$a->contains( 'class="cfs-modal-close"', $html );
	$a->contains( 'aria-haspopup="dialog"', $html );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Config for the front-end script
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'конфиг для JS содержит правила полей', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html   = cfs_render_template( $created, '[email* e][number x min="1" max="5"][select s options="Да:1,Нет:2"][submit]' );
	$config = cfs_render_config( $html );

	$a->ok( ! empty( $config ), 'config decoded' );
	$a->same( true, $config['fields']['e']['required'] );
	$a->same( array( 'email' ), $config['fields']['e']['rules'] );
	$a->same( '1', $config['fields']['x']['min'] );
	$a->same( array( '1', '2' ), $config['fields']['s']['options'] );
	$a->ok( isset( $config['after']['mode'] ), 'after-submit settings present' );
} );

$t->test( 'конфиг несёт шаги', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html   = cfs_render_template( $created, '[name n][step][phone p][submit]' );
	$config = cfs_render_config( $html );
	$a->same( array( array( 'n' ), array( 'p' ) ), $config['steps'] );
} );

$t->test( 'кнопка и разделитель шага не попадают в конфиг полей', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$html   = cfs_render_template( $created, '[name n][step][submit]' );
	$config = cfs_render_config( $html );
	$a->same( array( 'n' ), array_keys( $config['fields'] ) );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Failure modes
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'сломанная форма не выводится посетителю', function ( CFS_Test_Runner $a ) use ( &$created ) {
	wp_set_current_user( 0 );
	$html = cfs_render_template( $created, '<p>ни одного поля</p>' );
	$a->contains( '<!-- CFS:', $html );
	$a->lacks( '<form', $html );
} );

$t->test( 'шорткод без id ругается для администратора', function ( CFS_Test_Runner $a ) {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( empty( $admins ) ) {
		return;
	}
	wp_set_current_user( $admins[0]->ID );

	$shortcode = new CFS_Shortcode();
	$html      = $shortcode->render( array( 'id' => '999999' ) );
	$a->contains( 'не найдена', $html );

	wp_set_current_user( 0 );
	$a->contains( '<!-- CFS:', $shortcode->render( array( 'id' => '999999' ) ) );
} );

$t->test( 'шорткод рендерит форму по id и по слагу', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'По слагу', '[name n][submit]' );
	$created[] = $form->get_id();
	$slug      = (string) get_post_field( 'post_name', $form->get_id() );

	$shortcode = new CFS_Shortcode();
	$a->contains( '<form class="cfs-form"', $shortcode->render( array( 'id' => (string) $form->get_id() ) ) );
	$a->contains( '<form class="cfs-form"', $shortcode->render( array( 'slug' => $slug ) ) );
} );

$t->test( 'шорткод без id уходит в legacy-фильтр', function ( CFS_Test_Runner $a ) {
	$called = false;

	$handler = function ( $html, $atts ) use ( &$called ) {
		$called = true;
		return '<div id="legacy-answer"></div>';
	};

	add_filter( 'cfs_render_legacy_form', $handler, 5, 2 );
	$out = ( new CFS_Shortcode() )->render( array( 'fields' => 'name*,phone*' ) );
	remove_filter( 'cfs_render_legacy_form', $handler, 5 );

	$a->same( true, $called, 'legacy filter must be consulted' );
	$a->contains( 'legacy-answer', $out );
} );

$t->test( 'класс из шорткода попадает на обёртку', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'С классом', '[name n][submit]' );
	$created[] = $form->get_id();

	$html = ( new CFS_Shortcode() )->render(
		array(
			'id'    => (string) $form->get_id(),
			'class' => 'cfs-form--cols-2',
		)
	);
	$a->contains( 'cfs-form-wrap cfs-form--cols-2', $html );
} );

$t->test( 'неопубликованная форма не выводится шорткодом', function ( CFS_Test_Runner $a ) use ( &$created ) {
	$form      = CFS_Form::create( 'Черновик', '[name n][submit]' );
	$created[] = $form->get_id();
	wp_update_post( array( 'ID' => $form->get_id(), 'post_status' => 'draft' ) );

	$shortcode = new CFS_Shortcode();
	$atts      = array( 'id' => (string) $form->get_id() );

	// A visitor gets nothing: the intake refuses drafts, so a rendered form
	// could only take their time and answer with an opaque error.
	wp_set_current_user( 0 );
	$out = $shortcode->render( $atts );
	$a->ok( false === strpos( $out, '<form' ), 'no form for a visitor' );

	// The author gets told why, instead of hunting a form that renders nowhere.
	$editor = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	if ( ! empty( $editor ) ) {
		wp_set_current_user( (int) $editor[0] );
		$notice = $shortcode->render( $atts );
		$a->ok( false === strpos( $notice, '<form' ), 'no form for the author either' );
		$a->contains( 'не опубликована', $notice );
		wp_set_current_user( 0 );
	}

	// Publishing it brings the form back.
	wp_update_post( array( 'ID' => $form->get_id(), 'post_status' => 'publish' ) );
	$a->contains( '<form', $shortcode->render( $atts ) );
} );

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( $post_id, true );
}

exit( $t->summary() );
