<?php
/**
 * Email summary body.
 *
 * Plain table layout with inline styles, because that is what email clients render. The logos
 * are served from the site itself, so opening the email loads nothing from a third party.
 *
 * Order matters: the numbers and the Infinite Uploads offer sit above the fold, the library
 * totals and tip below it.
 *
 * @var array                         $report From Big_File_Uploads_Email_Digest::build_report().
 * @var Big_File_Uploads_Email_Digest $digest
 *
 * @package BigFileUploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bfu_uploads   = $report['uploads'];
$bfu_frequency = $report['frequency'];
$bfu_site      = get_bloginfo( 'name' );
$bfu_tip       = $digest->get_tip( $report );
$bfu_plugin    = BigFileUploads::get_instance();
$bfu_largest   = $bfu_uploads['largest'] ? $bfu_uploads['largest'][0] : null;

$bfu_period_noun = array(
	'daily'   => __( 'day', 'tuxedo-big-file-uploads' ),
	'weekly'  => __( 'week', 'tuxedo-big-file-uploads' ),
	'monthly' => __( 'month', 'tuxedo-big-file-uploads' ),
);
$bfu_period_noun = isset( $bfu_period_noun[ $bfu_frequency ] ) ? $bfu_period_noun[ $bfu_frequency ] : $bfu_period_noun['monthly'];

// The two headline numbers, each with its change from the previous period.
$bfu_tiles = array(
	array(
		'label'  => __( 'Files uploaded', 'tuxedo-big-file-uploads' ),
		'value'  => number_format_i18n( $bfu_uploads['count'] ),
		'change' => $report['change_files'],
	),
	array(
		'label'  => __( 'Storage added', 'tuxedo-big-file-uploads' ),
		'value'  => $digest->format_bytes( $bfu_uploads['bytes'] ),
		'change' => $report['change'],
	),
);

// One calm, consistent offer, left out entirely once Infinite Uploads is active.
$bfu_iu_features = array(
	__( 'Cloud storage', 'tuxedo-big-file-uploads' ),
	__( 'Global CDN delivery', 'tuxedo-big-file-uploads' ),
	__( 'Image optimization', 'tuxedo-big-file-uploads' ),
	__( 'Media folders', 'tuxedo-big-file-uploads' ),
	__( 'Advanced media search', 'tuxedo-big-file-uploads' ),
	__( 'Room to grow without limits', 'tuxedo-big-file-uploads' ),
);
$bfu_iu_rows = array_chunk( $bfu_iu_features, 2 );

$bfu_font  = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
$bfu_muted = 'color:#64748b;';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $digest->get_subject( $report ) ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f7fa;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fa;font-family:<?php echo esc_attr( $bfu_font ); ?>;color:#1f2d3d;">
	<tr>
		<td align="center" style="padding:28px 16px 32px;">

			<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
				<tr>
					<td style="padding-right:12px;vertical-align:middle;">
						<img src="<?php echo esc_url( plugins_url( 'assets/img/bfu-logo-sm.png', dirname( __FILE__ ) ) ); ?>" width="36" height="48" alt="" style="display:block;width:36px;height:48px;border:0;">
					</td>
					<td style="vertical-align:middle;font-size:24px;font-weight:700;color:#1f2d3d;"><?php esc_html_e( 'Big File Uploads', 'tuxedo-big-file-uploads' ); ?></td>
				</tr>
			</table>

			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e6eef4;border-radius:12px;">

				<tr>
					<td style="padding:28px 32px 0;">
						<div style="font-size:21px;font-weight:700;line-height:1.3;">
							<?php
							/* translators: %s: period name such as "September 2026" */
							printf( esc_html__( 'Your uploads for %s', 'tuxedo-big-file-uploads' ), esc_html( $report['period']['label'] ) );
							?>
						</div>
						<div style="margin-top:4px;font-size:14px;<?php echo esc_attr( $bfu_muted ); ?>"><?php echo esc_html( $bfu_site ); ?></div>
					</td>
				</tr>

				<?php if ( $report['first'] ) : ?>
					<tr>
						<td style="padding:16px 32px 0;">
							<div style="padding:12px 14px;border-radius:8px;background:#e8f4fb;font-size:13px;line-height:1.5;color:#0b6d95;">
								<?php esc_html_e( 'This is your first upload summary from Big File Uploads. It is built on your own site from your Media Library, and no data leaves it.', 'tuxedo-big-file-uploads' ); ?>
								<a href="<?php echo esc_url( $report['manage_url'] ); ?>" style="color:#0b6d95;font-weight:600;"><?php esc_html_e( 'Change how often it arrives, or turn it off.', 'tuxedo-big-file-uploads' ); ?></a>
							</div>
						</td>
					</tr>
				<?php endif; ?>

				<tr>
					<td style="padding:20px 32px 0;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
							<tr>
								<?php foreach ( $bfu_tiles as $bfu_index => $bfu_tile ) : ?>
									<?php if ( $bfu_index ) : ?>
										<td width="12" style="font-size:0;line-height:0;">&nbsp;</td>
									<?php endif; ?>
									<td width="50%" align="center" style="padding:16px 12px;border:1px solid #e6eef4;border-radius:10px;vertical-align:top;">
										<div style="font-size:13px;font-weight:600;<?php echo esc_attr( $bfu_muted ); ?>"><?php echo esc_html( $bfu_tile['label'] ); ?></div>
										<div style="margin-top:4px;font-size:28px;font-weight:700;line-height:1.2;"><?php echo esc_html( $bfu_tile['value'] ); ?></div>
										<?php if ( $bfu_tile['change'] && null !== $bfu_tile['change']['percent'] ) : ?>
											<div style="margin-top:4px;font-size:12px;font-weight:600;<?php echo esc_attr( $bfu_muted ); ?>">
												<?php
												$bfu_percent = $bfu_tile['change']['percent'];
												if ( $bfu_percent > 0 ) {
													/* translators: 1: percentage, 2: "day", "week" or "month" */
													printf( esc_html__( 'Up %1$s%% from last %2$s', 'tuxedo-big-file-uploads' ), esc_html( number_format_i18n( $bfu_percent ) ), esc_html( $bfu_period_noun ) );
												} elseif ( $bfu_percent < 0 ) {
													/* translators: 1: percentage, 2: "day", "week" or "month" */
													printf( esc_html__( 'Down %1$s%% from last %2$s', 'tuxedo-big-file-uploads' ), esc_html( number_format_i18n( abs( $bfu_percent ) ) ), esc_html( $bfu_period_noun ) );
												} else {
													/* translators: %s: "day", "week" or "month" */
													printf( esc_html__( 'Same as last %s', 'tuxedo-big-file-uploads' ), esc_html( $bfu_period_noun ) );
												}
												?>
											</div>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						</table>
					</td>
				</tr>

				<?php if ( $bfu_uploads['types'] ) : ?>
					<tr>
						<td style="padding:14px 32px 0;font-size:13px;line-height:1.9;<?php echo esc_attr( $bfu_muted ); ?>">
							<?php foreach ( $bfu_uploads['types'] as $bfu_type => $bfu_type_totals ) : ?>
								<span style="display:inline-block;margin-right:16px;white-space:nowrap;">
									<span style="display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:50%;background:<?php echo esc_attr( $bfu_plugin->get_file_type_format( $bfu_type, 'color' ) ); ?>;"></span><strong style="color:#1f2d3d;font-weight:600;"><?php echo esc_html( $bfu_plugin->get_file_type_format( $bfu_type, 'label' ) ); ?></strong>
									<?php echo esc_html( number_format_i18n( $bfu_type_totals['count'] ) . ' · ' . $digest->format_bytes( $bfu_type_totals['bytes'] ) ); ?>
								</span>
							<?php endforeach; ?>
							<?php if ( $bfu_largest ) : ?>
								<br>
								<?php esc_html_e( 'Largest upload:', 'tuxedo-big-file-uploads' ); ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $bfu_largest['id'] . '&action=edit' ) ); ?>" style="color:#0b6d95;text-decoration:none;font-weight:600;"><?php echo esc_html( $bfu_largest['title'] ); ?></a>
								(<?php echo esc_html( $digest->format_bytes( $bfu_largest['bytes'] ) ); ?>)
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>

				<?php if ( ! $report['iu_active'] ) : ?>
					<tr>
						<td style="padding:22px 32px 0;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d5e9f6;border-radius:12px;background:#f3f9fd;">
								<tr>
									<td style="padding:22px 24px;">
										<img src="<?php echo esc_url( plugins_url( 'assets/img/iu-logo-email.png', dirname( __FILE__ ) ) ); ?>" width="160" height="40" alt="<?php esc_attr_e( 'Infinite Uploads', 'tuxedo-big-file-uploads' ); ?>" style="display:block;width:160px;height:40px;border:0;">
										<div style="margin-top:12px;font-size:18px;font-weight:700;line-height:1.3;color:#1f2d3d;"><?php esc_html_e( 'Offload, optimize, and organize your media', 'tuxedo-big-file-uploads' ); ?></div>
										<div style="margin-top:6px;font-size:14px;line-height:1.55;color:#445566;"><?php esc_html_e( 'Move your Media Library to the cloud so uploads stop filling your server, and serve every file from a global CDN so pages load faster.', 'tuxedo-big-file-uploads' ); ?></div>
										<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;font-size:14px;color:#1f2d3d;">
											<?php foreach ( $bfu_iu_rows as $bfu_iu_row ) : ?>
												<tr>
													<?php foreach ( $bfu_iu_row as $bfu_iu_feature ) : ?>
														<td width="50%" style="padding:4px 8px 4px 0;vertical-align:top;">
															<span style="display:inline-block;width:18px;height:18px;margin-right:8px;border-radius:50%;background:#26a9e0;color:#ffffff;font-size:11px;font-weight:700;line-height:18px;text-align:center;vertical-align:middle;">&#10003;</span><span style="vertical-align:middle;"><?php echo esc_html( $bfu_iu_feature ); ?></span>
														</td>
													<?php endforeach; ?>
												</tr>
											<?php endforeach; ?>
										</table>
										<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:16px;">
											<tr>
												<td style="border-radius:8px;background:#26a9e0;">
													<a href="<?php echo esc_url( $digest->link( 'pricing/', 'upsell' ) ); ?>" style="display:inline-block;padding:11px 22px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Start your free trial', 'tuxedo-big-file-uploads' ); ?></a>
												</td>
												<td style="padding-left:14px;font-size:13px;<?php echo esc_attr( $bfu_muted ); ?>"><?php esc_html_e( 'Free for 7 days.', 'tuxedo-big-file-uploads' ); ?></td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				<?php endif; ?>

				<?php if ( $report['library'] ) : ?>
					<tr>
						<td style="padding:22px 32px 0;font-size:13px;line-height:1.55;<?php echo esc_attr( $bfu_muted ); ?>">
							<strong style="color:#1f2d3d;"><?php esc_html_e( 'Your Media Library:', 'tuxedo-big-file-uploads' ); ?></strong>
							<?php
							/* translators: 1: date of the scan, 2: total size, 3: number of files */
							printf( esc_html__( 'Your last full storage scan (%1$s) found %2$s across %3$s files.', 'tuxedo-big-file-uploads' ), esc_html( wp_date( get_option( 'date_format' ), $report['library']['scanned'] ) ), esc_html( $digest->format_bytes( $report['library']['bytes'] ) ), esc_html( number_format_i18n( $report['library']['files'] ) ) );
							if ( $report['library']['added_since'] > 0 ) {
								echo ' ';
								/* translators: %s: number of files */
								printf( esc_html( _n( '%s file has been uploaded since.', '%s files have been uploaded since.', $report['library']['added_since'], 'tuxedo-big-file-uploads' ) ), esc_html( number_format_i18n( $report['library']['added_since'] ) ) );
								echo ' ';
								?>
								<a href="<?php echo esc_url( $bfu_plugin->settings_url() ); ?>" style="color:#0b6d95;font-weight:600;"><?php esc_html_e( 'Run a new scan for an up-to-date total.', 'tuxedo-big-file-uploads' ); ?></a>
								<?php
							}
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php if ( $bfu_uploads['partial'] ) : ?>
					<tr>
						<td style="padding:10px 32px 0;font-size:12px;<?php echo esc_attr( $bfu_muted ); ?>">
							<?php
							/* translators: %s: number of uploads */
							printf( esc_html__( 'Sizes and file types cover the newest %s uploads in this period.', 'tuxedo-big-file-uploads' ), esc_html( number_format_i18n( Big_File_Uploads_Email_Digest::ROW_LIMIT ) ) );
							?>
						</td>
					</tr>
				<?php endif; ?>

				<tr>
					<td style="padding:20px 32px 28px;">
						<div style="padding:12px 14px;border-radius:8px;background:#f8fafc;font-size:13px;line-height:1.55;color:#445566;">
							<strong style="color:#1f2d3d;"><?php esc_html_e( 'Tip:', 'tuxedo-big-file-uploads' ); ?></strong>
							<?php echo esc_html( $bfu_tip['text'] ); ?>
							<a href="<?php echo esc_url( $bfu_tip['url'] ); ?>" style="color:#0b6d95;font-weight:600;"><?php echo esc_html( $bfu_tip['link_label'] ); ?></a>
						</div>
					</td>
				</tr>

			</table>

			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
				<tr>
					<td align="center" style="padding:18px 16px 0;font-size:12px;line-height:1.6;<?php echo esc_attr( $bfu_muted ); ?>">
						<?php
						/* translators: %s: site name */
						printf( esc_html__( 'Sent by Big File Uploads on %s.', 'tuxedo-big-file-uploads' ), esc_html( $bfu_site ) );
						?>
						<a href="<?php echo esc_url( $report['manage_url'] ); ?>" style="color:#64748b;"><?php esc_html_e( 'Change how often you get this, or turn it off.', 'tuxedo-big-file-uploads' ); ?></a>
					</td>
				</tr>
			</table>

		</td>
	</tr>
</table>
</body>
</html>
