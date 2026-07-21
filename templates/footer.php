<div id="iup-footer" class="container mt-5">
	<div class="row">
		<div class="col-sm text-center text-muted">
			<strong><?php printf( esc_html__( "Made with %s by Infinite Uploads", 'tuxedo-big-file-uploads' ), '<span class="dashicons dashicons-heart"></span>' ); ?></strong>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-sm text-center text-muted">
			<a href="<?php echo esc_url( $this->api_url( '/support/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_content=footer&utm_term=support' ) ); ?>" class="text-muted"><?php esc_html_e( "Support", 'tuxedo-big-file-uploads' ); ?></a> |
			<a href="<?php echo esc_url( $this->api_url( '/terms-of-service/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_content=footer&utm_term=terms' ) ); ?>" class="text-muted"><?php esc_html_e( "Terms of Service", 'tuxedo-big-file-uploads' ); ?></a> |
			<a href="<?php echo esc_url( $this->api_url( '/privacy/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_content=footer&utm_term=privacy' ) ); ?>" class="text-muted"><?php esc_html_e( "Privacy Policy", 'tuxedo-big-file-uploads' ); ?></a>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-sm text-center text-muted">
			<a href="https://twitter.com/infiniteuploads" class="text-muted" data-toggle="tooltip" title="<?php esc_attr_e( 'Twitter', 'tuxedo-big-file-uploads' ); ?>"><span class="dashicons dashicons-twitter"></span></a>
			<a href="https://www.facebook.com/infiniteuploads/" class="text-muted" data-toggle="tooltip" title="<?php esc_attr_e( 'Facebook', 'tuxedo-big-file-uploads' ); ?>"><span class="dashicons dashicons-facebook-alt"></span></a>
		</div>
	</div>
</div>
