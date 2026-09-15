<?php
/**
 * Template parser / compiler test suite.
 *
 * Runs inside a real WordPress so kses, sanitize_*() and force_balance_tags()
 * behave exactly as they will in production:
 *
 *   docker compose exec -T wpcli wp eval-file \
 *     /var/www/html/wp-content/plugins/contact-form/tests/run-tests.php --allow-root
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cfs_test_includes = dirname( __DIR__ ) . '/includes/';
require_once $cfs_test_includes . 'class-cfs-template-parser.php';
require_once $cfs_test_includes . 'class-cfs-field-types.php';
require_once $cfs_test_includes . 'class-cfs-template-sanitizer.php';
require_once $cfs_test_includes . 'class-cfs-form-compiler.php';

require_once __DIR__ . '/run-tests-runner.php';

/**
 * Count diagnostics of a given level.
 *
 * @param array  $errors Compiler diagnostics.
 * @param string $level  'error' or 'warning'.
 * @return int
 */
function cfs_test_count_level( array $errors, string $level ): int {
	$count = 0;
	foreach ( $errors as $error ) {
		if ( $level === $error['level'] ) {
			++$count;
		}
	}
	return $count;
}

/**
 * Join all diagnostic messages into one string.
 *
 * @param array $errors Compiler diagnostics.
 * @return string
 */
function cfs_test_messages( array $errors ): string {
	return implode( ' | ', wp_list_pluck( $errors, 'message' ) );
}

$t = new CFS_Test_Runner();

echo "\nCFS template parser / compiler\n";
echo str_repeat( '─', 60 ) . "\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Parser
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'разбирает тип, имя и звёздочку', function ( CFS_Test_Runner $a ) {
	$tags = CFS_Template_Parser::parse_tags( '[phone* my_phone]' );
	$a->same( 1, count( $tags ), 'tag count' );
	$a->same( 'phone', $tags[0]['type'] );
	$a->same( 'my_phone', $tags[0]['name'] );
	$a->same( true, $tags[0]['required'] );
} );

$t->test( 'разбирает атрибуты в кавычках, апострофах и без кавычек', function ( CFS_Test_Runner $a ) {
	$tags = CFS_Template_Parser::parse_tags( '[text a label="Имя" placeholder=\'Иванов\' rows=4 disabled]' );
	$a->same( 'Имя', $tags[0]['attrs']['label'] );
	$a->same( 'Иванов', $tags[0]['attrs']['placeholder'] );
	$a->same( '4', $tags[0]['attrs']['rows'] );
	$a->same( 'true', $tags[0]['attrs']['disabled'] );
} );

$t->test( 'закрывающая скобка внутри значения не обрывает тег', function ( CFS_Test_Runner $a ) {
	$tags = CFS_Template_Parser::parse_tags( '[agreement consent label="Согласен [с условиями]"]' );
	$a->same( 1, count( $tags ), 'tag count' );
	$a->same( 'Согласен [с условиями]', $tags[0]['attrs']['label'] );
} );

$t->test( 'экранированная скобка остаётся текстом', function ( CFS_Test_Runner $a ) {
	$segments = CFS_Template_Parser::parse( 'цена \\[примерная\\] тут' );
	$a->same( 1, count( $segments ), 'segment count' );
	$a->same( 'цена [примерная] тут', $segments[0]['content'] );
} );

$t->test( 'незакрытая скобка не съедает остаток шаблона', function ( CFS_Test_Runner $a ) {
	$segments = CFS_Template_Parser::parse( 'до [text a после' );
	$a->same( 1, count( $segments ) );
	$a->same( 'html', $segments[0]['kind'] );
	$a->contains( 'после', $segments[0]['content'] );
} );

$t->test( 'комментарий ничего не выводит', function ( CFS_Test_Runner $a ) {
	$segments = CFS_Template_Parser::parse( 'A[# заметка ]B' );
	$html = '';
	foreach ( $segments as $segment ) {
		if ( 'html' === $segment['kind'] ) {
			$html .= $segment['content'];
		}
	}
	$a->same( 'AB', $html );
	$a->same( 0, count( CFS_Template_Parser::parse_tags( 'A[# заметка ]B' ) ) );
} );

$t->test( 'многострочный тег разбирается целиком', function ( CFS_Test_Runner $a ) {
	$tags = CFS_Template_Parser::parse_tags( "[select* topic\n  label=\"Тема\"\n  options=\"Один:1,Два:2\"]" );
	$a->same( 1, count( $tags ) );
	$a->same( 'Тема', $tags[0]['attrs']['label'] );
	$a->same( true, $tags[0]['required'] );
} );

$t->test( 'обратный слэш в pattern сохраняется', function ( CFS_Test_Runner $a ) {
	$tags = CFS_Template_Parser::parse_tags( '[text a pattern="\d{3}-\d{2}"]' );
	$a->same( '\d{3}-\d{2}', $tags[0]['attrs']['pattern'] );
} );

$t->test( 'экранированная кавычка внутри значения', function ( CFS_Test_Runner $a ) {
	$tags = CFS_Template_Parser::parse_tags( '[text a label="Скажи \"да\""]' );
	$a->same( 'Скажи "да"', $tags[0]['attrs']['label'] );
} );

$t->test( 'номер строки указывает на тег', function ( CFS_Test_Runner $a ) {
	$tags = CFS_Template_Parser::parse_tags( "первая\nвторая\n[text a]" );
	$a->same( 3, $tags[0]['line'] );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Compiler — happy paths
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'компилирует поле с меткой и обязательностью', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[name* first_name label="Имя"][submit "Ок"]' );
	$field = $r['schema']['fields']['first_name'];
	$a->same( 'name', $field['type'] );
	$a->same( 'Имя', $field['label'] );
	$a->same( true, $field['required'] );
	$a->same( true, $r['schema']['has_submit'] );
	$a->same( 0, cfs_test_count_level( $r['errors'], 'error' ), cfs_test_messages( $r['errors'] ) );
} );

$t->test( 'позиционная строка становится меткой', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[text a "Ваше имя"][submit "Отправить"]' );
	$a->same( 'Ваше имя', $r['schema']['fields']['a']['label'] );
	$submit = null;
	foreach ( $r['schema']['plan'] as $entry ) {
		if ( 'submit' === $entry['kind'] ) {
			$submit = $entry;
		}
	}
	$a->ok( $submit, 'submit in plan' );
	$a->same( 'Отправить', $submit['attrs']['text'] );
} );

$t->test( 'имя генерируется, когда не задано', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[email][email][submit]' );
	$a->same( array( 'email', 'email_2' ), $r['schema']['order'] );
} );

$t->test( 'алиас comment превращается в textarea', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[comment c][submit]' );
	$a->same( 'textarea', $r['schema']['fields']['c']['type'] );
	$a->same( 'comment', $r['schema']['fields']['c']['role'] );
} );

$t->test( 'роли назначаются по типам полей', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[name* n][email e][phone* p][textarea c][submit]' );
	$a->same(
		array(
			'name'    => 'n',
			'email'   => 'e',
			'phone'   => 'p',
			'comment' => 'c',
		),
		$r['schema']['roles']
	);
} );

$t->test( 'роль можно переопределить атрибутом', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[text who role="name"][submit]' );
	$a->same( 'who', $r['schema']['roles']['name'] );
	$a->same( 'name', $r['schema']['fields']['who']['role'] );
} );

$t->test( 'несуществующая роль игнорируется', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[text who role="выдумка"][submit]' );
	$a->same( '', $r['schema']['fields']['who']['role'] );
	$a->same( array(), $r['schema']['roles'] );
} );

$t->test( 'вторая заявка на ту же роль — предупреждение', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[email first][email second][submit]' );
	$a->same( 'first', $r['schema']['roles']['email'] );
	$a->same( '', $r['schema']['fields']['second']['role'] );
	$a->same( 1, cfs_test_count_level( $r['errors'], 'warning' ), cfs_test_messages( $r['errors'] ) );
} );

$t->test( 'дефолтные pattern подставляются', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[name n][email e][phone p][submit]' );
	$a->same( CFS_Field_Types::LETTERS_PATTERN, $r['schema']['fields']['n']['attrs']['pattern'] );
	$a->same( 'type', $r['schema']['fields']['n']['pattern_from'] );
	$a->contains( '@', $r['schema']['fields']['e']['attrs']['pattern'] );
	$a->contains( '\d{3}', $r['schema']['fields']['p']['attrs']['pattern'] );
	$a->same( 'tel', $r['schema']['fields']['p']['attrs']['autocomplete'] );
} );

$t->test( 'свой pattern перекрывает дефолтный', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[phone p pattern="\d+"][submit]' );
	$a->same( '\d+', $r['schema']['fields']['p']['attrs']['pattern'] );
	$a->same( 'author', $r['schema']['fields']['p']['pattern_from'] );
} );

$t->test( 'дефолтный класс букв пропускает не только русские имена', function ( CFS_Test_Runner $a ) {
	// This class is what the browser enforces. The server rule behind it takes
	// any Unicode letter, so every name the class turns away is one the plugin
	// would have been glad to store.
	$re = chr( 1 ) . '^(?:' . CFS_Field_Types::LETTERS_PATTERN . ')$' . chr( 1 ) . 'u';

	foreach ( array( 'Иван Петров-Водкин', 'Ёлкина', 'Олександр Ткачук', 'Śląski', 'İlqar', "O'Neil" ) as $name ) {
		$a->ok( 1 === preg_match( $re, $name ), $name );
	}

	foreach ( array( '12345', '<script>', 'ok@mail.ru' ) as $junk ) {
		$a->ok( 1 !== preg_match( $re, $junk ), $junk );
	}
} );

$t->test( 'ограничения min/max/step попадают в схему', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[number age min="18" max="99" step="1"][submit]' );
	$a->same(
		array(
			'min'  => '18',
			'max'  => '99',
			'step' => '1',
		),
		$r['schema']['fields']['age']['constraints']
	);
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Compiler — options
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'числовые значения опций остаются строками', function ( CFS_Test_Runner $a ) {
	$r      = CFS_Form_Compiler::compile( '[radio* r options="Да:1,Нет:2"][submit]' );
	$field  = $r['schema']['fields']['r'];
	$values = CFS_Field_Types::option_values( $field );
	$a->same( array( '1', '2' ), $values );
	foreach ( $values as $value ) {
		$a->ok( is_string( $value ), 'option value must stay a string, got ' . gettype( $value ) );
	}
	// The 2.x pitfall: an int whitelist never matched the string from $_POST.
	$a->ok( in_array( '1', $values, true ), 'strict in_array must find the posted string' );
} );

$t->test( 'порядок опций сохраняется', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[select s options="Третий:c,Первый:a,Второй:b"][submit]' );
	$a->same( array( 'c', 'a', 'b' ), CFS_Field_Types::option_values( $r['schema']['fields']['s'] ) );
} );

$t->test( 'запятая в метке опции экранируется', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[select s options="Да\, конечно:yes,Нет:no"][submit]' );
	$field = $r['schema']['fields']['s'];
	$a->same( 2, count( $field['options'] ) );
	$a->same( 'Да, конечно', CFS_Field_Types::option_label( $field, 'yes' ) );
} );

$t->test( 'опция без двоеточия использует текст как значение', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[select s options="Один,Два"][submit]' );
	$a->same( array( 'Один', 'Два' ), CFS_Field_Types::option_values( $r['schema']['fields']['s'] ) );
} );

$t->test( 'значения multicheck не калечатся sanitize_key', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[multicheck m options="Первый:Москва,Второй:One Two"][submit]' );
	$field = $r['schema']['fields']['m'];
	$a->same( array( 'Москва', 'One Two' ), CFS_Field_Types::option_values( $field ) );
	$a->same( true, $field['multiple'] );
	$a->same( '', CFS_Field_Types::validate( $field, array( 'Москва' ) ), 'cyrillic value must validate' );
} );

$t->test( 'дубликат значения опции отбрасывается', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[select s options="Первый:a,Второй:a,Третий:b"][submit]' );
	$a->same( array( 'a', 'b' ), CFS_Field_Types::option_values( $r['schema']['fields']['s'] ) );
} );

$t->test( 'выбор без options — ошибка', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[select s][name n][submit]' );
	$a->same( 1, cfs_test_count_level( $r['errors'], 'error' ), cfs_test_messages( $r['errors'] ) );
	$a->not( isset( $r['schema']['fields']['s'] ) );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Compiler — diagnostics
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'неизвестный тип — ошибка', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[wombat w][name n][submit]' );
	$a->same( 1, cfs_test_count_level( $r['errors'], 'error' ) );
	$a->contains( 'wombat', cfs_test_messages( $r['errors'] ) );
	$a->not( isset( $r['schema']['fields']['w'] ), 'unknown field must not compile' );
} );

$t->test( 'некорректное имя — ошибка', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[text 1abc][submit]' );
	$a->ok( cfs_test_count_level( $r['errors'], 'error' ) >= 1 );
	$a->same( 0, count( $r['schema']['fields'] ) );
} );

$t->test( 'зарезервированное имя — ошибка', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[text action][name n][submit]' );
	$a->contains( 'action', cfs_test_messages( $r['errors'] ) );
	$a->not( isset( $r['schema']['fields']['action'] ) );
} );

$t->test( 'повтор имени — ошибка', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[text a][text a][submit]' );
	$a->same( 1, cfs_test_count_level( $r['errors'], 'error' ), cfs_test_messages( $r['errors'] ) );
	$a->same( 1, count( $r['schema']['fields'] ) );
} );

$t->test( 'нет полей — ошибка', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '<p>просто текст</p>' );
	$a->ok( cfs_test_count_level( $r['errors'], 'error' ) >= 1 );
} );

$t->test( 'нет submit — предупреждение', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[name n]' );
	$a->same( 0, cfs_test_count_level( $r['errors'], 'error' ), cfs_test_messages( $r['errors'] ) );
	$a->same( 1, cfs_test_count_level( $r['errors'], 'warning' ) );
	$a->same( false, $r['schema']['has_submit'] );
} );

$t->test( 'неизвестный атрибут — предупреждение', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[text a lable="опечатка"][submit]' );
	$a->same( 1, cfs_test_count_level( $r['errors'], 'warning' ), cfs_test_messages( $r['errors'] ) );
	$a->contains( 'lable', cfs_test_messages( $r['errors'] ) );
} );

$t->test( 'незакрытый HTML — предупреждение', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '<div>[name n][submit]' );
	$a->contains( 'HTML', cfs_test_messages( $r['errors'] ) );
} );

$t->test( 'одиночные теги не считаются незакрытыми', function ( CFS_Test_Runner $a ) {
	$template = "<p>Текст</p>\n<div class=\"cfs-row\">[name n]</div>\n<hr>\n<br>[submit]";
	$r        = CFS_Form_Compiler::compile( $template );
	$a->same( 0, cfs_test_count_level( $r['errors'], 'warning' ), cfs_test_messages( $r['errors'] ) );
	$a->same( true, CFS_Template_Sanitizer::is_balanced( $template ) );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Compiler — steps and plan
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'разделитель [step] делит форму на шаги', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[name n][step][phone p][submit]' );
	$a->same( 2, count( $r['schema']['steps'] ) );
	$a->same( array( 'n' ), $r['schema']['steps'][0] );
	$a->same( array( 'p' ), $r['schema']['steps'][1] );
} );

$t->test( '[step] в начале задаёт подпись первого шага', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[step label="Контакты"][name n][step label="Детали"][phone p][submit]' );
	$a->same( 2, count( $r['schema']['steps'] ), 'step count' );
	$a->same( array( 'Контакты', 'Детали' ), $r['schema']['step_labels'] );
} );

$t->test( 'висящий [step] в конце не создаёт пустой шаг', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[name n][submit][step]' );
	$a->same( 0, count( $r['schema']['steps'] ), 'single-step form has no steps array' );
} );

$t->test( 'план сохраняет HTML и порядок', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '<p>Привет</p>[name n]<hr>[submit]' );
	$kinds = wp_list_pluck( $r['schema']['plan'], 'kind' );
	$a->same( array( 'html', 'field', 'html', 'submit' ), $kinds );
	$a->contains( '<p>Привет</p>', $r['schema']['plan'][0]['content'] );
} );

$t->test( 'элементы плана несут номер шага', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[name n][step][phone p]' );
	$steps = array();
	foreach ( $r['schema']['plan'] as $entry ) {
		if ( 'field' === $entry['kind'] ) {
			$steps[ $entry['name'] ] = $entry['step'];
		}
	}
	$a->same( 0, $steps['n'] );
	$a->same( 1, $steps['p'] );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Sanitiser
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'kses вырезает script и оставляет разрешённые теги', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( '<p class="x">ок</p><script>alert(1)</script>' );
	$a->contains( '<p class="x">ок</p>', $clean );
	$a->lacks( '<script', $clean );
} );

$t->test( 'kses вырезает поля формы, добавленные руками', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( '<input type="text" name="hack"><button>x</button>' );
	$a->lacks( '<input', $clean );
	$a->lacks( '<button', $clean );
} );

$t->test( 'kses не трогает теги полей', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( '[phone* p label="Телефон"][submit "Отправить"]' );
	$a->same( '[phone* p label="Телефон"][submit "Отправить"]', $clean );
} );

$t->test( 'onclick вырезается', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( '<div onclick="evil()">текст</div>' );
	$a->lacks( 'onclick', $clean );
	$a->contains( 'текст', $clean );
} );

$t->test( 'голый < в атрибуте не ломает тег', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( '[text a label="Цена < 100"][submit]' );
	$r     = CFS_Form_Compiler::compile( $clean );
	$a->ok( isset( $r['schema']['fields']['a'] ), 'field must survive sanitising: ' . $clean );
	if ( isset( $r['schema']['fields']['a'] ) ) {
		$a->same( 'Цена < 100', $r['schema']['fields']['a']['label'] );
	}
} );

$t->test( 'записанная сущность декодируется в метке', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( '[text a label="Цена &lt; 100"][submit]' );
	$r     = CFS_Form_Compiler::compile( $clean );
	$a->ok( isset( $r['schema']['fields']['a'] ), 'field must survive sanitising: ' . $clean );
	if ( isset( $r['schema']['fields']['a'] ) ) {
		$a->same( 'Цена < 100', $r['schema']['fields']['a']['label'] );
	}
} );

$t->test( 'HTML вокруг тегов всё равно чистится', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( '<script>evil()</script>[text a label="x"]<p>ок</p>' );
	$a->lacks( '<script', $clean );
	$a->contains( '[text a label="x"]', $clean );
	$a->contains( '<p>ок</p>', $clean );
} );

$t->test( 'экранированные скобки переживают санитизацию', function ( CFS_Test_Runner $a ) {
	$clean = CFS_Template_Sanitizer::sanitize( 'цена \\[примерная\\] [text a]' );
	$a->contains( '\\[примерная\\]', $clean );
	$a->contains( '[text a]', $clean );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Validation
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'обязательное пустое поле не проходит', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[name* n][submit]' );
	$a->ok( '' !== CFS_Field_Types::validate( $r['schema']['fields']['n'], '' ) );
	$a->same( '', CFS_Field_Types::validate( $r['schema']['fields']['n'], 'Иван' ) );
} );

$t->test( 'необязательное пустое поле проходит', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[email e][submit]' );
	$a->same( '', CFS_Field_Types::validate( $r['schema']['fields']['e'], '' ) );
} );

$t->test( 'телефон принимает 10–11 цифр', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[phone p][submit]' );
	$field = $r['schema']['fields']['p'];
	$a->same( '', CFS_Field_Types::validate( $field, '+7 (900) 123-45-67' ) );
	$a->same( '', CFS_Field_Types::validate( $field, '9001234567' ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '123' ) );
} );

$t->test( 'email проверяется', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[email e][submit]' );
	$field = $r['schema']['fields']['e'];
	$a->same( '', CFS_Field_Types::validate( $field, 'a@b.ru' ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, 'не-почта' ) );
} );

$t->test( 'имя допускает только буквы, дефис и апостроф', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[name n][submit]' );
	$field = $r['schema']['fields']['n'];
	$a->same( '', CFS_Field_Types::validate( $field, "Жанна д'Арк" ) );
	$a->same( '', CFS_Field_Types::validate( $field, 'Анна-Мария' ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, 'Иван123' ) );
} );

$t->test( 'свой pattern проверяется на сервере', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[text code pattern="\d{6}"][submit]' );
	$field = $r['schema']['fields']['code'];
	$a->same( '', CFS_Field_Types::validate( $field, '123456' ), 'matching value passes' );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '12345' ), 'short value rejected' );
	$a->ok( '' !== CFS_Field_Types::validate( $field, 'abcdef' ), 'letters rejected' );
	$a->same( '', CFS_Field_Types::validate( $field, '' ), 'empty optional value still passes' );

	// The pattern is anchored the way HTML5 anchors it, so a matching prefix is
	// not a match.
	$a->ok( '' !== CFS_Field_Types::validate( $field, '1234567' ), 'anchored at both ends' );

	$custom = CFS_Form_Compiler::compile( '[text c pattern="\d{6}" error="Шесть цифр"][submit]' );
	$a->same( 'Шесть цифр', CFS_Field_Types::validate( $custom['schema']['fields']['c'], 'нет' ) );
} );

$t->test( 'дефолтный pattern типа на сервере не применяется', function ( CFS_Test_Runner $a ) {
	// The phone mask sends digits only, while its pattern spells out the
	// displayed "+7 (999) 999-99-99". Enforcing the type default server-side
	// would reject every honest submission the form itself produced.
	$r     = CFS_Form_Compiler::compile( '[phone p][submit]' );
	$field = $r['schema']['fields']['p'];
	$a->contains( '\d{3}', (string) $field['attrs']['pattern'], 'the default is still rendered' );
	$a->same( '', CFS_Field_Types::validate( $field, '79001234567' ), 'digits-only value accepted' );
} );

$t->test( 'нечитаемый для PCRE pattern не отбивает заявку', function ( CFS_Test_Runner $a ) {
	// The visitor did not write the regex and cannot fix it, so an unusable
	// pattern is skipped rather than turned into a rejection.
	$r     = CFS_Form_Compiler::compile( '[text c pattern="[unclosed"][submit]' );
	$field = $r['schema']['fields']['c'];
	$a->same( 'author', $field['pattern_from'] );
	$a->same( '', CFS_Field_Types::validate( $field, 'что угодно' ) );
} );

$t->test( 'значение вне списка опций отклоняется', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[select s options="Да:1,Нет:2"][submit]' );
	$field = $r['schema']['fields']['s'];
	$a->same( '', CFS_Field_Types::validate( $field, '1' ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '99' ) );
} );

$t->test( 'multicheck проверяет каждое значение', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[multicheck m options="Один:a,Два:b"][submit]' );
	$field = $r['schema']['fields']['m'];
	$a->same( '', CFS_Field_Types::validate( $field, array( 'a', 'b' ) ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, array( 'a', 'z' ) ) );
} );

$t->test( 'число проверяет min, max и шаг', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[number age min="18" max="99" step="1"][submit]' );
	$field = $r['schema']['fields']['age'];
	$a->same( '', CFS_Field_Types::validate( $field, '30' ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '10' ), 'below min' );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '120' ), 'above max' );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '30.5' ), 'off step' );
	$a->ok( '' !== CFS_Field_Types::validate( $field, 'abc' ), 'not numeric' );
} );

$t->test( 'дробный шаг не даёт ложных срабатываний', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[number n min="0" max="10" step="0.1"][submit]' );
	$field = $r['schema']['fields']['n'];
	foreach ( array( '0.1', '0.3', '0.7', '2.4', '9.9' ) as $value ) {
		$a->same( '', CFS_Field_Types::validate( $field, $value ), "step 0.1 with {$value}" );
	}
	$a->ok( '' !== CFS_Field_Types::validate( $field, '0.15' ), 'off step' );
} );

$t->test( 'дата проверяет формат и границы', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[date d min="2020-01-01" max="2030-12-31"][submit]' );
	$field = $r['schema']['fields']['d'];
	$a->same( '', CFS_Field_Types::validate( $field, '2025-06-15' ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '15.06.2025' ), 'wrong format' );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '2019-01-01' ), 'before min' );
	$a->ok( '' !== CFS_Field_Types::validate( $field, '2031-01-01' ), 'after max' );
} );

$t->test( 'длина комментария ограничивается', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[textarea c maxlength="10"][submit]' );
	$field = $r['schema']['fields']['c'];
	$a->same( '', CFS_Field_Types::validate( $field, 'коротко' ) );
	$a->ok( '' !== CFS_Field_Types::validate( $field, str_repeat( 'я', 11 ) ) );
} );

$t->test( 'своё сообщение об ошибке перекрывает стандартное', function ( CFS_Test_Runner $a ) {
	$r     = CFS_Form_Compiler::compile( '[email* e error="Нужна почта"][submit]' );
	$field = $r['schema']['fields']['e'];
	$a->same( 'Нужна почта', CFS_Field_Types::validate( $field, '' ) );
	$a->same( 'Нужна почта', CFS_Field_Types::validate( $field, 'мусор' ) );
} );

/* ─────────────────────────────────────────────────────────────────────────
 * Sanitisation of submitted values
 * ────────────────────────────────────────────────────────────────────── */

$t->test( 'санитизация по типу поля', function ( CFS_Test_Runner $a ) {
	$a->same( 'Иван', CFS_Field_Types::sanitize( 'name', '  Иван  ' ) );
	$a->same( 'a@b.ru', CFS_Field_Types::sanitize( 'email', 'a@b.ru' ) );
	$a->same( "строка1\nстрока2", CFS_Field_Types::sanitize( 'textarea', "строка1\nстрока2" ) );
	$a->same( '1', CFS_Field_Types::sanitize( 'checkbox', '1' ) );
	$a->same( '', CFS_Field_Types::sanitize( 'checkbox', '' ) );
	$a->same( array( 'a', 'b' ), CFS_Field_Types::sanitize( 'multicheck', array( 'a', 'b' ) ) );
	$a->same( '', CFS_Field_Types::sanitize( 'text', array( 'массив', 'вместо', 'строки' ) ) );
} );

$t->test( 'отображение значений человекочитаемо', function ( CFS_Test_Runner $a ) {
	$r = CFS_Form_Compiler::compile( '[select s options="Консультация:consult"][multicheck m options="Один:a,Два:b"][checkbox c][phone p][submit]' );
	$a->same( 'Консультация', CFS_Field_Types::display( $r['schema']['fields']['s'], 'consult' ) );
	$a->same( 'Один, Два', CFS_Field_Types::display( $r['schema']['fields']['m'], array( 'a', 'b' ) ) );
	$a->same( '+7 (900) 123-45-67', CFS_Field_Types::display( $r['schema']['fields']['p'], '79001234567' ) );
	$a->contains( 'Да', CFS_Field_Types::display( $r['schema']['fields']['c'], '1' ) );
} );

exit( $t->summary() );
