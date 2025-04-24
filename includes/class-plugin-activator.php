<?php
/**
 * PRC Attachments Inspector
 *
 * @package PRC\Platform\Attachments_Inspector
 */

namespace PRC\Platform\Attachments_Inspector;

use DEFAULT_TECHNICAL_CONTACT;

/**
 * The code that runs during plugin activation.
 */
class Plugin_Activator {
	/**
	 * Activate the plugin.
	 */
	public static function activate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Attachments Inspector Activated',
			'The PRC Attachments Inspector plugin has been activated on ' . get_site_url()
		);
	}
}
