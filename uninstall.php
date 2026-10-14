<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * Stored data: the cached results (transient "scepa_results") and a one-time
 * notice per admin user (user meta "scepa_notice").
 *
 * @package SiteCheckEmailPadlockAudit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function scepa_uninstall_site() {
	delete_transient( 'scepa_results' );
}

delete_metadata( 'user', 0, 'scepa_notice', '', true );

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $scepa_site_id ) {
		switch_to_blog( (int) $scepa_site_id );
		scepa_uninstall_site();
		restore_current_blog();
	}
} else {
	scepa_uninstall_site();
}
