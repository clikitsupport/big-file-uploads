<?php
/**
 * Storage Usage Analysis (scan results).
 *
 * @package Big_File_Uploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bfu_iu_cloud = plugins_url( '/assets/img/iu-logo-blue.svg', dirname( __FILE__ ) );
?>
<div class="card bfu-results-card">
	<div class="bfu-results__header"><?php esc_html_e( 'Storage Usage Analysis', 'tuxedo-big-file-uploads' ); ?></div>
	<div class="bfu-results">

		<div class="bfu-results__visual">
			<div class="bfu-results__ring">
				<svg class="bfu-results__ring-track" viewBox="0 0 200 200" fill="none" aria-hidden="true">
					<circle cx="100" cy="100" r="82" fill="#ffffff"/>
					<circle cx="100" cy="100" r="86" stroke="#d7ecf9" stroke-width="10"/>
					<circle cx="100" cy="16" r="4" fill="#b9ddf2"/>
					<circle cx="26" cy="64" r="3" fill="#c6e3f5"/>
					<circle cx="174" cy="64" r="3" fill="#c6e3f5"/>
					<circle cx="40" cy="152" r="3.5" fill="#b9ddf2"/>
					<circle cx="160" cy="152" r="3.5" fill="#b9ddf2"/>
				</svg>
				<div class="bfu-results__ring-inner">
					<span class="bfu-results__ring-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
					</span>
					<span class="bfu-results__total"><?php echo esc_html( size_format( $total_storage, 2 ) ); ?><small> / <?php echo esc_html( number_format_i18n( $total_files ) ); ?></small></span>
					<span class="bfu-results__total-label"><?php esc_html_e( 'Total Bytes / Files', 'tuxedo-big-file-uploads' ); ?></span>
				</div>
			</div>
		</div>

		<div class="bfu-results__main">
			<div class="bfu-results__meta">
				<span class="bfu-results__scanned">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					<?php printf( esc_html__( 'Scanned %s ago', 'tuxedo-big-file-uploads' ), esc_html( human_time_diff( $scan_results['scan_finished'] ) ) ); ?>
				</span>
				<a href="#" class="bfu-results__refresh" data-toggle="modal" data-target="#scan-modal" title="<?php esc_attr_e( 'Run a new scan to detect recently uploaded files.', 'tuxedo-big-file-uploads' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
					<?php esc_html_e( 'Refresh', 'tuxedo-big-file-uploads' ); ?>
				</a>
			</div>

			<div class="bfu-results__breakdown">
				<?php foreach ( $this->get_filetypes( false ) as $ftype ) : ?>
					<?php if ( empty( $ftype->files ) ) { continue; } ?>
					<div class="bfu-results__type">
						<span class="bfu-results__type-dot" style="background-color: <?php echo esc_attr( $ftype->color ); ?>;"></span>
						<span class="bfu-results__type-label"><?php echo esc_html( $ftype->label ); ?></span>
						<span class="bfu-results__type-value"><?php echo esc_html( size_format( $ftype->size, 2 ) ); ?> / <?php echo esc_html( number_format_i18n( $ftype->files ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="bfu-results__upgrade">
				<span class="bfu-results__upgrade-icon" aria-hidden="true">
					<img src="<?php echo esc_url( $bfu_iu_cloud ); ?>" alt="" width="42" height="42" />
				</span>
				<div class="bfu-results__upgrade-text">
					<h4><?php esc_html_e( 'Want unlimited storage space?', 'tuxedo-big-file-uploads' ); ?></h4>
					<p><?php esc_html_e( 'Move your media files to the Infinite Uploads cloud to save storage space, bandwidth, improve performance, and free you from hosting limits.', 'tuxedo-big-file-uploads' ); ?></p>
				</div>
				<button type="button" class="btn text-nowrap btn-primary btn-lg" data-toggle="modal" data-target="#upgrade-modal"><?php esc_html_e( 'More Info', 'tuxedo-big-file-uploads' ); ?></button>
			</div>
		</div>

	</div>
</div>
