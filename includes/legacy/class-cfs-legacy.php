<?php
/**
 * Compatibility module for the 2.x shortcode syntax.
 *
 * The core knows nothing about this folder. It is loaded by one guarded
 * require in the main plugin file and answers only public hooks, so deleting
 * the folder is the whole uninstall procedure — see README-REMOVAL.md.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Legacy
 */
class CFS_Legacy {

	/**
	 * Option holding the migration state.
	 */
	const OPTION_MIGRATED = 'cfs_legacy_migrated';

	/**
	 * Whether the module has been booted.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * The 2.x renderer.
	 *
	 * @var CFS_Form_Builder|null
	 */
	private static $builder = null;

	/**
	 * The migration screen.
	 *
	 * @var CFS_Legacy_Wizard|null
	 */
	private static $wizard = null;

	/**
	 * Load the module.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$dir = __DIR__ . '/';

		require_once $dir . 'class-cfs-form-builder.php';
		require_once $dir . 'class-cfs-legacy-adapter.php';
		require_once $dir . 'class-cfs-legacy-scanner.php';
		require_once $dir . 'class-cfs-legacy-wizard.php';

		// Answers cfs_render_legacy_form, which CFS_Shortcode consults only
		// when a shortcode names no form at all.
		self::$builder = new CFS_Form_Builder( new CFS_DB() );

		if ( is_admin() ) {
			self::$wizard = new CFS_Legacy_Wizard();
		}
	}

	/**
	 * Migration state: date and counters, or an empty array.
	 *
	 * @return array
	 */
	public static function migration_state(): array {
		$state = get_option( self::OPTION_MIGRATED, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Record a completed migration.
	 *
	 * @param int $forms        Forms created.
	 * @param int $replacements Shortcodes rewritten.
	 */
	public static function record_migration( int $forms, int $replacements ): void {
		$state = self::migration_state();

		update_option(
			self::OPTION_MIGRATED,
			array(
				'date'         => current_time( 'mysql' ),
				'forms'        => (int) ( $state['forms'] ?? 0 ) + $forms,
				'replacements' => (int) ( $state['replacements'] ?? 0 ) + $replacements,
			),
			false
		);
	}
}
