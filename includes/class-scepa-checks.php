<?php
/**
 * The checks. Each one returns a row describing what was found, in plain English.
 *
 * Network use, in full: DNS TXT lookups for the site's own domain (SPF, DMARC),
 * and HTTP requests to the site's own home URL. Nothing else is contacted and no
 * data leaves the site.
 *
 * @package SiteCheckEmailPadlockAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs and caches the site checks.
 */
final class SCEPA_Checks {

	/**
	 * Transient that holds the last results.
	 */
	const TRANSIENT = 'scepa_results';

	/**
	 * How long results are kept before a page view re-runs them (12 hours).
	 */
	const CACHE_TTL = 43200;

	/**
	 * Results already loaded in this request.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * Plugin files of known mail-sending (SMTP / API) plugins.
	 *
	 * @var string[]
	 */
	private static $mail_plugins = array(
		'wp-mail-smtp/wp_mail_smtp.php'                       => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php'                   => 'WP Mail SMTP Pro',
		'fluent-smtp/fluent-smtp.php'                         => 'FluentSMTP',
		'post-smtp/postman-smtp.php'                          => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'                       => 'Easy WP SMTP',
		'smtp-mailer/main.php'                                => 'SMTP Mailer',
		'wp-smtp/wp-smtp.php'                                 => 'WP SMTP',
		'gosmtp/gosmtp.php'                                   => 'GoSMTP',
		'suremails/suremails.php'                             => 'SureMail',
		'wp-ses/wp-ses.php'                                   => 'WP Offload SES',
		'mailgun/mailgun.php'                                 => 'Mailgun',
		'sendgrid-email-delivery-simplified/wpsendgrid.php'   => 'SendGrid',
		'smtp2go/smtp2go-wordpress-plugin.php'                => 'SMTP2GO',
		'mailin/sendinblue.php'                               => 'Brevo',
		'mailpoet/mailpoet.php'                               => 'MailPoet',
		'site-mailer/site-mailer.php'                         => 'Site Mailer',
		'check-email/check-email.php'                         => 'Check & Log Email',
	);

	/**
	 * Return results, from cache when fresh, otherwise by running every check.
	 *
	 * @param bool $force Run the checks even if cached results exist.
	 * @return array{time:int,rows:array}
	 */
	public static function get_results( $force = false ) {
		if ( ! $force ) {
			if ( null !== self::$memo ) {
				return self::$memo;
			}
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['rows'], $cached['time'] ) ) {
				self::$memo = $cached;
				return $cached;
			}
		}

		$results = self::run_all();
		set_transient( self::TRANSIENT, $results, self::CACHE_TTL );
		self::$memo = $results;
		return $results;
	}

	/**
	 * Human labels for each check, in display order.
	 *
	 * @return string[]
	 */
	public static function labels() {
		return array(
			'email_forgery'  => __( 'Email forgery protection (SPF and DMARC)', 'mailwick-site-audit' ),
			'padlock'        => __( 'Padlock and mixed content (SSL)', 'mailwick-site-audit' ),
			'updates'        => __( 'WordPress, plugins and themes up to date', 'mailwick-site-audit' ),
			'phone_view'     => __( 'Phone view (viewport tag)', 'mailwick-site-audit' ),
			'tap_to_call'    => __( 'Tap-to-call phone numbers', 'mailwick-site-audit' ),
			'contact_email'  => __( 'Contact form emails arrive', 'mailwick-site-audit' ),
			'search_snippet' => __( 'Search snippet (meta description)', 'mailwick-site-audit' ),
		);
	}

	/**
	 * Run every check once.
	 *
	 * @return array{time:int,rows:array}
	 */
	public static function run_all() {
		$page = self::fetch_home();

		return array(
			'time' => time(),
			'rows' => array(
				'email_forgery'  => self::check_email_forgery(),
				'padlock'        => self::check_padlock( $page ),
				'updates'        => self::check_updates(),
				'phone_view'     => self::check_viewport( $page ),
				'tap_to_call'    => self::check_tap_to_call( $page ),
				'contact_email'  => self::check_contact_email(),
				'search_snippet' => self::check_meta_description( $page ),
			),
		);
	}

	/**
	 * Severity order, worst last.
	 *
	 * @param string $status Status key.
	 * @return int
	 */
	public static function rank( $status ) {
		$order = array(
			'good'        => 0,
			'unknown'     => 1,
			'recommended' => 2,
			'critical'    => 3,
		);
		return isset( $order[ $status ] ) ? $order[ $status ] : 0;
	}

	/**
	 * Build a row from its findings. The row's status is its worst finding.
	 *
	 * @param string $summary  One-line result shown as the headline.
	 * @param array  $findings List of array( status, sentence ).
	 * @param array  $steps    Fix-it-yourself steps.
	 * @param array  $details  Evidence lines.
	 * @return array
	 */
	private static function row( $summary, $findings, $steps = array(), $details = array() ) {
		$status = 'good';
		foreach ( $findings as $finding ) {
			if ( self::rank( $finding[0] ) > self::rank( $status ) ) {
				$status = $finding[0];
			}
		}
		usort(
			$findings,
			static function ( $a, $b ) {
				return self::rank( $b[0] ) - self::rank( $a[0] );
			}
		);

		return array(
			'status'   => $status,
			'summary'  => $summary,
			'findings' => array_values( $findings ),
			'steps'    => array_values( array_unique( $steps ) ),
			'details'  => array_values( $details ),
		);
	}

	/**
	 * Fetch the site's own front page, verifying its certificate.
	 *
	 * @return array{url:string,ok:bool,html:string,error:string,ssl_error:bool,code:int}
	 */
	private static function fetch_home() {
		$url = home_url( '/' );
		$out = array(
			'url'       => $url,
			'ok'        => false,
			'html'      => '',
			'error'     => '',
			'ssl_error' => false,
			'code'      => 0,
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 5,
				'sslverify'           => true,
				'limit_response_size' => 3 * MB_IN_BYTES,
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message          = $response->get_error_message();
			$out['error']     = $message;
			$out['ssl_error'] = 'https' === wp_parse_url( $url, PHP_URL_SCHEME )
				&& (bool) preg_match( '/ssl|certificate|tls|cURL error (35|51|58|60)\b/i', $message );
			return $out;
		}

		$out['code'] = (int) wp_remote_retrieve_response_code( $response );
		$out['html'] = (string) wp_remote_retrieve_body( $response );
		$out['ok']   = $out['code'] >= 200 && $out['code'] < 400 && '' !== $out['html'];
		if ( ! $out['ok'] ) {
			/* translators: %d: HTTP status code, for example 500. */
			$out['error'] = sprintf( __( 'The front page answered with HTTP status %d.', 'mailwick-site-audit' ), $out['code'] );
		}
		return $out;
	}

	/**
	 * A row for a check that needs the front page when the page could not be loaded.
	 *
	 * @param string $summary Headline.
	 * @param array  $page    Result of fetch_home().
	 * @return array
	 */
	private static function page_unavailable( $summary, $page ) {
		return self::row(
			$summary,
			array(
				array( 'unknown', __( 'The server could not load your front page to look at it, so this check was skipped.', 'mailwick-site-audit' ) ),
			),
			array(
				__( 'Open Tools > Site Health and look at the "loopback request" test; some hosts block a site from loading itself.', 'mailwick-site-audit' ),
				__( 'If your site is in maintenance or "coming soon" mode, switch that off and run the checks again.', 'mailwick-site-audit' ),
			),
			array( $page['error'] )
		);
	}

	/* --------------------------------------------------------------------
	 * 1. Email forgery: SPF and DMARC.
	 * ------------------------------------------------------------------ */

	/**
	 * The site's registrable host name without "www.", or '' when it is not a public domain.
	 *
	 * @return string
	 */
	public static function site_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		$host = strtolower( rtrim( $host, '.' ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' )
			|| preg_match( '/\.(test|local|localhost|invalid|example|internal|lan)$/', $host ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * TXT records for a name, or null when the lookup itself failed.
	 *
	 * @param string $name DNS name.
	 * @return string[]|null
	 */
	private static function txt_records( $name ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() raises a warning when a name has no records, which is an expected answer here.
		$records = @dns_get_record( $name, DNS_TXT );
		if ( ! is_array( $records ) ) {
			return null;
		}
		$out = array();
		foreach ( $records as $record ) {
			if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
				$out[] = trim( implode( '', $record['entries'] ) );
			} elseif ( isset( $record['txt'] ) ) {
				$out[] = trim( (string) $record['txt'] );
			}
		}
		return $out;
	}

	/**
	 * Records that start with a given tag, for example "v=spf1".
	 *
	 * @param string[] $records TXT records.
	 * @param string   $tag     Leading tag.
	 * @return string[]
	 */
	private static function records_with_tag( $records, $tag ) {
		$found = array();
		foreach ( (array) $records as $record ) {
			$record = trim( $record, " \t\"" );
			if ( 0 === stripos( $record, $tag ) ) {
				$rest = substr( $record, strlen( $tag ) );
				if ( '' === $rest || ' ' === $rest[0] || ';' === $rest[0] ) {
					$found[] = $record;
				}
			}
		}
		return $found;
	}

	/**
	 * Whether a name has an address record (or its www name does). Used to tell
	 * "this domain has no TXT records" apart from "DNS lookups return nothing here".
	 *
	 * @param string $domain Domain name.
	 * @return bool
	 */
	private static function resolves( $domain ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return false;
		}
		foreach ( array( $domain, 'www.' . $domain ) as $name ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() raises a warning when a name has no records, which is an expected answer here.
			$records = @dns_get_record( $name, DNS_A | DNS_AAAA | DNS_CNAME );
			if ( is_array( $records ) && ! empty( $records ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Look for a tagged TXT record on a name, then on each parent name down to two labels.
	 *
	 * @param string $domain Domain name.
	 * @param string $prefix Label prefix, such as "_dmarc.".
	 * @param string $tag    Leading tag, such as "v=DMARC1".
	 * @param int    $skip   How many of the most specific names to skip.
	 * @return array{records:string[],name:string}
	 */
	private static function find_tagged_record( $domain, $prefix, $tag, $skip ) {
		$labels = explode( '.', $domain );
		$labels = array_slice( $labels, $skip );
		while ( count( $labels ) >= 2 ) {
			$name    = implode( '.', $labels );
			$records = self::records_with_tag( (array) self::txt_records( $prefix . $name ), $tag );
			if ( ! empty( $records ) ) {
				return array(
					'records' => $records,
					'name'    => $name,
				);
			}
			array_shift( $labels );
		}
		return array(
			'records' => array(),
			'name'    => '',
		);
	}

	/**
	 * Check SPF and DMARC for the site's domain.
	 *
	 * @return array
	 */
	private static function check_email_forgery() {
		$summary_ok = __( 'Your domain tells mail servers which email is really yours', 'mailwick-site-audit' );
		$domain     = self::site_domain();

		if ( '' === $domain ) {
			return self::row(
				__( 'Email forgery protection could not be checked', 'mailwick-site-audit' ),
				array( array( 'unknown', __( 'This site is not on a public domain name (it looks like a local or test address), so there is nothing to look up.', 'mailwick-site-audit' ) ) )
			);
		}

		$root = self::txt_records( $domain );
		if ( null === $root || ( empty( $root ) && ! self::resolves( $domain ) ) ) {
			return self::row(
				__( 'Email forgery protection could not be checked', 'mailwick-site-audit' ),
				array( array( 'unknown', __( 'Your server could not look up DNS records, so SPF and DMARC were not checked. Your host may block DNS lookups from PHP.', 'mailwick-site-audit' ) ) ),
				array(),
				/* translators: %s: domain name. */
				array( sprintf( __( 'DNS lookup failed for %s.', 'mailwick-site-audit' ), $domain ) )
			);
		}

		$findings = array();
		$steps    = array();
		$details  = array();
		$address  = 'you@' . $domain;

		// SPF: the site's own name first, then its parents (a site on shop.example.com usually mails from example.com).
		$spf = self::records_with_tag( $root, 'v=spf1' );
		if ( empty( $spf ) ) {
			$found = self::find_tagged_record( $domain, '', 'v=spf1', 1 );
			$spf   = $found['records'];
		}
		if ( empty( $spf ) ) {
			$findings[] = array(
				'critical',
				/* translators: %s: domain name. */
				sprintf( __( '%s has no SPF record, so mail servers have no list of who is allowed to send email for it.', 'mailwick-site-audit' ), $domain ),
			);
			$steps[]   = __( 'Log in where your domain\'s DNS is managed (usually your domain registrar or web host).', 'mailwick-site-audit' );
			$steps[]   = __( 'Add a TXT record on the root of the domain (often written "@") such as: v=spf1 include:_spf.google.com ~all. Your email provider\'s help pages give the exact "include:" value; list every service that sends mail for you in one record.', 'mailwick-site-audit' );
			$details[] = __( 'SPF: none found.', 'mailwick-site-audit' );
		} elseif ( count( $spf ) > 1 ) {
			$findings[] = array( 'critical', __( 'Your domain has more than one SPF record. Mail servers treat that as an error and ignore all of them.', 'mailwick-site-audit' ) );
			$steps[]    = __( 'In your DNS settings, merge the SPF records into one TXT record that starts with v=spf1 and lists every sending service, then delete the extras.', 'mailwick-site-audit' );
			foreach ( $spf as $record ) {
				/* translators: %s: an SPF record. */
				$details[] = sprintf( __( 'SPF: %s', 'mailwick-site-audit' ), $record );
			}
		} else {
			/* translators: %s: an SPF record. */
			$details[] = sprintf( __( 'SPF: %s', 'mailwick-site-audit' ), $spf[0] );
			if ( preg_match( '/(^|\s)\+?all(\s|$)/i', $spf[0] ) ) {
				$findings[] = array( 'critical', __( 'Your SPF record ends in "+all", which tells mail servers that any server in the world may send as you.', 'mailwick-site-audit' ) );
				$steps[]    = __( 'In your DNS settings, change "+all" at the end of the SPF record to "~all".', 'mailwick-site-audit' );
			} else {
				$findings[] = array( 'good', __( 'SPF record found.', 'mailwick-site-audit' ) );
			}
		}

		// DMARC: the site's own name first, then each parent down to two labels.
		$found       = self::find_tagged_record( $domain, '_dmarc.', 'v=DMARC1', 0 );
		$dmarc       = $found['records'];
		$dmarc_where = $found['name'];

		$forgery_sentence = sprintf(
			/* translators: %s: example email address on the site's domain, such as you@example.com. */
			__( 'Anyone can send email that looks like it came from %s, and receiving mail servers have no instruction to stop it.', 'mailwick-site-audit' ),
			$address
		);
		$dmarc_steps = array(
			__( 'In your DNS settings, add a TXT record named _dmarc with the value: v=DMARC1; p=none; rua=mailto:', 'mailwick-site-audit' ) . $address,
			__( 'Read the reports that arrive over the next 2 to 4 weeks and make sure your real mail (website, newsletter, invoices) passes SPF or DKIM.', 'mailwick-site-audit' ),
			__( 'Then change p=none to p=quarantine, and later to p=reject. From then on, forged mail in your name is sent to spam or refused.', 'mailwick-site-audit' ),
		);

		if ( empty( $dmarc ) ) {
			$findings[] = array( 'critical', $forgery_sentence );
			$steps      = array_merge( $steps, $dmarc_steps );
			$details[]  = __( 'DMARC: none found.', 'mailwick-site-audit' );
		} else {
			$policy = '';
			if ( preg_match( '/(?:^|;)\s*p\s*=\s*([a-z]+)/i', $dmarc[0], $match ) ) {
				$policy = strtolower( $match[1] );
			}
			/* translators: 1: domain the DMARC record was found on, 2: the DMARC record. */
			$details[] = sprintf( __( 'DMARC (on %1$s): %2$s', 'mailwick-site-audit' ), $dmarc_where, $dmarc[0] );
			if ( 'quarantine' === $policy || 'reject' === $policy ) {
				$findings[] = array( 'good', __( 'DMARC record found with a policy that stops forged mail.', 'mailwick-site-audit' ) );
			} else {
				$findings[] = array( 'recommended', $forgery_sentence . ' ' . __( 'Your DMARC policy is "none", which only watches.', 'mailwick-site-audit' ) );
				$steps      = array_merge( $steps, array_slice( $dmarc_steps, 1 ) );
			}
		}

		$worst = self::row( '', $findings );
		if ( 'good' === $worst['status'] ) {
			$summary = $summary_ok;
		} elseif ( 'critical' === $worst['status'] ) {
			/* translators: %s: domain name. */
			$summary = sprintf( __( 'Email from %s can be forged', 'mailwick-site-audit' ), $domain );
		} else {
			/* translators: %s: domain name. */
			$summary = sprintf( __( 'Email from %s is only partly protected from forgery', 'mailwick-site-audit' ), $domain );
		}

		return self::row( $summary, $findings, $steps, $details );
	}

	/* --------------------------------------------------------------------
	 * 2. Padlock and mixed content.
	 * ------------------------------------------------------------------ */

	/**
	 * Insecure (http://) resources the front page loads.
	 *
	 * @param string $html Page HTML.
	 * @return array{active:string[],passive:string[]}
	 */
	public static function find_mixed_content( $html ) {
		$active  = array();
		$passive = array();
		if ( ! preg_match_all( '#<(script|link|iframe|img|source|video|audio|embed|object|input)\b[^>]*>#i', $html, $tags, PREG_SET_ORDER ) ) {
			return array(
				'active'  => $active,
				'passive' => $passive,
			);
		}
		foreach ( $tags as $tag ) {
			$name = strtolower( $tag[1] );
			$text = $tag[0];
			if ( 'link' === $name && ! preg_match( '/\brel\s*=\s*["\']?[^"\'>]*\b(stylesheet|icon|preload|modulepreload)\b/i', $text ) ) {
				continue;
			}
			if ( ! preg_match_all( '/\s(?:src|href|srcset|data|poster)\s*=\s*(["\']?)([^"\'>]*)\1/i', $text, $attrs, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $attrs as $attr ) {
				if ( ! preg_match_all( '#http://[^\s,"\'<>]+#i', $attr[2], $urls ) ) {
					continue;
				}
				foreach ( $urls[0] as $url ) {
					if ( in_array( $name, array( 'script', 'link', 'iframe', 'embed', 'object' ), true ) ) {
						$active[] = $url;
					} else {
						$passive[] = $url;
					}
				}
			}
		}
		return array(
			'active'  => array_values( array_unique( $active ) ),
			'passive' => array_values( array_unique( $passive ) ),
		);
	}

	/**
	 * Check HTTPS, the certificate, the http-to-https redirect and mixed content.
	 *
	 * @param array $page Result of fetch_home().
	 * @return array
	 */
	private static function check_padlock( $page ) {
		$home     = home_url( '/' );
		$findings = array();
		$steps    = array();
		$details  = array();

		if ( 'https' !== wp_parse_url( $home, PHP_URL_SCHEME ) ) {
			$findings[] = array( 'critical', __( 'Your site address starts with http://, so browsers label it "Not secure" and show no padlock.', 'mailwick-site-audit' ) );
			$steps[]    = __( 'Ask your web host to switch on a free SSL certificate (most offer Let\'s Encrypt with one click).', 'mailwick-site-audit' );
			$steps[]    = __( 'Back up the site, then go to Settings > General and change both addresses from http:// to https://.', 'mailwick-site-audit' );
			$steps[]    = __( 'Run these checks again to find any images or scripts that still load over http://.', 'mailwick-site-audit' );
			return self::row( __( 'No padlock: the site is not served over HTTPS', 'mailwick-site-audit' ), $findings, $steps, array( $home ) );
		}

		$site_url = get_option( 'siteurl' );
		if ( is_string( $site_url ) && 0 === stripos( $site_url, 'http://' ) ) {
			$findings[] = array( 'recommended', __( 'Your WordPress address still starts with http://, so the admin area and some files can load without the padlock.', 'mailwick-site-audit' ) );
			$steps[]    = __( 'Back up the site, then go to Settings > General and change the WordPress Address to https://.', 'mailwick-site-audit' );
			/* translators: %s: the WordPress Address setting. */
			$details[] = sprintf( __( 'WordPress Address: %s', 'mailwick-site-audit' ), $site_url );
		}

		if ( $page['ssl_error'] ) {
			$findings[] = array( 'critical', __( 'Your security certificate failed verification, so visitors see a full-page warning instead of your site.', 'mailwick-site-audit' ) );
			$steps[]    = __( 'Ask your web host to renew or reissue the SSL certificate for every name your site uses (with and without www).', 'mailwick-site-audit' );
			$details[]  = $page['error'];
			return self::row( __( 'The padlock is broken: the certificate is not valid', 'mailwick-site-audit' ), $findings, $steps, $details );
		}

		// Does the plain http:// address send visitors to https://? Same site, same host.
		$plain    = set_url_scheme( $home, 'http' );
		$response = wp_remote_get(
			$plain,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 65536,
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( $code >= 200 && $code < 300 ) {
				$findings[] = array( 'recommended', __( 'Visitors who type your address without https:// stay on the insecure version of the site.', 'mailwick-site-audit' ) );
				$steps[]    = __( 'Ask your host to turn on "force HTTPS" (or add a redirect from http:// to https:// in the hosting control panel).', 'mailwick-site-audit' );
				/* translators: %d: HTTP status code. */
				$details[] = sprintf( __( 'http:// version answered %d with no redirect.', 'mailwick-site-audit' ), $code );
			} elseif ( $code >= 300 && $code < 400 && 0 !== stripos( $location, 'https://' ) ) {
				$findings[] = array( 'recommended', __( 'The http:// version of your address redirects somewhere that is not https://.', 'mailwick-site-audit' ) );
				$steps[]    = __( 'Ask your host to make the http:// address redirect straight to the https:// address.', 'mailwick-site-audit' );
				/* translators: %s: redirect target URL. */
				$details[] = sprintf( __( 'http:// redirects to %s', 'mailwick-site-audit' ), $location );
			}
		}

		if ( ! $page['ok'] ) {
			$findings[] = array( 'unknown', __( 'The server could not load your front page, so mixed content was not checked.', 'mailwick-site-audit' ) );
			$details[]  = $page['error'];
		} else {
			$mixed = self::find_mixed_content( $page['html'] );
			if ( ! empty( $mixed['active'] ) ) {
				$findings[] = array( 'critical', __( 'Your front page loads scripts, styles or frames over insecure http://; browsers block them, which can break the page and remove the padlock.', 'mailwick-site-audit' ) );
			}
			if ( ! empty( $mixed['passive'] ) ) {
				$findings[] = array( 'recommended', __( 'Your front page loads images or media over insecure http://, so some browsers drop the padlock or the images.', 'mailwick-site-audit' ) );
			}
			if ( ! empty( $mixed['active'] ) || ! empty( $mixed['passive'] ) ) {
				$steps[] = __( 'Back up the database, then use a free search-and-replace plugin (such as Better Search Replace) to replace your site address written with http:// by the same address written with https://.', 'mailwick-site-audit' );
				$steps[] = __( 'For addresses on other sites, edit the widget, menu or theme setting that contains them and change http:// to https:// (or remove the item if that site has no https).', 'mailwick-site-audit' );
				foreach ( array_slice( array_merge( $mixed['active'], $mixed['passive'] ), 0, 10 ) as $url ) {
					/* translators: %s: URL of an insecure resource. */
					$details[] = sprintf( __( 'Insecure: %s', 'mailwick-site-audit' ), $url );
				}
			}
		}

		if ( empty( $findings ) ) {
			$findings[] = array( 'good', __( 'HTTPS is on, the certificate is valid and the front page loads nothing over insecure http://.', 'mailwick-site-audit' ) );
		}

		$worst = self::row( '', $findings );
		if ( 'good' === $worst['status'] ) {
			$summary = __( 'The padlock shows and nothing on the front page breaks it', 'mailwick-site-audit' );
		} elseif ( 'unknown' === $worst['status'] ) {
			$summary = __( 'HTTPS is on; mixed content could not be checked', 'mailwick-site-audit' );
		} else {
			$summary = __( 'The padlock has problems', 'mailwick-site-audit' );
		}
		return self::row( $summary, $findings, $steps, $details );
	}

	/* --------------------------------------------------------------------
	 * 3. Updates. Reads WordPress's own cached update data only.
	 * ------------------------------------------------------------------ */

	/**
	 * Check core, plugin and theme updates from the stored update transients.
	 *
	 * @return array
	 */
	private static function check_updates() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		if ( false === get_site_transient( 'update_core' ) && false === get_site_transient( 'update_plugins' ) ) {
			return self::row(
				__( 'WordPress has not checked for updates yet', 'mailwick-site-audit' ),
				array( array( 'unknown', __( 'There is no stored update information, so out-of-date software could not be counted.', 'mailwick-site-audit' ) ) ),
				array( __( 'Open Dashboard > Updates once; WordPress then checks and this row fills in on the next run.', 'mailwick-site-audit' ) )
			);
		}

		$findings = array();
		$details  = array();
		$steps    = array();

		$core = get_core_updates();
		if ( is_array( $core ) ) {
			foreach ( $core as $offer ) {
				if ( isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
					$findings[] = array( 'critical', __( 'WordPress itself is out of date. Old versions have publicly known security holes that automated attacks look for.', 'mailwick-site-audit' ) );
					/* translators: 1: installed WordPress version, 2: available version. */
					$details[] = sprintf( __( 'WordPress %1$s, %2$s available.', 'mailwick-site-audit' ), get_bloginfo( 'version' ), $offer->current );
					break;
				}
			}
		}

		$plugins = get_plugin_updates();
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- plugin header fields as returned by get_plugin_updates().
		foreach ( (array) $plugins as $plugin ) {
			if ( isset( $plugin->Name, $plugin->Version, $plugin->update->new_version ) ) {
				/* translators: 1: plugin name, 2: installed version, 3: available version. */
				$details[] = sprintf( __( 'Plugin: %1$s %2$s, %3$s available.', 'mailwick-site-audit' ), $plugin->Name, $plugin->Version, $plugin->update->new_version );
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! empty( $plugins ) ) {
			$findings[] = array(
				'recommended',
				sprintf(
					/* translators: %d: number of plugins. */
					_n( '%d plugin is out of date. Outdated plugins are the most common way WordPress sites get hacked.', '%d plugins are out of date. Outdated plugins are the most common way WordPress sites get hacked.', count( $plugins ), 'mailwick-site-audit' ),
					count( $plugins )
				),
			);
		}

		$themes = get_theme_updates();
		foreach ( (array) $themes as $theme ) {
			if ( $theme instanceof WP_Theme && isset( $theme->update['new_version'] ) ) {
				/* translators: 1: theme name, 2: installed version, 3: available version. */
				$details[] = sprintf( __( 'Theme: %1$s %2$s, %3$s available.', 'mailwick-site-audit' ), $theme->display( 'Name', false ), $theme->get( 'Version' ), $theme->update['new_version'] );
			}
		}
		if ( ! empty( $themes ) ) {
			$findings[] = array(
				'recommended',
				sprintf(
					/* translators: %d: number of themes. */
					_n( '%d theme is out of date.', '%d themes are out of date.', count( $themes ), 'mailwick-site-audit' ),
					count( $themes )
				),
			);
		}

		if ( empty( $findings ) ) {
			return self::row(
				__( 'WordPress, plugins and themes are up to date', 'mailwick-site-audit' ),
				array( array( 'good', __( 'Nothing is waiting to be updated.', 'mailwick-site-audit' ) ) )
			);
		}

		$steps[] = __( 'Make a backup first (many hosts have a one-click backup in the control panel).', 'mailwick-site-audit' );
		$steps[] = __( 'Go to Dashboard > Updates and update WordPress, then plugins and themes, a few at a time.', 'mailwick-site-audit' );
		$steps[] = __( 'Open your front page and contact form after each batch to make sure nothing broke.', 'mailwick-site-audit' );
		$steps[] = __( 'On the Plugins page, turn on "Enable auto-updates" for plugins you trust so this does not pile up again.', 'mailwick-site-audit' );

		return self::row( __( 'Some software is out of date', 'mailwick-site-audit' ), $findings, $steps, $details );
	}

	/* --------------------------------------------------------------------
	 * 4. Phone view.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the HTML has a viewport meta tag.
	 *
	 * @param string $html Page HTML.
	 * @return bool
	 */
	public static function has_viewport( $html ) {
		return (bool) preg_match( '/<meta\b[^>]*\bname\s*=\s*["\']?viewport\b/i', $html );
	}

	/**
	 * Check for a viewport meta tag on the front page.
	 *
	 * @param array $page Result of fetch_home().
	 * @return array
	 */
	private static function check_viewport( $page ) {
		if ( ! $page['ok'] ) {
			return self::page_unavailable( __( 'Phone view could not be checked', 'mailwick-site-audit' ), $page );
		}
		if ( self::has_viewport( $page['html'] ) ) {
			return self::row(
				__( 'The front page is set up for phone screens', 'mailwick-site-audit' ),
				array( array( 'good', __( 'A viewport tag is present, so phones show the page at a readable size.', 'mailwick-site-audit' ) ) )
			);
		}
		return self::row(
			__( 'The front page is not set up for phone screens', 'mailwick-site-audit' ),
			array( array( 'critical', __( 'Phones show your front page as a shrunken desktop page that visitors have to pinch and zoom, and Google ranks such pages lower in mobile search.', 'mailwick-site-audit' ) ) ),
			array(
				__( 'Check whether your theme has an update (Appearance > Themes); most current themes include the tag.', 'mailwick-site-audit' ),
				__( 'If not, switch to a mobile-friendly theme, or ask your theme developer to add this line inside <head>: <meta name="viewport" content="width=device-width, initial-scale=1">.', 'mailwick-site-audit' ),
				__( 'Open the site on your phone to confirm text is readable without zooming.', 'mailwick-site-audit' ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * 5. Tap-to-call.
	 * ------------------------------------------------------------------ */

	/**
	 * Phone numbers shown as text on the page that have no matching tel: link.
	 *
	 * @param string $html Page HTML.
	 * @return array{shown:string[],unlinked:string[]}
	 */
	public static function find_unlinked_phones( $html ) {
		$linked = array();
		if ( preg_match_all( '/href\s*=\s*["\']?\s*tel:([^"\'\s>]+)/i', $html, $tel ) ) {
			foreach ( $tel[1] as $number ) {
				$digits = preg_replace( '/\D+/', '', rawurldecode( $number ) );
				if ( strlen( $digits ) >= 7 ) {
					$linked[] = $digits;
				}
			}
		}

		$body = preg_replace( '#<head\b.*?</head>#is', ' ', $html );
		$body = preg_replace( '#<(script|style|noscript|svg|template|textarea|select)\b[^>]*>.*?</\1>#is', ' ', (string) $body );
		$body = preg_replace( '/<[^>]*>/', ' ', (string) $body );
		$text = html_entity_decode( (string) $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\x87", "\xE2\x80\xAF" ), ' ', $text );

		$pattern = '/(?<![\w+\-.\/])(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]\d{4}(?![\w\-]|\.\d)'
			. '|(?<![\w+])\+\d{1,3}(?:[\s.\-]?\(?\d{1,4}\)?){2,5}(?![\w\-]|\.\d)/';

		$shown    = array();
		$unlinked = array();
		if ( is_string( $text ) && preg_match_all( $pattern, $text, $found ) ) {
			foreach ( $found[0] as $number ) {
				$number = trim( $number );
				$digits = preg_replace( '/\D+/', '', $number );
				if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 || isset( $shown[ $digits ] ) ) {
					continue;
				}
				$shown[ $digits ] = $number;
				$is_linked        = false;
				foreach ( $linked as $link_digits ) {
					$len = min( strlen( $link_digits ), strlen( $digits ), 10 );
					if ( substr( $link_digits, -$len ) === substr( $digits, -$len ) ) {
						$is_linked = true;
						break;
					}
				}
				if ( ! $is_linked ) {
					$unlinked[] = $number;
				}
			}
		}
		return array(
			'shown'    => array_values( $shown ),
			'unlinked' => $unlinked,
		);
	}

	/**
	 * Check that phone numbers on the front page are tappable.
	 *
	 * @param array $page Result of fetch_home().
	 * @return array
	 */
	private static function check_tap_to_call( $page ) {
		if ( ! $page['ok'] ) {
			return self::page_unavailable( __( 'Tap-to-call could not be checked', 'mailwick-site-audit' ), $page );
		}
		$phones = self::find_unlinked_phones( $page['html'] );
		if ( empty( $phones['shown'] ) ) {
			return self::row(
				__( 'No phone numbers on the front page', 'mailwick-site-audit' ),
				array( array( 'good', __( 'No phone numbers were found on the front page, so there is nothing to link.', 'mailwick-site-audit' ) ) )
			);
		}
		if ( empty( $phones['unlinked'] ) ) {
			return self::row(
				__( 'Phone numbers can be tapped to call', 'mailwick-site-audit' ),
				array( array( 'good', __( 'Every phone number on the front page is a tap-to-call link.', 'mailwick-site-audit' ) ) )
			);
		}
		$details = array();
		foreach ( array_slice( $phones['unlinked'], 0, 5 ) as $number ) {
			/* translators: %s: a phone number. */
			$details[] = sprintf( __( 'Not tappable: %s', 'mailwick-site-audit' ), $number );
		}
		return self::row(
			__( 'Phone numbers cannot be tapped to call', 'mailwick-site-audit' ),
			array( array( 'recommended', __( 'Phone numbers on your front page are plain text, so visitors on a phone have to copy them by hand instead of tapping to call you.', 'mailwick-site-audit' ) ) ),
			array(
				__( 'Edit the page, header or footer widget that shows the number.', 'mailwick-site-audit' ),
				__( 'Select the number, click the link button and enter tel: followed by the full number with country code and no spaces, for example tel:+15551234567.', 'mailwick-site-audit' ),
				__( 'Save, then tap the number on your phone to confirm it starts a call.', 'mailwick-site-audit' ),
			),
			$details
		);
	}

	/* --------------------------------------------------------------------
	 * 6. Contact form email.
	 * ------------------------------------------------------------------ */

	/**
	 * Active mail-sending plugins, by name.
	 *
	 * @return string[]
	 */
	public static function active_mail_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active = array();
		foreach ( self::$mail_plugins as $file => $name ) {
			if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
				$active[] = $name;
			}
		}
		return $active;
	}

	/**
	 * Check whether email is sent through a proper mail service.
	 *
	 * @return array
	 */
	private static function check_contact_email() {
		$active = self::active_mail_plugins();
		if ( ! empty( $active ) ) {
			return self::row(
				__( 'Site email goes through a mail service', 'mailwick-site-audit' ),
				array( array( 'good', __( 'A mail-sending plugin is active, so contact form messages are sent through a proper mail service.', 'mailwick-site-audit' ) ) ),
				array(),
				/* translators: %s: comma-separated plugin names. */
				array( sprintf( __( 'Active: %s', 'mailwick-site-audit' ), implode( ', ', $active ) ) )
			);
		}
		if ( has_action( 'phpmailer_init' ) || has_filter( 'pre_wp_mail' ) ) {
			return self::row(
				__( 'Site email has custom sending settings', 'mailwick-site-audit' ),
				array( array( 'good', __( 'Your theme or a plugin changes how WordPress sends email. Use the test button below to confirm messages arrive.', 'mailwick-site-audit' ) ) )
			);
		}
		return self::row(
			__( 'Contact form emails may be landing in spam', 'mailwick-site-audit' ),
			array( array( 'recommended', __( 'Your site sends email (contact forms, orders, password resets) straight from the web server, which spam filters often distrust, so messages can land in spam or never arrive.', 'mailwick-site-audit' ) ) ),
			array(
				__( 'Go to Plugins > Add New and install a free mail plugin such as WP Mail SMTP or FluentSMTP.', 'mailwick-site-audit' ),
				__( 'Connect it to the mailbox you already use (Google Workspace, Microsoft 365 or your host\'s email account) by following its setup wizard.', 'mailwick-site-audit' ),
				__( 'Come back here and use "Send a test email", then check the inbox and the spam folder.', 'mailwick-site-audit' ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * 7. Search snippet.
	 * ------------------------------------------------------------------ */

	/**
	 * The content of the page's meta description, or '' when there is none.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function meta_description( $html ) {
		if ( ! preg_match_all( '/<meta\b[^>]*>/i', $html, $metas ) ) {
			return '';
		}
		foreach ( $metas[0] as $meta ) {
			if ( ! preg_match( '/\bname\s*=\s*(["\']?)description\1(?=[\s\/>])/i', $meta ) ) {
				continue;
			}
			if ( preg_match( '/\bcontent\s*=\s*(["\'])(.*?)\1/is', $meta, $content ) ) {
				return trim( html_entity_decode( $content[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}
		return '';
	}

	/**
	 * Check the front page has a meta description.
	 *
	 * @param array $page Result of fetch_home().
	 * @return array
	 */
	private static function check_meta_description( $page ) {
		if ( ! $page['ok'] ) {
			return self::page_unavailable( __( 'Search snippet could not be checked', 'mailwick-site-audit' ), $page );
		}
		$description = self::meta_description( $page['html'] );
		if ( '' !== $description ) {
			$shown = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 200 ) : substr( $description, 0, 200 );
			return self::row(
				__( 'The front page has a search snippet', 'mailwick-site-audit' ),
				array( array( 'good', __( 'A meta description is set, so you control the text under your link in search results.', 'mailwick-site-audit' ) ) ),
				array(),
				/* translators: %s: the page's meta description. */
				array( sprintf( __( 'Description: %s', 'mailwick-site-audit' ), $shown ) )
			);
		}
		return self::row(
			__( 'The front page has no search snippet', 'mailwick-site-audit' ),
			array( array( 'recommended', __( 'Your front page has no meta description, so Google writes its own snippet for your search listing, often from menu text or a cookie notice.', 'mailwick-site-audit' ) ) ),
			array(
				__( 'Install or open an SEO plugin (Yoast SEO, Rank Math, All in One SEO and The SEO Framework all have free versions).', 'mailwick-site-audit' ),
				__( 'Set the home page description: one or two sentences, under 155 characters, saying what you do and where.', 'mailwick-site-audit' ),
				__( 'Save, clear any page cache, then run these checks again.', 'mailwick-site-audit' ),
			)
		);
	}
}
