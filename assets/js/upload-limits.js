/**
 * Per-file-type upload limits and the video hosting notice in the media uploader.
 *
 * Registers a plupload file filter so an oversized file is rejected before it
 * starts transferring, in both the classic uploader and the media modal. The
 * chunk handler enforces the same limits server side - this is purely so the
 * user finds out immediately and gets told which limit they hit.
 *
 * A second filter watches for video files and drops a one line note next to the
 * uploader's size text. It never rejects anything: raising the limit does let the
 * video through, it just is not the right home for it.
 */
( function ( window ) {
	'use strict';

	var plupload = window.plupload;
	var data     = window.bfuUploadLimits || {};

	if ( ! plupload || typeof plupload.addFileFilter !== 'function' ) {
		return;
	}

	/**
	 * Classify a filename using the same extension table as the server.
	 */
	function typeForName( name ) {
		var dot = String( name ).lastIndexOf( '.' );

		if ( dot < 0 ) {
			return '';
		}

		var ext = String( name ).slice( dot + 1 ).toLowerCase();

		return ( data.extensions && data.extensions[ ext ] ) || '';
	}

	function formatBytes( bytes ) {
		if ( bytes >= 1073741824 ) {
			return String( ( bytes / 1073741824 ).toFixed( 1 ) ).replace( /\.0$/, '' ) + ' GB';
		}

		return Math.round( bytes / 1048576 ) + ' MB';
	}

	function message( file, type, max ) {
		var label    = ( data.labels && data.labels[ type ] ) || type;
		var template = ( data.strings && data.strings.too_large ) ||
			'%1$s is bigger than the %2$s limit of %3$s.';

		return template
			.replace( '%1$s', file.name )
			.replace( '%2$s', label )
			.replace( '%3$s', formatBytes( max ) );
	}

	/**
	 * The uploader's "Maximum upload file size" line, which both the classic
	 * uploader and the media modal render. Prefer one the user can actually see:
	 * the modal keeps a hidden copy in its template markup.
	 */
	function sizeLine() {
		var lines = document.querySelectorAll( '.max-upload-size' );
		var i;

		for ( i = 0; i < lines.length; i++ ) {
			if ( lines[ i ].offsetParent !== null ) {
				return lines[ i ];
			}
		}

		return lines[ 0 ] || null;
	}

	/**
	 * Add the video note once, or re-reveal the one already there. Shown every
	 * time a video is queued, so it stays small and carries no dismiss control.
	 */
	function showVideoNotice() {
		var cfg = data.video;

		if ( ! cfg || ! cfg.message ) {
			return;
		}

		var anchor = sizeLine();

		if ( ! anchor || ! anchor.parentNode ) {
			return;
		}

		var existing = anchor.parentNode.querySelector( '.bfu-video-notice' );

		if ( existing ) {
			existing.style.display = '';
			return;
		}

		var notice = document.createElement( 'p' );

		notice.className     = 'bfu-video-notice';
		notice.style.cssText = 'margin:4px 0 0;font-size:12px;line-height:1.6;color:#646970;';
		notice.appendChild( document.createTextNode( cfg.message + ' ' ) );

		if ( cfg.url && cfg.link ) {
			var link = document.createElement( 'a' );

			link.href        = cfg.url;
			link.target      = '_blank';
			link.rel         = 'noopener';
			link.textContent = cfg.link;

			notice.appendChild( link );
		}

		anchor.parentNode.insertBefore( notice, anchor.nextSibling );
	}

	plupload.addFileFilter( 'bfu_video_notice', function ( enabled, file, cb ) {
		if ( enabled && 'video' === typeForName( file.name ) ) {
			showVideoNotice();
		}

		// Informational only - every file passes.
		cb( true );
	} );

	plupload.addFileFilter( 'bfu_type_limits', function ( limits, file, cb ) {
		if ( ! limits ) {
			cb( true );
			return;
		}

		var type = typeForName( file.name );
		var max  = type && limits[ type ] ? parseInt( limits[ type ], 10 ) : 0;

		if ( max && file.size > max ) {
			this.trigger( 'Error', {
				code:    plupload.FILE_SIZE_ERROR,
				message: message( file, type, max ),
				file:    file
			} );
			cb( false );
			return;
		}

		cb( true );
	} );
}( window ) );
