<?php
/**
 * PRC Attachments Inspector
 *
 * @package PRC\Platform\Attachments_Inspector
 */

namespace PRC\Platform\Attachments_Inspector;

use DEFAULT_TECHNICAL_CONTACT;

/**
 * The code that runs during plugin deactivation.
 */
class Plugin_Deactivator {
	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Attachments Inspector Deactivated',
			'The PRC Attachments Inspector plugin has been deactivated on ' . get_site_url()
		);
	}
}
