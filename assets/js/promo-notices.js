(function ($) {
	'use strict';

	$('.bffu-notice').on('click', 'button', function (e) {
		e.preventDefault();

		const $notice = $(this).closest('.bffu-notice');
		const noticeId = $notice.data('notice-id');
		const action = $(this).data('action');
		const link = $(this).data('link');

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

					if (link) {
						window.open(link, '_blank');
						return;
					}
				}
			}
		});
	});
})(jQuery);