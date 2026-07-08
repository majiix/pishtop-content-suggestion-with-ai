jQuery(document).ready(function($) {
	$('.pishtop-suggestions-container').each(function() {
		var $container = $(this);
		var data = {
			action: 'pishtop_get_suggestions',
			post_id: $container.data('post-id'),
			limit: $container.data('limit'),
			template: $container.data('template'),
			post_type: $container.data('post-type'),
			nonce: pishtopFrontend.nonce
		};

		$.post(pishtopFrontend.ajaxUrl, data, function(response) {
			if (response.success && response.data) {
				$container.html(response.data);
			} else {
				$container.fadeOut();
			}
		}).fail(function() {
			$container.fadeOut();
		});
	});
});
