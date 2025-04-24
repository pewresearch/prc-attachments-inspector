<?php
/**
 * PRC Attachments Inspector
 *
 * @package           PRC_ATTACHMENTS_INSPECTOR
 * @author            Seth Rubenstein
 * @copyright         2024 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Attachments Inspector
 * Plugin URI:        https://github.com/pewresearch/prc-attachments-inspector
 * Description:       This plugin adds an Attachments Inspector panel to the editor, allowing editors to easily drag and drop attachments and search for media library items to insert into posts. It also features an Attachments Report accessible to social media editors and other stakeholders from the post edit screen or admin bar.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-attachments-inspector
 * Requires Plugins:  prc-platform-core
 */

namespace PRC\Platform\Attachments_Inspector;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRC_ATTACHMENTS_INSPECTOR_FILE', __FILE__ );
define( 'PRC_ATTACHMENTS_INSPECTOR_DIR', __DIR__ );
define( 'PRC_ATTACHMENTS_INSPECTOR_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-plugin-activator.php
 */
function activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-activator.php';
	Plugin_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-plugin-deactivator.php
 */
function deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-deactivator.php';
	Plugin_Deactivator::deactivate();
}

register_activation_hook( __FILE__, '\PRC\Platform\Attachments_Inspector\activate' );
register_deactivation_hook( __FILE__, '\PRC\Platform\Attachments_Inspector\deactivate' );

/**
 * Helper utilities
 */
require plugin_dir_path( __FILE__ ) . 'includes/utils.php';

/**
 * The core plugin class that is used to define the hooks that initialize the various components.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-plugin.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_prc_attachments_inspector() {
	$plugin = new Plugin();
	$plugin->run();
}
run_prc_attachments_inspector();
