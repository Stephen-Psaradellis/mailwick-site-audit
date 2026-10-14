<?php
/**
 * Site Health tests (Tools > Site Health).
 *
 * The update check is left out here because WordPress's own Site Health
 * already reports outdated core, plugins and themes.
 *
 * @package SiteCheckEmailPadlockAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the plugin's checks to Site Health, reading the same cached results.
 */
final class SCEPA_Site_Health {

	/**
	 * Register the filter.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
	}

	/**
	 * Checks shown in Site Health, with their badge group.
	 *
	 * @return array<string,string>
	 */
	private static function tests() {
		return array(
			'email_forgery'  => 'security',
			'padlock'        => 'security',
			'phone_view'     => 'performance',
			'tap_to_call'    => 'performance',
			'contact_email'  => 'performance',
			'search_snippet' => 'performance',
		);
	}

	/**
	 * Add the direct tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function register( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$labels = SCEPA_Checks::labels();
		foreach ( array_keys( self::tests() ) as $id ) {
			$tests['direct'][ 'scepa_' . $id ] = array(
				'label' => $labels[ $id ],
				'test'  => array( __CLASS__, 'test_' . $id ),
			);
		}
		return $tests;
	}

	/**
	 * Email forgery test.
	 *
	 * @return array
	 */
	public static function test_email_forgery() {
		return self::result( 'email_forgery' );
	}

	/**
	 * Padlock test.
	 *
	 * @return array
	 */
	public static function test_padlock() {
		return self::result( 'padlock' );
	}

	/**
	 * Phone view test.
	 *
	 * @return array
	 */
	public static function test_phone_view() {
		return self::result( 'phone_view' );
	}

	/**
	 * Tap-to-call test.
	 *
	 * @return array
	 */
	public static function test_tap_to_call() {
		return self::result( 'tap_to_call' );
	}

	/**
	 * Contact email test.
	 *
	 * @return array
	 */
	public static function test_contact_email() {
		return self::result( 'contact_email' );
	}

	/**
	 * Search snippet test.
	 *
	 * @return array
	 */
	public static function test_search_snippet() {
		return self::result( 'search_snippet' );
	}

	/**
	 * Convert a cached check row to a Site Health result.
	 *
	 * @param string $id Check id.
	 * @return array
	 */
	private static function result( $id ) {
		$results = SCEPA_Checks::get_results();
		$row     = isset( $results['rows'][ $id ] ) ? $results['rows'][ $id ] : null;
		$groups  = self::tests();

		$badge = array(
			'label' => 'security' === $groups[ $id ]
				? __( 'Security', 'mailwick-site-audit' )
				: __( 'Mailwick Site Audit', 'mailwick-site-audit' ),
			'color' => 'blue',
		);

		if ( null === $row ) {
			return array(
				'label'       => SCEPA_Checks::labels()[ $id ],
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'This check has not run yet.', 'mailwick-site-audit' ) . '</p>',
				'actions'     => self::actions( $id ),
				'test'        => 'scepa_' . $id,
			);
		}

		$status = in_array( $row['status'], array( 'good', 'recommended', 'critical' ), true ) ? $row['status'] : 'recommended';

		$description = '';
		foreach ( (array) $row['findings'] as $finding ) {
			$description .= '<p>' . esc_html( $finding[1] ) . '</p>';
		}

		return array(
			// Plain text: the Site Health template escapes the label itself.
			'label'       => wp_strip_all_tags( $row['summary'] ),
			'status'      => $status,
			'badge'       => $badge,
			'description' => $description,
			'actions'     => 'good' === $status ? '' : self::actions( $id ),
			'test'        => 'scepa_' . $id,
		);
	}

	/**
	 * Link to the plugin's page for the fix steps.
	 *
	 * @param string $id Check id.
	 * @return string
	 */
	private static function actions( $id ) {
		$url = admin_url( 'tools.php?page=mailwick-site-audit' ) . '#scepa-' . $id;
		return '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'See how to fix it', 'mailwick-site-audit' ) . '</a></p>';
	}
}
