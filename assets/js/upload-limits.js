/**
 * Per-file-type upload limits and the video hosting notice in the media uploader.
 *
 * Registers a plupload file filter so an oversized file is rejected before it
 * starts transferring, in both the classic uploader and the media modal. The
 * chunk handler enforces the same limits server side - this is purely so the
 * user finds out immediately and gets told which limit they hit.
 *
 * A second filter watches for video files and drops a short note next to the
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

	/*
	 * Static markup, no interpolation. A play glyph in the brand blue so the note
	 * reads as being about video before a word of it is read.
	 */
	var VIDEO_ICON = '<svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
		'<rect x="1" y="4" width="18" height="13" rx="2.5" fill="#26a9e0"/>' +
		'<path d="M8.4 8.2 L13 10.5 L8.4 12.8 Z" fill="#ffffff"/></svg>';

	/*
	 * Core's own upload errors stack directly below this note, so it copies their
	 * geometry exactly - full width, white, square, 4px accent rule, the same hairline
	 * shadow and the same 13px/#3c434a type - and changes only the accent colour. A
	 * tinted, rounded, narrower box read as bolted on next to them.
	 *
	 * Inline because the uploader appears on screens that never load the plugin
	 * stylesheet, and one note does not justify a request of its own.
	 */
	var NOTICE_CSS = 'display:flex;gap:8px;align-items:flex-start;' +
		'margin:12px 0;padding:8px 10px;background:#fff;border-left:4px solid #26a9e0;' +
		'box-shadow:0 1px 0 0 #dcdcde;font-size:13px;line-height:1.5;color:#3c434a;text-align:left;';

	/**
	 * Add the video note once, or re-reveal the one already there. Shown every
	 * time a video is queued, so it carries no dismiss control.
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

		var existing = document.querySelector( '.bfu-video-notice' );

		if ( existing ) {
			// Restore the layout the panel was built with, not the div default.
			existing.style.display = 'flex';
			return;
		}

		var notice = document.createElement( 'div' );

		notice.className     = 'bfu-video-notice';
		notice.style.cssText = NOTICE_CSS;

		var icon = document.createElement( 'span' );

		icon.style.cssText = 'flex:0 0 auto;margin-top:2px;';
		icon.innerHTML     = VIDEO_ICON;

		notice.appendChild( icon );

		// Message and link share one block so the icon keeps a column of its own.
		var body = document.createElement( 'span' );

		body.appendChild( document.createTextNode( cfg.message + ' ' ) );

		if ( cfg.url && cfg.link ) {
			var link = document.createElement( 'a' );

			link.href          = cfg.url;
			link.target        = '_blank';
			link.rel           = 'noopener';
			link.textContent   = cfg.link;
			link.style.cssText = 'font-weight:600;color:#1c6f9c;';

			body.appendChild( link );
		}

		notice.appendChild( body );

		place( notice, anchor );
	}

	/**
	 * Put the note directly above whatever is about to list the upload.
	 *
	 * The media library renders the size line inside the dashed drop target, where
	 * a full width box splits the drop zone in two, so there the note goes above
	 * the whole box. Media > Add New lists finished uploads in #media-items, and
	 * the note belongs against that list rather than up by the size line, where an
	 * unrelated notice can end up sitting between the two.
	 */
	function place( notice, anchor ) {
		var box = anchor.closest ? anchor.closest( '.uploader-inline' ) : null;

		if ( box && box.parentNode ) {
			box.parentNode.insertBefore( notice, box );
			return;
		}

		var items = document.getElementById( 'media-items' );

		if ( items && items.parentNode === anchor.parentNode ) {
			items.parentNode.insertBefore( notice, items );
			return;
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

	/**
	 * Show the rejection to the user.
	 *
	 * Core's uploaders rewrite the text of any plupload FILE_SIZE_ERROR into the
	 * generic "exceeds the maximum upload size for this site", which would sit
	 * right under a "Maximum upload file size: 2 GB" line for a 12 MB image. So
	 * the filter does not raise a plupload error; it places the message itself,
	 * in the same spot and markup core uses, so the reader learns which limit
	 * they hit.
	 */
	function report( uploader, file, text ) {
		// Media modal and anything else built on wp.Uploader: the same error
		// collection core pushes to, rendered (escaped) by the uploader status view.
		if ( window.wp && wp.Uploader && wp.Uploader.errors ) {
			wp.Uploader.errors.unshift( { message: text, data: { code: plupload.FILE_SIZE_ERROR }, file: file } );
			return;
		}

		// Classic uploader (Media > Add New): mirror core's failed-item markup.
		var list = document.getElementById( 'media-items' );
		if ( list ) {
			var item = document.createElement( 'div' );
			item.className = 'media-item error bfu-upload-limit-error';
			// .media-item reserves 70px for a thumbnail and progress bar. A file
			// refused before it transferred has neither, and the reserved space
			// left the message floating in an empty box.
			item.style.cssText = 'min-height:0;padding:8px 10px;';
			var p = document.createElement( 'p' );
			p.textContent   = text;
			p.style.cssText = 'margin:0;';
			item.appendChild( p );
			list.appendChild( item );
			return;
		}

		// Unknown host: fall back to core's generic rejection so the file is
		// at least visibly refused.
		uploader.trigger( 'Error', { code: plupload.FILE_SIZE_ERROR, message: text, file: file } );
	}

	plupload.addFileFilter( 'bfu_type_limits', function ( limits, file, cb ) {
		if ( ! limits ) {
			cb( true );
			return;
		}

		var type = typeForName( file.name );
		var max  = type && limits[ type ] ? parseInt( limits[ type ], 10 ) : 0;

		if ( max && file.size > max ) {
			report( this, file, message( file, type, max ) );
			cb( false );
			return;
		}

		cb( true );
	} );
}( window ) );
