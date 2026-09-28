<?php
/**
 * Popup for Scan files: the "Personalize Your Report" questions, then the scan in progress.
 *
 * The questions are only asked while the subscribe modal will follow the scan ($dismissed is set by
 * settings_page()), because their answers are sent with that form and nowhere else.
 *
 * @package Big_File_Uploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="modal fade" id="scan-modal" tabindex="-1" role="dialog" aria-labelledby="<?php echo $dismissed ? 'scan-modal-label' : 'bfu-report-label'; ?>" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content bfu-scanning">
			<button type="button" class="bfu-scanning__close" data-dismiss="modal" aria-label="<?php esc_attr_e( 'Close', 'tuxedo-big-file-uploads' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
			</button>
			<div class="modal-body">
				<?php if ( ! $dismissed ) : ?>
					<div class="bfu-report" id="bfu-report-questions">
						<h4 class="bfu-scanning__title" id="bfu-report-label"><?php esc_html_e( 'Personalize Your Report', 'tuxedo-big-file-uploads' ); ?></h4>
						<p class="bfu-scanning__lead"><?php esc_html_e( 'Answer three quick questions so we can tailor the results and tips we send you.', 'tuxedo-big-file-uploads' ); ?></p>

						<?php foreach ( $this->get_report_questions() as $bfu_question_key => $bfu_question ) : ?>
							<fieldset class="bfu-report__question" data-merge-tag="<?php echo esc_attr( $bfu_question['merge_tag'] ); ?>">
								<legend class="bfu-report__label"><?php echo esc_html( $bfu_question['label'] ); ?></legend>
								<div class="bfu-report__choices">
									<?php foreach ( $bfu_question['choices'] as $bfu_choice_value => $bfu_choice_label ) : ?>
										<?php $bfu_choice_id = 'bfu-report-' . $bfu_question_key . '-' . $bfu_choice_value; ?>
										<input type="radio" class="bfu-report__input" id="<?php echo esc_attr( $bfu_choice_id ); ?>" name="<?php echo esc_attr( 'bfu_report_' . $bfu_question_key ); ?>" value="<?php echo esc_attr( $bfu_choice_value ); ?>">
										<label class="bfu-report__choice" for="<?php echo esc_attr( $bfu_choice_id ); ?>"><?php echo esc_html( $bfu_choice_label ); ?></label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endforeach; ?>

						<button type="button" class="btn text-nowrap btn-primary btn-lg" id="bfu-report-start" disabled><?php esc_html_e( 'Start Scan', 'tuxedo-big-file-uploads' ); ?></button>
					</div>
				<?php endif; ?>

				<div id="bfu-scan-running"<?php echo $dismissed ? '' : ' hidden'; ?>>
					<div class="bfu-scanning__spinner" aria-hidden="true"></div>
					<h4 class="bfu-scanning__title" id="scan-modal-label"><?php esc_html_e( 'Scanning Media Library', 'tuxedo-big-file-uploads' ); ?></h4>
					<p class="bfu-scanning__lead"><?php esc_html_e( 'This usually only takes a minute or two but can take longer for very large media libraries with a lot of files. Please leave this tab open while we complete your scan.', 'tuxedo-big-file-uploads' ); ?></p>
					<p class="bfu-scanning__progress">
						<span id="bfu-scan-progress">
							<?php
							printf(
							// translators: %1$s is the opening span tag for storage
							// translators: %2$s is the closing span tag for storage
							// translators: %3$s is the opening span tag for files
							// translators: %4$s is the closing span tag for files.
								esc_html__( 'Found %1$s0 MB%2$s / %3$s0%4$s Files...', 'tuxedo-big-file-uploads' ),
								'<span id="bfu-scan-storage">',
								'</span>',
								'<span id="bfu-scan-files">',
								'</span>'
							);
							?>
						</span>
					</p>
				</div>
			</div>
		</div>
	</div>
</div>
