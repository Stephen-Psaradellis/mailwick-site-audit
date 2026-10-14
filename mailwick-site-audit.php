<?php
/**
 * Plugin Name:       Mailwick Site Audit
 * Description:       Checks your site for email forgery protection (SPF and DMARC), padlock and mixed content problems, out-of-date software, phone view, tap-to-call links, contact form email delivery and the search snippet, then explains each fault in plain English with steps to fix it.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Stephen Psaradellis
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mailwick-site-audit
 *
 * @package SiteCheckEmailPadlockAudit
 */

/*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCEPA_VERSION', '1.0.0' );
define( 'SCEPA_FILE', __FILE__ );
define( 'SCEPA_DIR', plugin_dir_path( __FILE__ ) );

/*
 * The only link to an outside site. It is shown on the plugin's own Tools page,
 * under a failing check, and is never followed or requested by the plugin.
 */
define( 'SCEPA_FIX_URL', 'https://stephen-psaradellis.github.io/demos/' );

require_once SCEPA_DIR . 'includes/class-scepa-checks.php';
require_once SCEPA_DIR . 'includes/class-scepa-site-health.php';

SCEPA_Site_Health::init();

if ( is_admin() ) {
	require_once SCEPA_DIR . 'includes/class-scepa-admin.php';
	SCEPA_Admin::init();
}

/**
 * Clear cached results when the plugin is deactivated.
 *
 * @return void
 */
function scepa_deactivate() {
	delete_transient( SCEPA_Checks::TRANSIENT );
}
register_deactivation_hook( __FILE__, 'scepa_deactivate' );
