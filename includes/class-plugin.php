<?php
/**
 * PRC Attachments Inspector
 *
 * @package PRC\Platform\Attachments_Inspector
 */

namespace PRC\Platform\Attachments_Inspector;

use WP_Error;

/**
 * Plugin class.
 *
 * @package PRC\Platform\Attachments_Inspector
 */
class Plugin {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Shared image-mismatch scanner.
	 *
	 * @var Image_Mismatch_Scanner
	 */
	protected $scanner;

	/**
	 * Define the core functionality of the platform as initialized by hooks.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = '1.0.0';
		$this->plugin_name = 'prc-attachments-inspector';

		$this->load_dependencies();
		$this->init_dependencies();
	}


	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		// Load plugin loading class.
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';

		// Initialize the loader.
		$this->loader = new Loader();

		require_once plugin_dir_path( __DIR__ ) . '/includes/attachment-report/class-attachment-report.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/attachments-panel/class-attachments-panel.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/image-mismatch/class-image-mismatch-scanner.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/image-mismatch/class-image-mismatch.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/image-mismatch/class-ability-categories.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/image-mismatch/class-ability.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once plugin_dir_path( __DIR__ ) . '/includes/image-mismatch/class-cli.php';
		}
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->scanner = new Image_Mismatch_Scanner();

		new Attachment_Report( $this->get_loader() );
		new Attachments_Panel( $this->get_loader() );
		new Image_Mismatch( $this->get_loader(), $this->scanner );
		new Ability_Categories( $this->get_loader() );
		new Ability( $this->get_loader(), $this->scanner );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( __NAMESPACE__ . '\\CLI' ) ) {
			$cli = new CLI( $this->scanner );
			\WP_CLI::add_command(
				'prc attachments-inspector mismatch-report',
				array( $cli, 'mismatch_report' )
			);
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    PRC\Platform\Attachments_Inspector\Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
