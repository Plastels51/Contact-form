<?php
/**
 * Custom post type that stores forms.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Post_Type
 *
 * The post type is deliberately invisible: forms are edited through the
 * plugin's own screens, so the default WordPress post UI would only offer a
 * title field and an editor that does not understand the template syntax.
 */
class CFS_Post_Type {

	/**
	 * Post type name.
	 */
	const POST_TYPE = 'cfs_form';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Формы', 'contact-form-submissions' ),
					'singular_name' => __( 'Форма', 'contact-form-submissions' ),
					'add_new_item'  => __( 'Новая форма', 'contact-form-submissions' ),
					'edit_item'     => __( 'Редактирование формы', 'contact-form-submissions' ),
					'search_items'  => __( 'Искать формы', 'contact-form-submissions' ),
					'not_found'     => __( 'Формы не найдены', 'contact-form-submissions' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'query_var'           => false,
				'rewrite'             => false,
				'hierarchical'        => false,
				'has_archive'         => false,
				'can_export'          => true,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'author' ),
			)
		);
	}

	/**
	 * Capability required to manage forms and plugin settings.
	 *
	 * Returned as a single capability rather than a mapped capability_type: the
	 * post type has no WordPress UI to guard, and every plugin screen checks
	 * this one value.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return (string) apply_filters( 'cfs_manage_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may manage forms.
	 *
	 * @return bool
	 */
	public static function user_can_manage(): bool {
		return current_user_can( self::capability() );
	}
}
