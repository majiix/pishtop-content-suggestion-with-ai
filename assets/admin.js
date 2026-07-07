jQuery(document).ready(function($) {
	// Tab Switching
	$('.pishtop-tabs-nav a').on('click', function(e) {
		e.preventDefault();
		var target = $(this).attr('href').replace('#', 'tab-');
		$('.pishtop-tabs-nav a').removeClass('active');
		$(this).addClass('active');
		$('.pishtop-tab-content').removeClass('active');
		$('#' + target).addClass('active');
		window.location.hash = $(this).attr('href');
	});

	// Support direct hash links
	if (window.location.hash) {
		var activeTab = $('.pishtop-tabs-nav a[href="' + window.location.hash + '"]');
		if (activeTab.length) {
			activeTab.trigger('click');
		}
	}

	// Load OpenRouter models asynchronously via AJAX
	function loadOpenRouterModels() {
		var $embSelect = $('#pishtop_embedding_model');
		var $rankSelect = $('#pishtop_ranking_model');

		var selectedEmb = $embSelect.val();
		var selectedRank = $rankSelect.val();

		$.get(pishtopSettings.ajaxUrl, {
			action: 'pishtop_load_models',
			nonce: pishtopSettings.nonce
		}, function(response) {
			if (response.success) {
				$embSelect.empty().removeClass('loading');
				$.each(response.data.embeddings, function(i, model) {
					var selectedAttr = (model.id === selectedEmb) ? 'selected="selected"' : '';
					$embSelect.append('<option value="' + model.id + '" ' + selectedAttr + '>' + model.name + '</option>');
				});

				$rankSelect.empty().removeClass('loading');
				$.each(response.data.rankings, function(i, model) {
					var selectedAttr = (model.id === selectedRank) ? 'selected="selected"' : '';
					$rankSelect.append('<option value="' + model.id + '" ' + selectedAttr + '>' + model.name + '</option>');
				});
			} else {
				$embSelect.removeClass('loading');
				$rankSelect.removeClass('loading');
			}
		}).fail(function() {
			$embSelect.removeClass('loading');
			$rankSelect.removeClass('loading');
		});
	}

	loadOpenRouterModels();

	// Confirm action helper
	function runAjaxAction(btnId, actionName, dataPayload, successCallback) {
		var $btn = $('#' + btnId);
		var $spinner = $btn.find('.btn-spinner');

		if ($btn.hasClass('disabled') || !$spinner.hasClass('hidden')) {
			return;
		}

		if (!confirm(pishtopSettings.confirm)) {
			return;
		}

		$spinner.removeClass('hidden');
		$btn.addClass('disabled');

		var reqData = $.extend({
			action: actionName,
			nonce: pishtopSettings.nonce
		}, dataPayload || {});

		$.post(pishtopSettings.ajaxUrl, reqData, function(response) {
			$spinner.addClass('hidden');
			$btn.removeClass('disabled');
			if (response.success) {
				alert(response.data);
				if (successCallback) {
					successCallback(response.data);
				}
			} else {
				alert('Error: ' + (response.data || 'Unknown error.'));
			}
		}).fail(function() {
			$spinner.addClass('hidden');
			$btn.removeClass('disabled');
			alert('Request failed. Please try again.');
		});
	}

	// Caches Clearing
	$('#pishtop-clear-rec-caches').on('click', function() {
		runAjaxAction('pishtop-clear-rec-caches', 'pishtop_clear_cache');
	});

	$('#pishtop-clear-embeddings').on('click', function() {
		runAjaxAction('pishtop-clear-embeddings', 'pishtop_clear_embeddings', {}, function() {
			location.reload();
		});
	});

	$('#pishtop-clear-logs-btn').on('click', function() {
		runAjaxAction('pishtop-clear-logs-btn', 'pishtop_clear_logs', {}, function() {
			loadLogs(1);
		});
	});

	// Template Repeater Actions
	$('#pishtop-add-new-template').on('click', function(e) {
		e.preventDefault();
		var index = $('#pishtop-templates-repeater .template-item-card').length;
		var templateHtml = $('#pishtop-template-repeater-row').html();
		templateHtml = templateHtml.replace(/\{\{idx\}\}/g, index);
		$('#pishtop-templates-repeater').append(templateHtml);
	});

	$(document).on('click', '.pishtop-btn-remove-template', function(e) {
		e.preventDefault();
		if (confirm(pishtopSettings.confirm)) {
			$(this).closest('.template-item-card').remove();
		}
	});

	// Diagnostic Logs AJAX Loading
	var currentLogPage = 1;
	function loadLogs(page) {
		var level = $('#pishtop-log-level-filter').val();
		var search = $('#pishtop-log-search').val();
		var $tbody = $('#pishtop-logs-tbody');
		var $prev = $('#pishtop-logs-prev');
		var $next = $('#pishtop-logs-next');

		$tbody.html('<tr><td colspan="4" style="text-align:center;"><span class="spinner is-active" style="float:none;"></span> Loading...</td></tr>');

		$.get(pishtopSettings.ajaxUrl, {
			action: 'pishtop_get_logs',
			nonce: pishtopSettings.nonce,
			log_page: page,
			log_level: level,
			log_search: search
		}, function(response) {
			if (response.success) {
				$tbody.html(response.data.html);
				currentLogPage = response.data.page;
				$('#pishtop-current-log-page').text(currentLogPage);
				$('#pishtop-total-log-pages').text(response.data.totalPages || 1);

				$prev.prop('disabled', currentLogPage <= 1);
				$next.prop('disabled', currentLogPage >= response.data.totalPages);
			} else {
				$tbody.html('<tr><td colspan="4" style="text-align:center; color:red;">Failed to load logs.</td></tr>');
			}
		}).fail(function() {
			$tbody.html('<tr><td colspan="4" style="text-align:center; color:red;">Failed to load logs.</td></tr>');
		});
	}

	$('#diagnostics-tab-trigger').on('click', function() {
		loadLogs(1);
	});

	$('#pishtop-refresh-logs').on('click', function() {
		loadLogs(currentLogPage);
	});

	$('#pishtop-log-level-filter, #pishtop-log-search').on('change keyup search', function() {
		loadLogs(1);
	});

	$('#pishtop-logs-prev').on('click', function() {
		if (currentLogPage > 1) {
			loadLogs(currentLogPage - 1);
		}
	});

	$('#pishtop-logs-next').on('click', function() {
		loadLogs(currentLogPage + 1);
	});

	// Context Details Modal
	$(document).on('click', '.view-context', function(e) {
		e.preventDefault();
		var rawJson = $(this).data('context');
		try {
			var formatted = JSON.stringify(rawJson, null, 2);
			$('#pishtop-modal-payload-code').text(formatted);
		} catch(err) {
			$('#pishtop-modal-payload-code').text(rawJson);
		}
		$('#pishtop-context-modal').removeClass('hidden');
	});

	$('.pishtop-modal-close, .pishtop-modal-backdrop').on('click', function() {
		$('#pishtop-context-modal').addClass('hidden');
	});

	// Bulk Indexing Controller
	var isIndexing = false;
	var indexedCount = 0;
	var totalToIndex = 0;

	$('#pishtop-start-bulk-index').on('click', function() {
		if (isIndexing) return;

		totalToIndex = parseInt($(this).data('count'));
		if (totalToIndex <= 0) return;

		if (!confirm('Bulk indexing will make OpenRouter API requests for all unindexed posts. Proceed?')) {
			return;
		}

		isIndexing = true;
		indexedCount = 0;
		$(this).addClass('disabled').prop('disabled', true);
		$(this).find('.btn-spinner').removeClass('hidden');
		
		runNextIndexStep();
	});

	function runNextIndexStep() {
		if (!isIndexing) return;

		var $btn = $('#pishtop-start-bulk-index');
		$btn.html('<span class="btn-spinner"></span> Indexing (' + indexedCount + '/' + totalToIndex + ')...');

		$.post(pishtopSettings.ajaxUrl, {
			action: 'pishtop_bulk_index',
			nonce: pishtopSettings.nonce
		}, function(response) {
			if (response.success) {
				if (response.data.done) {
					isIndexing = false;
					$btn.html('Done!');
					alert(response.data.message);
					location.reload();
				} else {
					indexedCount++;
					runNextIndexStep();
				}
			} else {
				isIndexing = false;
				$btn.removeClass('disabled').prop('disabled', false).html('Start Bulk Indexing');
				$btn.find('.btn-spinner').addClass('hidden');
				alert('Indexing interrupted: ' + (response.data || 'Unknown error.'));
			}
		}).fail(function() {
			isIndexing = false;
			$btn.removeClass('disabled').prop('disabled', false).html('Start Bulk Indexing');
			$btn.find('.btn-spinner').addClass('hidden');
			alert('Network error during indexing.');
		});
	}
});
