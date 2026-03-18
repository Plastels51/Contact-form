<?php
/**
 * Main plugin class — Singleton.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Plugin
 */
class CFS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var CFS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * DB handler.
	 *
	 * @var CFS_DB
	 */
	private $db;

	/**
	 * Form builder.
	 *
	 * @var CFS_Form_Builder
	 */
	private $form_builder;

	/**
	 * AJAX handler.
	 *
	 * @var CFS_Ajax_Handler
	 */
	private $ajax_handler;

	/**
	 * Admin handler.
	 *
	 * @var CFS_Admin
	 */
	private $admin;

	/**
	 * Dashboard widget.
	 *
	 * @var CFS_Dashboard
	 */
	private $dashboard;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Get or create singleton instance.
	 *
	 * @return CFS_Plugin
	 */
	public static function get_instance(): CFS_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialise all sub-systems and register hooks.
	 */
	private function init(): void {
		$this->db           = new CFS_DB();
		$this->form_builder = new CFS_Form_Builder( $this->db );
		$this->ajax_handler = new CFS_Ajax_Handler( $this->db );
		$this->dashboard    = new CFS_Dashboard( $this->db );

		if ( is_admin() ) {
			$this->admin = new CFS_Admin( $this->db );
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			CFS_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( CFS_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Plugin activation callback.
	 */
	public static function activate(): void {
		$db = new CFS_DB();
		$db->create_tables();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
