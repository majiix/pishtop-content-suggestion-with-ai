jQuery(document).ready(function($) {
	var loadSuggestions = function($container) {
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
	};

	if ('IntersectionObserver' in window) {
		var observer = new IntersectionObserver(function(entries, observer) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting) {
					var $container = $(entry.target);
					loadSuggestions($container);
					observer.unobserve(entry.target);
				}
			});
		}, {
			rootMargin: '100px'
		});

		$('.pishtop-suggestions-container').each(function() {
			observer.observe(this);
		});
	} else {
		$('.pishtop-suggestions-container').each(function() {
			loadSuggestions($(this));
		});
	}
});
