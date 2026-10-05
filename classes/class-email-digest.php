<?php
/**
 * Email summary: a periodic upload report the site emails to its own admin.
 *
 * Every number comes from data the site already has (the Media Library, the last storage
 * scan). Nothing is sent anywhere except the admin's inbox.
 *
 * Built to stay out of the way on a large install base: one scheduled event that exists only
 * while the summary is on, one small non-autoloaded option for the comparison, and no filesystem
 * walks. Single sites only; a network's uploads live in per-site tables, which a single admin
 * email cannot summarize cheaply.
 *
 * @package BigFileUploads
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Big_File_Uploads_Email_Digest
 */
class Big_File_Uploads_Email_Digest {

	/**
	 * The scheduled event. A single event that reschedules itself, so the send lands on a
	 * calendar boundary (the 1st, a Monday, midnight) rather than drifting with each run.
	 */
	const HOOK = 'bfu_send_email_digest';

	/**
	 * Per-period totals, newest last, for the comparison with the previous period. Never autoloaded.
	 */
	const HISTORY_OPTION = 'tuxbfu_digest_history';

	/**
	 * Periods kept in the history: the one just reported, ready to be next time's "previous".
	 */
	const HISTORY_LENGTH = 2;

	const DEFAULT_FREQUENCY = 'monthly';

	/**
	 * Local hour the summary is sent at.
	 */
	const SEND_HOUR = 9;

	/**
	 * Most attachments read per period. Counts stay exact past this; sizes become a floor.
	 */
	const ROW_LIMIT = 5000;

	/**
	 * @var BigFileUploads
	 */
	protected $plugin;

	/**
	 * @param BigFileUploads $plugin
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		if ( is_multisite() ) {
			return;
		}

		add_action( self::HOOK, array( $this, 'send_scheduled' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Selectable frequencies, in the order the setting lists them.
	 *
	 * @return array key => label
	 */
	public function get_frequencies() {
		return array(
			'monthly' => __( 'Monthly', 'tuxedo-big-file-uploads' ),
			'weekly'  => __( 'Weekly', 'tuxedo-big-file-uploads' ),
			'daily'   => __( 'Daily', 'tuxedo-big-file-uploads' ),
			'off'     => __( 'Off', 'tuxedo-big-file-uploads' ),
		);
	}

	/**
	 * Whether the summary is offered on this install at all.
	 *
	 * @return bool
	 */
	public function is_available() {
		return ! is_multisite();
	}

	/**
	 * The saved frequency, falling back to the default for installs that never chose one.
	 *
	 * @return string
	 */
	public function get_frequency() {
		if ( ! $this->is_available() ) {
			return 'off';
		}

		$settings = get_site_option( 'tuxbfu_settings' );

		return $this->sanitize_frequency( isset( $settings['digest'] ) ? $settings['digest'] : self::DEFAULT_FREQUENCY );
	}

	/**
	 * @param mixed $frequency
	 *
	 * @return string A known frequency key.
	 */
	public function sanitize_frequency( $frequency ) {
		$frequencies = $this->get_frequencies();

		return ( is_string( $frequency ) && isset( $frequencies[ $frequency ] ) ) ? $frequency : self::DEFAULT_FREQUENCY;
	}

	/**
	 * Who receives the summary.
	 *
	 * @return string[]
	 */
	public function get_recipients() {
		/**
		 * Filters who receives the Big File Uploads email summary.
		 *
		 * @param string|string[] $recipients Default the site admin email.
		 *
		 * @since 2.3.0
		 */
		$recipients = apply_filters( 'bfu_email_digest_recipients', get_option( 'admin_email' ) );

		return array_values( array_filter( array_map( 'sanitize_email', (array) $recipients ), 'is_email' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scheduling
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Keep the event in step with the setting: there while the summary is on, gone while off.
	 *
	 * Runs on admin_init, which is also how existing installs pick the summary up after an
	 * update, since updates do not fire the activation hook. Reads only the cron array,
	 * which WordPress already has in memory.
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( wp_doing_ajax() || ! $this->is_available() ) {
			return;
		}

		$next = wp_next_scheduled( self::HOOK );

		if ( 'off' === $this->get_frequency() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}

			return;
		}

		if ( ! $next ) {
			wp_schedule_single_event( $this->get_next_send( $this->get_frequency() ), self::HOOK );
		}
	}

	/**
	 * Drop the pending send and schedule from the current setting, after it changes.
	 *
	 * @return void
	 */
	public function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$this->maybe_schedule();
	}

	/**
	 * When the next summary goes out, for the settings page.
	 *
	 * @return string|null Site-time date and time, or null while the summary is off.
	 */
	public function get_next_send_label() {
		$frequency = $this->get_frequency();
		if ( 'off' === $frequency ) {
			return null;
		}

		$next = wp_next_scheduled( self::HOOK );
		if ( ! $next ) {
			$next = $this->get_next_send( $frequency );
		}

		/* translators: 1: date, 2: time */
		return sprintf( __( '%1$s at %2$s', 'tuxedo-big-file-uploads' ), wp_date( get_option( 'date_format' ), $next ), wp_date( get_option( 'time_format' ), $next ) );
	}

	/**
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * When the next summary goes out: the start of the next period, at SEND_HOUR local time.
	 *
	 * @param string                  $frequency
	 * @param DateTimeImmutable|null  $now
	 *
	 * @return int Unix timestamp.
	 */
	public function get_next_send( $frequency, $now = null ) {
		$today = $this->local_now( $now )->setTime( 0, 0 );

		switch ( $frequency ) {
			case 'daily':
				$next = $today->modify( '+1 day' );
				break;
			case 'weekly':
				$next = $today->modify( 'next monday' );
				break;
			default:
				$next = $today->modify( 'first day of next month' );
		}

		return $next->setTime( self::SEND_HOUR, 0 )->getTimestamp();
	}

	/**
	 * The period a summary sent now reports on: the last complete day, week or month.
	 *
	 * @param string                  $frequency
	 * @param DateTimeImmutable|null  $now
	 *
	 * @return DateTimeImmutable[] 'start' (inclusive) and 'end' (exclusive), site time.
	 */
	public function get_period( $frequency, $now = null ) {
		$today = $this->local_now( $now )->setTime( 0, 0 );

		switch ( $frequency ) {
			case 'daily':
				$end   = $today;
				$start = $end->modify( '-1 day' );
				break;
			case 'weekly':
				$end   = $today->modify( 'monday this week' );
				$start = $end->modify( '-1 week' );
				break;
			default:
				$end   = $today->modify( 'first day of this month' );
				$start = $end->modify( 'first day of last month' );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * @param DateTimeImmutable|null $now
	 *
	 * @return DateTimeImmutable
	 */
	protected function local_now( $now = null ) {
		$timezone = wp_timezone();

		return $now ? $now->setTimezone( $timezone ) : new DateTimeImmutable( 'now', $timezone );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sending
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The scheduled run: report on the period that just ended, keep its totals for the next comparison,
	 * send it if anything was uploaded, and queue the next one.
	 *
	 * @return void
	 */
	public function send_scheduled() {
		$frequency = $this->get_frequency();
		if ( 'off' === $frequency ) {
			return;
		}

		$report = $this->build_report( $frequency );
		$this->record_period( $report );

		// An empty summary is noise, and noise is what gets the whole thing switched off.
		if ( $report['uploads']['count'] > 0 ) {
			$this->send( $report );
		}

		$this->maybe_schedule();
	}

	/**
	 * @param array $report From build_report().
	 *
	 * @return bool Whether wp_mail() accepted it.
	 */
	public function send( $report ) {
		$recipients = $this->get_recipients();
		if ( ! $recipients ) {
			return false;
		}

		return wp_mail(
			$recipients,
			$this->get_subject( $report ),
			$this->render( $report ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * @param array $report
	 *
	 * @return string
	 */
	public function get_subject( $report ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( $report['uploads']['count'] > 0 ) {
			/* translators: 1: site name, 2: period name such as "September 2026", 3: number of files, 4: total size such as "1.2 GB" */
			$subject = _n( '[%1$s] %2$s uploads: %3$s file, %4$s', '[%1$s] %2$s uploads: %3$s files, %4$s', $report['uploads']['count'], 'tuxedo-big-file-uploads' );

			return sprintf( $subject, $site, $report['period']['label'], number_format_i18n( $report['uploads']['count'] ), size_format( $report['uploads']['bytes'], 1 ) );
		}

		/* translators: 1: site name, 2: period name such as "September 2026" */
		return sprintf( __( '[%1$s] %2$s upload summary', 'tuxedo-big-file-uploads' ), $site, $report['period']['label'] );
	}

	/**
	 * @param array $report
	 *
	 * @return string HTML email body.
	 */
	public function render( $report ) {
		$digest = $this;

		ob_start();
		require dirname( dirname( __FILE__ ) ) . '/templates/email-digest.php';

		return ob_get_clean();
	}

	/*
	 * ---------------------------------------------------------------------
	 * The report
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Everything the email shows, for the period that just ended.
	 *
	 * @param string                  $frequency
	 * @param DateTimeImmutable|null  $now
	 *
	 * @return array
	 */
	public function build_report( $frequency, $now = null ) {
		$period  = $this->get_period( $frequency, $now );
		$uploads = $this->get_period_uploads( $period['start'], $period['end'] );
		$history = $this->get_history( $frequency );

		// Only the period directly before counts as "previous"; after a gap there is none.
		$previous_start = $this->get_period( $frequency, $period['start'] )['start']->getTimestamp();
		$previous       = null;
		foreach ( $history as $entry ) {
			if ( $entry['start'] === $previous_start ) {
				$previous = $entry;
			}
		}

		return array(
			'frequency'    => $frequency,
			'period'       => array(
				'start' => $period['start'],
				'end'   => $period['end'],
				'label' => $this->get_period_label( $frequency, $period['start'] ),
			),
			'uploads'      => $uploads,
			'change'       => $this->get_change( $uploads['bytes'], $previous ),
			'change_files' => $this->get_change( $uploads['count'], $previous, 'files' ),
			'library'      => $this->get_library(),
			'first'        => ! $this->get_history(),
			'iu_active'    => $this->plugin->is_infinite_uploads_active(),
			'manage_url'   => $this->plugin->settings_url() . '#bfu-email-summary',
		);
	}

	/**
	 * Attachments added during the period, by file type, with the largest few.
	 *
	 * Reads the database, never the disk: sizes come from the attachment metadata WordPress
	 * has stored since 6.0, and only uploads without one are measured on disk.
	 *
	 * @param DateTimeImmutable $start
	 * @param DateTimeImmutable $end
	 *
	 * @return array count, bytes, types (type => count/bytes), largest, partial
	 */
	public function get_period_uploads( $start, $end ) {
		global $wpdb;

		$where = $wpdb->prepare(
			"p.post_type = 'attachment' AND p.post_status IN ('inherit','private') AND p.post_date >= %s AND p.post_date < %s",
			$start->format( 'Y-m-d H:i:s' ),
			$end->format( 'Y-m-d H:i:s' )
		);

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" );

		$result = array(
			'count'   => $count,
			'bytes'   => 0,
			'types'   => array(),
			'largest' => array(),
			'partial' => $count > self::ROW_LIMIT,
		);

		if ( ! $count ) {
			return $result;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, file.meta_value AS file, meta.meta_value AS meta
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} file ON file.post_id = p.ID AND file.meta_key = '_wp_attached_file'
				LEFT JOIN {$wpdb->postmeta} meta ON meta.post_id = p.ID AND meta.meta_key = '_wp_attachment_metadata'
				WHERE {$where}
				ORDER BY p.ID DESC
				LIMIT %d",
				self::ROW_LIMIT
			)
		);

		$uploads = wp_get_upload_dir();

		foreach ( $rows as $row ) {
			$bytes = $this->get_attachment_size( $row, $uploads['basedir'] );
			$type  = $this->plugin->get_file_type( (string) $row->file );

			if ( ! isset( $result['types'][ $type ] ) ) {
				$result['types'][ $type ] = array( 'count' => 0, 'bytes' => 0 );
			}
			$result['types'][ $type ]['count']++;
			$result['types'][ $type ]['bytes'] += $bytes;
			$result['bytes']                   += $bytes;

			$result['largest'][] = array(
				'id'    => (int) $row->ID,
				'title' => '' !== $row->post_title ? $row->post_title : wp_basename( (string) $row->file ),
				'bytes' => $bytes,
				'type'  => $type,
			);
		}

		uasort( $result['types'], array( $this, 'compare_bytes' ) );
		usort( $result['largest'], array( $this, 'compare_bytes' ) );
		$result['largest'] = array_slice( array_filter( $result['largest'], array( $this, 'has_bytes' ) ), 0, 3 );

		return $result;
	}

	/**
	 * @param object $row     id, file and serialized metadata.
	 * @param string $basedir Uploads base directory.
	 *
	 * @return int
	 */
	protected function get_attachment_size( $row, $basedir ) {
		$meta = maybe_unserialize( $row->meta );
		if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
			return (int) $meta['filesize'];
		}

		if ( $row->file ) {
			$path = path_join( $basedir, $row->file );
			if ( @is_file( $path ) ) {
				return (int) @filesize( $path );
			}
		}

		return 0;
	}

	/**
	 * @param array $a
	 * @param array $b
	 *
	 * @return int Larger first.
	 */
	public function compare_bytes( $a, $b ) {
		if ( $a['bytes'] === $b['bytes'] ) {
			return 0;
		}

		return ( $a['bytes'] < $b['bytes'] ) ? 1 : -1;
	}

	/**
	 * @param array $item
	 *
	 * @return bool
	 */
	public function has_bytes( $item ) {
		return $item['bytes'] > 0;
	}

	/**
	 * How one of this period's totals compares with the period before it.
	 *
	 * @param int        $current
	 * @param array|null $previous History entry for the previous period.
	 * @param string     $key      'bytes' or 'files'.
	 *
	 * @return array|null previous and percent (percent is null when the previous period was empty).
	 */
	public function get_change( $current, $previous, $key = 'bytes' ) {
		if ( ! $previous ) {
			return null;
		}

		$before = (int) $previous[ $key ];

		return array(
			'previous' => $before,
			'percent'  => $before > 0 ? (int) round( ( $current - $before ) / $before * 100 ) : null,
		);
	}

	/**
	 * Totals from the last storage scan, if one has finished, and how many uploads it predates.
	 * The summary never runs a scan, so an old total is labelled as old rather than passed off
	 * as current.
	 *
	 * @return array|null bytes, files, scanned (timestamp), added_since (uploads after the scan)
	 */
	public function get_library() {
		global $wpdb;

		$scan = get_site_option( 'tuxbfu_file_scan' );
		if ( empty( $scan['scan_finished'] ) || empty( $scan['types'] ) ) {
			return null;
		}

		$scanned = (int) $scan['scan_finished'];

		return array(
			'bytes'       => array_sum( wp_list_pluck( $scan['types'], 'size' ) ),
			'files'       => array_sum( wp_list_pluck( $scan['types'], 'files' ) ),
			'scanned'     => $scanned,
			'added_since' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit','private') AND post_date_gmt > %s",
					gmdate( 'Y-m-d H:i:s', $scanned )
				)
			),
		);
	}

	/**
	 * @param string            $frequency
	 * @param DateTimeImmutable $start
	 *
	 * @return string
	 */
	public function get_period_label( $frequency, $start ) {
		$timestamp = $start->getTimestamp();

		switch ( $frequency ) {
			case 'daily':
				return wp_date( get_option( 'date_format' ), $timestamp );
			case 'weekly':
				/* translators: %s: date the week starts, such as "Sep 21" */
				return sprintf( __( 'Week of %s', 'tuxedo-big-file-uploads' ), wp_date( 'M j', $timestamp ) );
			default:
				return wp_date( 'F Y', $timestamp );
		}
	}

	/**
	 * A size for the email: one decimal, except a plain "0 B" for nothing.
	 *
	 * @param int|float $bytes
	 *
	 * @return string
	 */
	public function format_bytes( $bytes ) {
		return $bytes > 0 ? size_format( $bytes, 1 ) : size_format( 0 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * History
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Stored periods, oldest first, optionally only those of one frequency.
	 *
	 * @param string|null $frequency
	 *
	 * @return array[] start, frequency, files, bytes
	 */
	public function get_history( $frequency = null ) {
		$history = get_option( self::HISTORY_OPTION );
		if ( ! is_array( $history ) ) {
			return array();
		}

		$entries = array();
		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['start'], $entry['frequency'], $entry['files'], $entry['bytes'] ) ) {
				continue;
			}
			if ( null === $frequency || $entry['frequency'] === $frequency ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Keep a period's totals. Replaces an existing entry for the same period, and only the
	 * newest HISTORY_LENGTH survive.
	 *
	 * @param array $report From build_report().
	 *
	 * @return void
	 */
	public function record_period( $report ) {
		$start   = $report['period']['start']->getTimestamp();
		$history = array();

		foreach ( $this->get_history() as $entry ) {
			if ( ! ( $entry['start'] === $start && $entry['frequency'] === $report['frequency'] ) ) {
				$history[] = $entry;
			}
		}

		$history[] = array(
			'start'     => $start,
			'frequency' => $report['frequency'],
			'files'     => (int) $report['uploads']['count'],
			'bytes'     => (int) $report['uploads']['bytes'],
		);

		usort( $history, array( $this, 'compare_start' ) );

		update_option( self::HISTORY_OPTION, array_slice( $history, - self::HISTORY_LENGTH ), false );
	}

	/**
	 * @param array $a
	 * @param array $b
	 *
	 * @return int Oldest first.
	 */
	public function compare_start( $a, $b ) {
		if ( $a['start'] === $b['start'] ) {
			return 0;
		}

		return ( $a['start'] < $b['start'] ) ? -1 : 1;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Email content helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A link to infiniteuploads.com tagged as coming from this email.
	 *
	 * @param string $path
	 * @param string $content utm_content value.
	 *
	 * @return string
	 */
	public function link( $path, $content ) {
		return add_query_arg(
			array(
				'utm_source'   => 'bfu_plugin',
				'utm_medium'   => 'email',
				'utm_campaign' => 'bfu_digest',
				'utm_content'  => $content,
			),
			$this->plugin->api_url( $path )
		);
	}

	/**
	 * One tip per summary, rotating by period so consecutive emails differ.
	 *
	 * @param array $report
	 *
	 * @return array text, link_label, url
	 */
	public function get_tip( $report ) {
		$tips = array(
			array(
				'text'       => __( 'Give each user role its own maximum upload size, so authors and editors can each have the limit they need.', 'tuxedo-big-file-uploads' ),
				'link_label' => __( 'Set limits by role', 'tuxedo-big-file-uploads' ),
				'url'        => $this->plugin->settings_url(),
			),
			array(
				'text'       => __( 'Set separate limits for images, video, audio and documents, so large video is allowed without opening the door to oversized images.', 'tuxedo-big-file-uploads' ),
				'link_label' => __( 'Set limits by file type', 'tuxedo-big-file-uploads' ),
				'url'        => $this->plugin->settings_url(),
			),
			array(
				'text'       => __( 'Run a storage scan to see how your whole Media Library breaks down by file type, not just what was uploaded recently.', 'tuxedo-big-file-uploads' ),
				'link_label' => __( 'Scan your storage', 'tuxedo-big-file-uploads' ),
				'url'        => $this->plugin->settings_url(),
			),
		);

		$index = (int) floor( $report['period']['start']->getTimestamp() / DAY_IN_SECONDS ) % count( $tips );

		return $tips[ $index ];
	}
}
