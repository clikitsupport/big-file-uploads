(function ($) {
	'use strict';

	$('.bffu-notice').on('click', 'button', function (e) {
		e.preventDefault();

		const $notice = $(this).closest('.bffu-notice');
		const noticeId = $notice.data('notice-id');
		const action = $(this).data('action');
		const link = $(this).data('link');

		// Open the link immediately, inside the click gesture, so the browser's
		// popup blocker doesn't swallow it (a window.open() fired from an async
		// AJAX callback gets blocked). The AJAX below just records the click.
		if (link) {
			window.open(link, '_blank', 'noopener');
		}

		$.ajax({
			url: bffuPromo.ajaxurl,
			type: 'POST',
			data: {
				action: 'bffu_handle_promo_action',
				notice_id: noticeId,
				action_type: action,
				nonce: bffuPromo.nonce
			},
			success: function (response) {
				if (response.success) {
					$notice.slideUp();
				}
			}
		});
	});
})(jQuery);