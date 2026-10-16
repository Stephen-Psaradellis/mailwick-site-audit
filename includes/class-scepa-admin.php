<?php
/**
 * The Tools > Mailwick Site Audit admin page.
 *
 * @package SiteCheckEmailPadlockAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page, its two form handlers and its notices.
 */
final class SCEPA_Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'mailwick-site-audit';

	/**
	 * User meta key holding a one-time notice for the next page view.
	 */
	const NOTICE_META = 'scepa_notice';

	/**
	 * Hook suffix of the admin page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scepa_rescan', array( __CLASS__, 'handle_rescan' ) );
		add_action( 'admin_post_scepa_test_email', array( __CLASS__, 'handle_test_email' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCEPA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * URL of the admin page.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'tools.php?page=' . self::PAGE );
	}

	/**
	 * Add Tools > Mailwick Site Audit.
	 *
	 * @return void
	 */
	public static function menu() {
		$hook = add_management_page(
			__( 'Mailwick Site Audit', 'mailwick-site-audit' ),
			__( 'Mailwick Site Audit', 'mailwick-site-audit' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Load the stylesheet on the plugin's own page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function assets( $hook_suffix ) {
		if ( '' === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'scepa-admin', plugins_url( 'assets/admin.css', SCEPA_FILE ), array(), SCEPA_VERSION );
	}

	/**
	 * "Run checks" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Run checks', 'mailwick-site-audit' ) . '</a>'
			);
		}
		return $links;
	}

	/**
	 * Store a one-time notice for the current user.
	 *
	 * @param string $code    Notice code.
	 * @param string $message Optional extra text.
	 * @return void
	 */
	private static function set_notice( $code, $message = '' ) {
		update_user_meta(
			get_current_user_id(),
			self::NOTICE_META,
			array(
				'code'    => sanitize_key( $code ),
				'message' => sanitize_text_field( $message ),
			)
		);
	}

	/**
	 * Read and clear the current user's notice.
	 *
	 * @return array|null
	 */
	private static function pop_notice() {
		$user_id = get_current_user_id();
		$notice  = get_user_meta( $user_id, self::NOTICE_META, true );
		if ( '' !== $notice ) {
			delete_user_meta( $user_id, self::NOTICE_META );
		}
		return is_array( $notice ) && isset( $notice['code'] ) ? $notice : null;
	}

	/**
	 * Stop unless the current user may manage options.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to do that.', 'mailwick-site-audit' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Handle "Run the checks again".
	 *
	 * @return void
	 */
	public static function handle_rescan() {
		self::require_capability();
		check_admin_referer( 'scepa_rescan' );
		SCEPA_Checks::get_results( true );
		self::set_notice( 'rescanned' );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Handle "Send a test email" to the site's admin address.
	 *
	 * @return void
	 */
	public static function handle_test_email() {
		self::require_capability();
		check_admin_referer( 'scepa_test_email' );

		$to = get_option( 'admin_email' );
		if ( ! is_string( $to ) || ! is_email( $to ) ) {
			self::set_notice( 'no_admin_email' );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$error    = '';
		$on_error = static function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $on_error );

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Mailwick test email', 'mailwick-site-audit' ), $site_name );
		$body    = sprintf(
			/* translators: 1: site address, 2: date and time. */
			__( "This is a test email from the Mailwick Site Audit plugin on %1\$s, sent %2\$s.\n\nIf it reached your inbox, your site's email is being delivered. If you found it in spam, or it took a long time to arrive, contact form messages are probably going the same way.", 'mailwick-site-audit' ),
			home_url( '/' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
		);

		$sent = wp_mail( $to, $subject, $body );
		remove_action( 'wp_mail_failed', $on_error );

		if ( $sent ) {
			self::set_notice( 'mail_sent', $to );
		} else {
			self::set_notice( 'mail_failed', $error );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Labels for each status.
	 *
	 * @return string[]
	 */
	private static function status_labels() {
		return array(
			'critical'    => __( 'Critical', 'mailwick-site-audit' ),
			'recommended' => __( 'Recommended', 'mailwick-site-audit' ),
			'unknown'     => __( 'Not checked', 'mailwick-site-audit' ),
			'good'        => __( 'Good', 'mailwick-site-audit' ),
		);
	}

	/**
	 * Print the notice left by a form handler, if any.
	 *
	 * @return void
	 */
	private static function render_notice() {
		$notice = self::pop_notice();
		if ( null === $notice ) {
			return;
		}
		$type = 'success';
		switch ( $notice['code'] ) {
			case 'rescanned':
				$text = __( 'The checks were run again.', 'mailwick-site-audit' );
				break;
			case 'mail_sent':
				/* translators: %s: email address. */
				$text = sprintf( __( 'WordPress handed a test email to the mail system for %s. Check that inbox, and its spam folder, in the next few minutes.', 'mailwick-site-audit' ), $notice['message'] );
				break;
			case 'mail_failed':
				$type = 'error';
				$text = __( 'The test email could not be sent. This server cannot send mail as it is set up now.', 'mailwick-site-audit' );
				if ( '' !== $notice['message'] ) {
					$text .= ' ' . $notice['message'];
				}
				break;
			case 'no_admin_email':
				$type = 'error';
				$text = __( 'The administration email address in Settings > General is not valid, so no test email was sent.', 'mailwick-site-audit' );
				break;
			default:
				return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		self::require_capability();

		$results = SCEPA_Checks::get_results();
		$labels  = SCEPA_Checks::labels();
		$badges  = self::status_labels();
		$counts  = array_fill_keys( array_keys( $badges ), 0 );
		foreach ( $results['rows'] as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				++$counts[ $row['status'] ];
			}
		}
		?>
		<div class="wrap scepa-wrap">
			<h1><?php esc_html_e( 'Mailwick Site Audit', 'mailwick-site-audit' ); ?></h1>
			<?php self::render_notice(); ?>

			<p class="scepa-intro">
				<?php esc_html_e( 'Seven checks for faults that cost small sites visitors, email and trust. Everything runs on this server: the only lookups are DNS records for your own domain and a visit to your own front page. Nothing is sent anywhere else.', 'mailwick-site-audit' ); ?>
			</p>

			<p class="scepa-summary">
				<?php
				printf(
					/* translators: 1: time since the last run, such as "5 mins", 2: count of critical, 3: count of recommended, 4: count of good. */
					esc_html__( 'Last run %1$s ago: %2$d critical, %3$d recommended, %4$d good.', 'mailwick-site-audit' ),
					esc_html( human_time_diff( (int) $results['time'] ) ),
					(int) $counts['critical'],
					(int) $counts['recommended'],
					(int) $counts['good']
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="scepa-inline-form">
				<input type="hidden" name="action" value="scepa_rescan" />
				<?php wp_nonce_field( 'scepa_rescan' ); ?>
				<?php submit_button( __( 'Run the checks again', 'mailwick-site-audit' ), 'primary', 'submit', false ); ?>
			</form>

			<table class="widefat striped scepa-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Check', 'mailwick-site-audit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'mailwick-site-audit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What it means and how to fix it', 'mailwick-site-audit' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $labels as $id => $label ) {
					if ( isset( $results['rows'][ $id ] ) ) {
						self::render_row( $id, $label, $results['rows'][ $id ], $badges );
					}
				}
				?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The fix guide for one check: a page under SCEPA_FIX_URL with the steps and a quote form.
	 *
	 * @param string $id Check id.
	 * @return string URL.
	 */
	private static function fix_url( $id ) {
		$guides = array(
			'email_forgery'  => 'forged-email-fix.html',
			'padlock'        => 'not-secure-fix.html',
			'updates'        => 'wordpress-update-fix.html',
			'phone_view'     => 'phone-view-fix.html',
			'tap_to_call'    => 'tap-to-call-fix.html',
			'contact_email'  => 'mailto-form-fix.html',
			'search_snippet' => 'search-snippet-fix.html',
		);
		return SCEPA_FIX_URL . ( isset( $guides[ $id ] ) ? $guides[ $id ] : '' );
	}

	/**
	 * Render one check row.
	 *
	 * @param string $id     Check id.
	 * @param string $label  Check label.
	 * @param array  $row    Check result.
	 * @param array  $badges Status labels.
	 * @return void
	 */
	private static function render_row( $id, $label, $row, $badges ) {
		$status  = isset( $badges[ $row['status'] ] ) ? $row['status'] : 'unknown';
		$failing = in_array( $status, array( 'critical', 'recommended' ), true );
		$details = array_filter( array_map( 'strval', (array) $row['details'] ), 'strlen' );
		?>
		<tr id="<?php echo esc_attr( 'scepa-' . $id ); ?>">
			<th scope="row" class="scepa-check"><?php echo esc_html( $label ); ?></th>
			<td class="scepa-result">
				<span class="scepa-badge scepa-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $badges[ $status ] ); ?></span>
			</td>
			<td class="scepa-body">
				<p class="scepa-headline"><strong><?php echo esc_html( $row['summary'] ); ?></strong></p>
				<?php if ( ! empty( $row['findings'] ) ) : ?>
					<ul class="scepa-findings">
						<?php foreach ( $row['findings'] as $finding ) : ?>
							<li class="scepa-finding--<?php echo esc_attr( $finding[0] ); ?>"><?php echo esc_html( $finding[1] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $row['steps'] ) ) : ?>
					<details class="scepa-steps"
					<?php
					if ( $failing ) {
						echo ' open';
					}
					?>
					>
						<summary><?php esc_html_e( 'Fix it yourself', 'mailwick-site-audit' ); ?></summary>
						<ol>
							<?php foreach ( $row['steps'] as $step ) : ?>
								<li><?php echo esc_html( $step ); ?></li>
							<?php endforeach; ?>
						</ol>
					</details>
				<?php endif; ?>

				<?php if ( ! empty( $details ) ) : ?>
					<details class="scepa-details">
						<summary><?php esc_html_e( 'What was found', 'mailwick-site-audit' ); ?></summary>
						<ul>
							<?php foreach ( $details as $line ) : ?>
								<li><code><?php echo esc_html( $line ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>

				<?php if ( 'contact_email' === $id ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="scepa-inline-form">
						<input type="hidden" name="action" value="scepa_test_email" />
						<?php wp_nonce_field( 'scepa_test_email' ); ?>
						<?php
						submit_button(
							sprintf(
								/* translators: %s: the site's administration email address. */
								__( 'Send a test email to %s', 'mailwick-site-audit' ),
								(string) get_option( 'admin_email' )
							),
							'secondary',
							'submit',
							false
						);
						?>
					</form>
				<?php endif; ?>

				<?php if ( $failing ) : ?>
					<p class="scepa-done-for-you">
						<?php esc_html_e( 'Prefer it done for you?', 'mailwick-site-audit' ); ?>
						<a href="<?php echo esc_url( self::fix_url( $id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See the fix guide and a fixed price', 'mailwick-site-audit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'mailwick-site-audit' ); ?></span></a>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
