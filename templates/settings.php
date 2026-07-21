<div class="card">
	<form action="<?php echo esc_url( $this->settings_url() ); ?>" method="post">
		<?php wp_nonce_field( 'bfu_settings' ); ?>
		<div class="card-header h5">
			<div class="d-flex align-items-center">
				<h5 class="m-0 mr-auto p-0"><?php esc_html_e( 'Settings', 'tuxedo-big-file-uploads' ); ?></h5>
			</div>
		</div>
		<div class="card-body p-md-5">
			<div class="row justify-content-center mb-4">
				<div class="col-md-6 col-sm-12">
					<h5><?php esc_html_e( 'Maximum Upload Size', 'tuxedo-big-file-uploads' ); ?></h5>
					<p class="lead"><?php printf( esc_html__( 'Big File Uploads allows you to bypass your hosting file size %s limit by seamlessly uploading in multiple smaller chunks. Set the max filesize you want to allow users to upload in Megabytes (MB) or Gigabytes (GB) up to what your hosting provider can handle. Toggle "Customize by user role" to set the maximum file size for each user role with upload capabilities.', 'tuxedo-big-file-uploads' ), size_format( $this->max_upload_size ) ); ?></p>
				</div>
				<div class="col-md-6 col-sm-12 text-right p-4">
					<div class="custom-control custom-switch mb-3">
						<input type="checkbox" name="by_role" class="custom-control-input" id="customSwitch_role" value="1" <?php checked( $settings['by_role'] ); ?>>
						<label class="custom-control-label" for="customSwitch_role"><?php esc_html_e( 'Customize by user role', 'tuxedo-big-file-uploads' ); ?></label>
					</div>
					<div class="<?php echo $settings['by_role'] ? 'bfu-disabled' : ''; ?>" id="bfu-settings">
						<div class="row justify-content-end">
							<div class="pl-5 col-xl-6 col-lg-7 col-md-8 text-left"><strong><?php esc_html_e( 'All Users', 'tuxedo-big-file-uploads' ); ?></strong></div>
						</div>
						<div class="row mb-3 justify-content-end">
							<div class="col-xl-6 col-lg-7 col-md-8">
								<div class="input-group bfu-input-limit">
									<input name="upload_limit" id="upload-limit" type="number" step="0.1" min="0" value="<?php echo esc_attr( $settings['limits']['all']['bytes'] ); ?>" class="form-control text-right"
									       aria-label="<?php esc_attr_e( 'All users upload limit', 'tuxedo-big-file-uploads' ); ?>">
									<div class="input-group-append">
										<select name="upload_limit_format" id="upload-limit-format">
											<option <?php selected( $settings['limits']['all']['format'], 'MB' ); ?> value="MB">MB</option>
											<option <?php selected( $settings['limits']['all']['format'], 'GB' ); ?> value="GB">GB</option>
										</select>
									</div>
								</div>
								<div class="row text-center">
									<div class="col">
										<small><?php printf( esc_html__( 'Maximum Upload Size (default is %s)', 'tuxedo-big-file-uploads' ), size_format( $this->max_upload_size ) ); ?> <span class="dashicons dashicons-info text-muted" data-toggle="tooltip" title="<?php esc_attr_e( 'Default size is defined by your hosting provider', 'tuxedo-big-file-uploads' ); ?>"></span></small>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="<?php echo $settings['by_role'] ? '' : 'bfu-disabled'; ?>" id="bfu-settings-roles">
						<?php
						foreach ( wp_roles()->roles as $role_key => $role ) {
							if ( isset( $role['capabilities']['upload_files'] ) && $role['capabilities']['upload_files'] ) {
								?>
								<div class="row justify-content-end">
									<div class="pl-5 col-xl-6 col-lg-7 col-md-8 text-left"><strong><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></strong></div>
								</div>
								<div class="row mb-3 justify-content-end">
									<div class="col-xl-6 col-lg-7 col-md-8">
										<div class="input-group bfu-input-limit">
											<input name="upload_limit[<?php echo esc_attr( $role_key ); ?>]" id="upload-limit-<?php echo esc_attr( $role_key ); ?>" type="number" step="0.1" min="0" value="<?php echo esc_attr( $settings['limits'][ $role_key ]['bytes'] ); ?>"
											       class="form-control text-right" aria-label="<?php printf( esc_attr__( '%s upload limit', 'tuxedo-big-file-uploads' ), translate_user_role( $role['name'] ) ); ?>">
											<div class="input-group-append">
												<select name="upload_limit_format[<?php echo esc_attr( $role_key ); ?>]" id="upload-limit-format-<?php echo esc_attr( $role_key ); ?>">
													<option <?php selected( $settings['limits'][ $role_key ]['format'], 'MB' ); ?> value="MB">MB</option>
													<option <?php selected( $settings['limits'][ $role_key ]['format'], 'GB' ); ?> value="GB">GB</option>
												</select>
											</div>
										</div>
										<div class="row text-center">
											<div class="col">
												<small><?php printf( esc_html__( 'Maximum Upload Size (default is %s)', 'tuxedo-big-file-uploads' ), size_format( $this->max_upload_size ) ); ?> <span class="dashicons dashicons-info text-muted" data-toggle="tooltip" title="<?php esc_attr_e( 'Default size is defined by your hosting provider', 'tuxedo-big-file-uploads' ); ?>"></span></small>
											</div>
										</div>
									</div>
								</div>
							<?php }
						} ?>
					</div>
				</div>
			</div>
			<div class="row justify-content-center mb-4">
				<div class="col-xl-2 col-lg-3 col-md-4 text-center">
					<button class="btn text-nowrap btn-info btn-lg btn-block" name="bfu_settings_submit" value="1" type="submit"><?php esc_html_e( 'Save', 'tuxedo-big-file-uploads' ); ?></button>
				</div>
			</div>
			<?php if ( ! $this->is_infinite_uploads_active() ) { ?>
				<div class="row justify-content-center mt-3">
					<div class="col text-center">
						<p class="iu-upgrade-text"><?php esc_html_e( 'Want unlimited storage space, CDN, video hosting, folders, and enhanced media library search?', 'tuxedo-big-file-uploads' ); ?> <a href="" data-toggle="modal" data-target="#upgrade-modal" class="text-warning"><?php esc_html_e( 'Move your media files to the Infinite Uploads cloud', 'tuxedo-big-file-uploads' ); ?></a>.
						</p>
					</div>
				</div>
			<?php } ?>
		</div>
	</form>
</div>
