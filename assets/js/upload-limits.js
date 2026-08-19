/**
 * Per-file-type upload limits in the media uploader.
 *
 * Registers a plupload file filter so an oversized file is rejected before it
 * starts transferring, in both the classic uploader and the media modal. The
 * chunk handler enforces the same limits server side - this is purely so the
 * user finds out immediately and gets told which limit they hit.
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
