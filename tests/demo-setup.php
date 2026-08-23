<?php
/**
 * Create (or refresh) a demo form and a page that renders it.
 *
 * Idempotent — re-running updates the same form and page.
 *
 *   docker compose exec -T wordpress php -r "define('WP_USE_THEMES', false); \
 *     require '/var/www/html/wp-load.php'; \
 *     require '/var/www/html/wp-content/plugins/contact-form/tests/demo-setup.php';"
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cfs_demo_template = <<<'TPL'
<p>Оставьте заявку — перезвоним в течение 15 минут.</p>

<div class="cfs-row">
	[name* first_name label="Имя" icon="user" width="1/2"]
	[phone* phone label="Телефон" icon="phone" width="1/2"]
</div>

[email email label="Email" icon="email"]
[select* topic label="Тема обращения" options="Консультация:consult,Расчёт стоимости:calc,Другое:other"]
[radio experience label="Опыт работы с нами" options="Первый раз:first,Уже обращался:repeat"]
[multicheck channels label="Как удобнее связаться" options="Телефон:phone,Почта:email,Мессенджер:messenger"]
[textarea comment label="Комментарий" rows="4" help="Не более 1000 символов"]

<hr>
[agreement* consent label="Я согласен с <a href='/privacy/'>политикой конфиденциальности</a>"]
[hidden utm_source source="query:utm_source"]
[submit "Отправить заявку" icon_after="arrow-right"]
TPL;

$cfs_demo_steps = <<<'TPL'
[step label="Контакты"]
[name* first_name label="Имя" icon="user"]
[phone* phone label="Телефон" icon="phone"]

[step label="Детали"]
[date when label="Удобная дата"]
[number people label="Количество человек" min="1" max="20" step="1"]
[textarea comment label="Комментарий" rows="3"]

[submit "Готово"]
TPL;

/**
 * Create or update a demo form by title.
 *
 * @param string $title    Form title.
 * @param string $template Template text.
 * @param array  $settings Presentation settings.
 * @return CFS_Form
 */
function cfs_demo_form( string $title, string $template, array $settings = array() ): CFS_Form {
	$existing = get_posts(
		array(
			'post_type'   => CFS_Post_Type::POST_TYPE,
			'title'       => $title,
			'post_status' => 'any',
			'numberposts' => 1,
		)
	);

	$form = ! empty( $existing ) ? CFS_Form::load( (int) $existing[0]->ID ) : CFS_Form::create( $title, $template );

	$form->set_title( $title );
	$form->set_template( $template );

	if ( ! empty( $settings ) ) {
		$form->set_group( CFS_Form::META_SETTINGS, array_merge( $form->get_settings(), $settings ) );
	}

	$form->save();

	return $form;
}

$plain = cfs_demo_form( 'Демо — все типы полей', $cfs_demo_template );
$steps = cfs_demo_form( 'Демо — мастер в 2 шага', $cfs_demo_steps );
$modal = cfs_demo_form(
	'Демо — модальное окно',
	"[name* first_name label=\"Имя\" icon=\"user\"]\n[phone* phone label=\"Телефон\" icon=\"phone\"]\n[submit \"Жду звонка\"]",
	array(
		'container'         => 'dialog',
		'modal_button_text' => 'Заказать звонок',
		'modal_button_icon_after' => 'phone',
	)
);

$content = "<h2>Обычная форма</h2>\n"
	. $plain->get_shortcode() . "\n\n"
	. "<h2>Мастер в два шага</h2>\n"
	. $steps->get_shortcode() . "\n\n"
	. "<h2>Модальное окно</h2>\n"
	. $modal->get_shortcode() . "\n";

$page = get_page_by_path( 'cfs-demo' );

$page_id = wp_insert_post(
	array(
		'ID'           => $page ? $page->ID : 0,
		'post_type'    => 'page',
		'post_title'   => 'CFS демо',
		'post_name'    => 'cfs-demo',
		'post_status'  => 'publish',
		'post_content' => $content,
	)
);

foreach ( array( $plain, $steps, $modal ) as $form ) {
	printf( "form #%d — %s\n", $form->get_id(), $form->get_title() );
	foreach ( $form->get_errors() as $error ) {
		printf( "   [%s] %s\n", $error['level'], $error['message'] );
	}
}

printf( "page: %s\n", get_permalink( $page_id ) );
