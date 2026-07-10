<?php
/**
 * "Analyze Your Storage Usage" scan start panel.
 *
 * @package Big_File_Uploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="card bfu-scan-card">
	<div class="bfu-scan">

		<div class="bfu-scan__visual">
			<div class="bfu-scan__ring">
				<svg class="bfu-scan__ring-track" viewBox="0 0 160 160" fill="none" aria-hidden="true">
					<circle cx="80" cy="80" r="66" stroke="#cfe8f8" stroke-width="13"/>
					<circle cx="80" cy="80" r="66" stroke="#26a9e0" stroke-width="13" stroke-linecap="round" stroke-dasharray="414.7" stroke-dashoffset="135" transform="rotate(-90 80 80)"/>
					<circle cx="80" cy="146" r="4" fill="#26a9e0"/>
					<circle cx="20" cy="52" r="3" fill="#a6d6f0"/>
					<circle cx="142" cy="60" r="3" fill="#a6d6f0"/>
				</svg>
				<span class="bfu-scan__ring-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
						<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
						<polyline points="9.5 13.5 11 15 14.5 11.25"/>
					</svg>
				</span>
			</div>
			<p class="bfu-scan__steps">
				<?php esc_html_e( 'Scan', 'tuxedo-big-file-uploads' ); ?> &bull;
				<?php esc_html_e( 'Analyze', 'tuxedo-big-file-uploads' ); ?> &bull;
				<?php esc_html_e( 'Report', 'tuxedo-big-file-uploads' ); ?>
			</p>
		</div>

		<div class="bfu-scan__main">
			<h2 class="bfu-scan__title"><?php esc_html_e( 'Analyze Your Storage Usage', 'tuxedo-big-file-uploads' ); ?></h2>
			<p class="bfu-scan__lead"><?php esc_html_e( 'Run a free scan of your existing Media Library and get your report in seconds.', 'tuxedo-big-file-uploads' ); ?></p>

			<button type="button" class="btn text-nowrap btn-primary btn-lg" data-toggle="modal" data-target="#scan-modal"><?php esc_html_e( 'Run Free Scan', 'tuxedo-big-file-uploads' ); ?></button>
		</div>

		<div class="bfu-scan__features">
			<div class="bfu-scan__feature">
				<span class="bfu-scan__feature-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
				</span>
				<span class="bfu-scan__feature-text">
					<strong><?php esc_html_e( 'Lightning Fast', 'tuxedo-big-file-uploads' ); ?></strong>
					<span><?php esc_html_e( 'Get your storage usage report in just a few seconds.', 'tuxedo-big-file-uploads' ); ?></span>
				</span>
			</div>

			<div class="bfu-scan__feature">
				<span class="bfu-scan__feature-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
				</span>
				<span class="bfu-scan__feature-text">
					<strong><?php esc_html_e( 'Detailed Insights', 'tuxedo-big-file-uploads' ); ?></strong>
					<span><?php esc_html_e( 'See how your storage is used across different file types.', 'tuxedo-big-file-uploads' ); ?></span>
				</span>
			</div>

			<div class="bfu-scan__feature">
				<span class="bfu-scan__feature-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				</span>
				<span class="bfu-scan__feature-text">
					<strong><?php esc_html_e( 'Private &amp; Secure', 'tuxedo-big-file-uploads' ); ?></strong>
					<span><?php esc_html_e( 'We analyze your data locally. Your privacy is our priority.', 'tuxedo-big-file-uploads' ); ?></span>
				</span>
			</div>
		</div>

	</div>
</div>
