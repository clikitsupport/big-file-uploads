<?php
/**
 * Settings card (maximum upload size).
 *
 * @package Big_File_Uploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bfu_logo    = plugins_url( '/assets/img/bfu-logo-sm.png', dirname( __FILE__ ) );
$bfu_default = size_format( $this->max_upload_size );
?>
<div class="card bfu-settings-card">
	<form action="<?php echo esc_url( $this->settings_url() ); ?>" method="post">
		<?php wp_nonce_field( 'bfu_settings' ); ?>

		<div class="bfu-settings__header">
			<span class="bfu-settings__logo" aria-hidden="true">
				<img src="<?php echo esc_url( $bfu_logo ); ?>" alt="" width="22" height="30" />
			</span>
			<h2 class="bfu-settings__title"><?php esc_html_e( 'Settings', 'tuxedo-big-file-uploads' ); ?></h2>
		</div>

		<div class="bfu-settings__body">

			<div class="bfu-settings__guide">
				<h3 class="bfu-settings__guide-title"><?php esc_html_e( 'Set Maximum Upload Size', 'tuxedo-big-file-uploads' ); ?></h3>
				<ol class="bfu-steps">
					<li class="bfu-step">
						<span class="bfu-step__num" aria-hidden="true">1</span>
						<span class="bfu-step__text">
							<span class="bfu-step__title"><?php esc_html_e( 'Choose the maximum file size', 'tuxedo-big-file-uploads' ); ?></span>
							<span class="bfu-step__desc"><?php esc_html_e( 'Set the size limit users can upload in MB or GB.', 'tuxedo-big-file-uploads' ); ?></span>
						</span>
					</li>
					<li class="bfu-step">
						<span class="bfu-step__num" aria-hidden="true">2</span>
						<span class="bfu-step__text">
							<span class="bfu-step__title"><?php esc_html_e( 'Customize by role or file type (optional)', 'tuxedo-big-file-uploads' ); ?></span>
							<span class="bfu-step__desc"><?php esc_html_e( 'Use the toggles to set different limits per user role or per file type.', 'tuxedo-big-file-uploads' ); ?></span>
						</span>
					</li>
					<li class="bfu-step">
						<span class="bfu-step__num" aria-hidden="true">3</span>
						<span class="bfu-step__text">
							<span class="bfu-step__title"><?php esc_html_e( 'Click Save', 'tuxedo-big-file-uploads' ); ?></span>
							<span class="bfu-step__desc"><?php esc_html_e( 'Your settings will be applied to all uploads.', 'tuxedo-big-file-uploads' ); ?></span>
						</span>
					</li>
				</ol>
				<p class="bfu-settings__hint"><?php printf( esc_html__( 'Big File Uploads bypasses your hosting limit of %s by uploading in smaller chunks.', 'tuxedo-big-file-uploads' ), esc_html( $bfu_default ) ); ?></p>
			</div>

			<div class="bfu-settings__panel">
				<div class="bfu-panel__head">
					<h3 class="bfu-panel__title"><?php esc_html_e( 'Maximum Upload Size', 'tuxedo-big-file-uploads' ); ?></h3>
					<span class="bfu-panel__toggles">
						<span class="bfu-panel__toggle">
							<label class="bfu-switch" for="customSwitch_role">
								<input type="checkbox" name="by_role" class="bfu-switch__input" id="customSwitch_role" value="1" <?php checked( $settings['by_role'] ); ?>>
								<span class="bfu-switch__track" aria-hidden="true"><span class="bfu-switch__thumb"></span></span>
								<span class="bfu-switch__label"><?php esc_html_e( 'Customize by user role', 'tuxedo-big-file-uploads' ); ?></span>
							</label>
							<span class="bfu-info dashicons dashicons-info-outline" data-toggle="tooltip" title="<?php esc_attr_e( 'Set a different maximum upload size for each user role that can upload files.', 'tuxedo-big-file-uploads' ); ?>"></span>
						</span>
						<span class="bfu-panel__toggle">
							<label class="bfu-switch" for="customSwitch_type">
								<input type="checkbox" name="by_type" class="bfu-switch__input" id="customSwitch_type" value="1" <?php checked( $settings['by_type'] ); ?>>
								<span class="bfu-switch__track" aria-hidden="true"><span class="bfu-switch__thumb"></span></span>
								<span class="bfu-switch__label"><?php esc_html_e( 'Customize by file type', 'tuxedo-big-file-uploads' ); ?></span>
							</label>
							<span class="bfu-info dashicons dashicons-info-outline" data-toggle="tooltip" title="<?php esc_attr_e( 'Give images, audio, video and other file types their own limit. Anything left blank uses the limit above it.', 'tuxedo-big-file-uploads' ); ?>"></span>
						</span>
					</span>
				</div>

				<div class="bfu-panel__body <?php echo $settings['by_role'] ? 'bfu-disabled' : ''; ?>" id="bfu-settings">
					<div class="bfu-limit">
						<div class="bfu-limit__head">
							<span class="bfu-limit__role"><?php esc_html_e( 'Default for all users', 'tuxedo-big-file-uploads' ); ?></span>
							<span class="bfu-limit__badge" data-toggle="tooltip" title="<?php esc_attr_e( 'Default size is defined by your hosting provider', 'tuxedo-big-file-uploads' ); ?>"><?php printf( esc_html__( 'Host limit: %s', 'tuxedo-big-file-uploads' ), esc_html( $bfu_default ) ); ?></span>
						</div>
						<div class="input-group bfu-input-limit">
							<input name="upload_limit" id="upload-limit" type="number" step="0.1" min="0" value="<?php echo esc_attr( $settings['limits']['all']['bytes'] ); ?>" class="form-control bfu-limit__input"
							       aria-label="<?php esc_attr_e( 'All users upload limit', 'tuxedo-big-file-uploads' ); ?>">
							<div class="input-group-append bfu-limit__unit">
								<select name="upload_limit_format" id="upload-limit-format">
									<option <?php selected( $settings['limits']['all']['format'], 'MB' ); ?> value="MB">MB</option>
									<option <?php selected( $settings['limits']['all']['format'], 'GB' ); ?> value="GB">GB</option>
								</select>
							</div>
						</div>
						<?php $this->render_type_limits( 'all', $settings ); ?>
					</div>
				</div>

				<div class="bfu-panel__body <?php echo $settings['by_role'] ? '' : 'bfu-disabled'; ?>" id="bfu-settings-roles">
					<?php
					foreach ( wp_roles()->roles as $role_key => $role ) {
						if ( isset( $role['capabilities']['upload_files'] ) && $role['capabilities']['upload_files'] ) {
							?>
							<div class="bfu-limit">
								<div class="bfu-limit__head">
									<span class="bfu-limit__role"><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></span>
									<span class="bfu-limit__badge" data-toggle="tooltip" title="<?php esc_attr_e( 'Default size is defined by your hosting provider', 'tuxedo-big-file-uploads' ); ?>"><?php printf( esc_html__( 'Host limit: %s', 'tuxedo-big-file-uploads' ), esc_html( $bfu_default ) ); ?></span>
								</div>
								<div class="input-group bfu-input-limit">
									<input name="upload_limit[<?php echo esc_attr( $role_key ); ?>]" id="upload-limit-<?php echo esc_attr( $role_key ); ?>" type="number" step="0.1" min="0" value="<?php echo esc_attr( $settings['limits'][ $role_key ]['bytes'] ); ?>"
									       class="form-control bfu-limit__input" aria-label="<?php printf( esc_attr__( '%s upload limit', 'tuxedo-big-file-uploads' ), translate_user_role( $role['name'] ) ); ?>">
									<div class="input-group-append bfu-limit__unit">
										<select name="upload_limit_format[<?php echo esc_attr( $role_key ); ?>]" id="upload-limit-format-<?php echo esc_attr( $role_key ); ?>">
											<option <?php selected( $settings['limits'][ $role_key ]['format'], 'MB' ); ?> value="MB">MB</option>
											<option <?php selected( $settings['limits'][ $role_key ]['format'], 'GB' ); ?> value="GB">GB</option>
										</select>
									</div>
								</div>
								<?php $this->render_type_limits( $role_key, $settings ); ?>
							</div>
						<?php }
					} ?>
				</div>
			</div>

		</div>

		<div class="bfu-settings__actions">
			<button class="bfu-save" name="bfu_settings_submit" value="1" type="submit">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
				<?php esc_html_e( 'Save Changes', 'tuxedo-big-file-uploads' ); ?>
			</button>
		</div>

	</form>
</div>
