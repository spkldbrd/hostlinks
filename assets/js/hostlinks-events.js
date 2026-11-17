(function ($) {
	'use strict';

	function compactMD(d) {
		if (!d || typeof d.format !== 'function') {
			return '';
		}
		return d.format('MM/DD');
	}

	function refreshCompact($input) {
		var $span = $input.closest('tr').find('span.hl-compact-date');
		if (!$span.length) {
			return;
		}
		var dp = $input.data('daterangepicker');
		if (!dp) {
			return;
		}
		var s = dp.startDate;
		var e = dp.endDate;
		var out = compactMD(s);
		if (e && e.format('YYYY-MM-DD') !== s.format('YYYY-MM-DD')) {
			out += '-' + compactMD(e);
		}
		$span.text(out || '—').attr('title', s.format('YYYY/MM/DD') + ' - ' + e.format('YYYY/MM/DD'));
		$input.val(s.format('YYYY/MM/DD') + ' - ' + e.format('YYYY/MM/DD'));
	}

	function closeMenus() {
		$('.hl-row-menu-panel').attr('hidden', true);
		$('.hl-menu-toggle').attr('aria-expanded', 'false');
	}

	function syncTypeBadge($select) {
		var color = $select.find('option:selected').attr('data-color') || '';
		$select.closest('.hl-type-badge').attr('data-color', color);
	}

	function setStatus(text, isError) {
		var $el = $('#hl-links-status');
		if (!text) {
			$el.attr('hidden', true).text('').removeClass('is-error');
			return;
		}
		$el.text(text).toggleClass('is-error', !!isError).removeAttr('hidden');
	}

	function openLinksModal($row) {
		var loc = $row.find('.hl-loc-input').val() || 'Event';
		$('#hl-links-title').text(loc + ' — Links');
		$('#hl-links-eve-id').val($row.data('eve-id'));
		$('#hl-links-host').val($row.find('.hl-fld-host').val());
		$('#hl-links-roster').val($row.find('.hl-fld-roster').val());
		$('#hl-links-reg').val($row.find('.hl-fld-reg').val());
		$('#hl-links-web').val($row.find('.hl-fld-web').val());
		$('#hl-links-email').val($row.find('.hl-fld-email').val());
		$('#hl-links-zoom-time').val($row.find('.hl-fld-zoom-time').val());
		setStatus('');
		$('#hl-links-modal').removeAttr('hidden');
		$('#hl-links-host').trigger('focus');
	}

	function closeLinksModal() {
		$('#hl-links-modal').attr('hidden', true);
	}

	function fieldVal(id) {
		return $.trim($(id).val() || '');
	}

	$(function () {
		$('.eventenddertot').each(function () {
			var $el = $(this);
			var val = $el.val();
			var opts = { timePicker: false, locale: { format: 'YYYY/MM/DD' }, parentEl: 'body' };
			var p = val ? val.split(' - ') : [];
			if (p.length === 2 && p[0].trim() && p[1].trim()) {
				opts.startDate = p[0].trim();
				opts.endDate = p[1].trim();
			}
			$el.daterangepicker(opts);
			$el.on('apply.daterangepicker', function () {
				refreshCompact($(this));
			});
		});

		$(document).on('click', 'span.hl-compact-date', function () {
			var $input = $(this).siblings('input.eventenddertot');
			var dp = $input.data('daterangepicker');
			if (dp) {
				dp.show();
			}
		});

		$('.hl-type-select').each(function () {
			syncTypeBadge($(this));
		});
		$(document).on('change', '.hl-type-select', function () {
			syncTypeBadge($(this));
		});

		$('#hl-chooseyear, #hl-choosemonth').on('change', function () {
			var yr = $('#hl-chooseyear').val();
			var mo = $('#hl-choosemonth').val();
			var url = 'admin.php?page=booking-menu&syear=' + yr;
			if (mo !== 'all') {
				url += '-' + mo;
			}
			window.location.href = url;
		});

		$('#cb-select-all-1').on('change', function () {
			$('#hl-events-table tbody input.administrator').prop('checked', this.checked);
		});

		$(document).on('click', '.hl-menu-toggle', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var $panel = $(this).closest('.hl-row-menu').find('.hl-row-menu-panel');
			var open = !$panel.attr('hidden');
			closeMenus();
			if (!open) {
				$panel.removeAttr('hidden');
				$(this).attr('aria-expanded', 'true');
			}
		});

		$(document).on('click', function () {
			closeMenus();
		});

		$(document).on('click', '.hl-links-btn', function () {
			openLinksModal($(this).closest('tr'));
		});

		$(document).on('click', '[data-hl-links-close]', function () {
			closeLinksModal();
		});

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && !$('#hl-links-modal').attr('hidden')) {
				closeLinksModal();
			}
		});

		$(document).on('click', '.hl-links-open', function () {
			var url = fieldVal('#' + $(this).attr('data-for'));
			if (url) {
				window.open(url, '_blank', 'noopener');
			}
		});

		$(document).on('click', '.hl-links-copy', function () {
			var $btn = $(this);
			var url = fieldVal('#' + $btn.attr('data-for'));
			if (!url) {
				return;
			}
			var done = function () {
				var prev = $btn.text();
				$btn.text('Copied');
				setTimeout(function () {
					$btn.text(prev);
				}, 1200);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(done);
			} else {
				done();
			}
		});

		$('#hl-links-save').on('click', function () {
			var cfg = window.hlEvents || {};
			var eveId = $('#hl-links-eve-id').val();
			var $row = $('#user-' + eveId);
			var payload = {
				action: 'hostlinks_save_event_links',
				nonce: cfg.nonce || '',
				eve_id: eveId,
				eve_host_url: fieldVal('#hl-links-host'),
				eve_roster_url: fieldVal('#hl-links-roster'),
				eve_trainer_url: fieldVal('#hl-links-reg'),
				eve_web_url: fieldVal('#hl-links-web'),
				eve_email_url: fieldVal('#hl-links-email'),
				eve_zoom_time: fieldVal('#hl-links-zoom-time')
			};
			var $save = $(this).prop('disabled', true);
			setStatus('Saving…');
			$.post(cfg.ajaxUrl || window.ajaxurl, payload)
				.done(function (res) {
					if (!res || !res.success) {
						setStatus((res && res.data && res.data.message) || 'Could not save.', true);
						return;
					}
					var d = res.data || {};
					$row.find('.hl-fld-host').val(d.eve_host_url || payload.eve_host_url);
					$row.find('.hl-fld-roster').val(d.eve_roster_url || payload.eve_roster_url);
					$row.find('.hl-fld-reg').val(d.eve_trainer_url || payload.eve_trainer_url);
					$row.find('.hl-fld-web').val(d.eve_web_url || payload.eve_web_url);
					$row.find('.hl-fld-email').val(d.eve_email_url || payload.eve_email_url);
					$row.find('.hl-fld-zoom-time').val(d.eve_zoom_time || payload.eve_zoom_time);
					closeLinksModal();
				})
				.fail(function () {
					setStatus('Could not save.', true);
				})
				.always(function () {
					$save.prop('disabled', false);
				});
		});

		if ($.fn.dataTable) {
			var table = $('#hl-events-table').DataTable({
				order: [[2, 'asc']],
				paging: true,
				lengthChange: true,
				pageLength: 25,
				searching: true,
				info: true,
				autoWidth: false,
				stateSave: true,
				dom: 't<"hl-events-pager"lip>',
				columnDefs: [
					{ targets: [0, 3, 6, 7, 8, 9, 11, 12], orderable: false }
				],
				language: {
					emptyTable: 'No events found for this period.',
					zeroRecords: 'No matching events.'
				},
				stateLoadParams: function (settings, data) {
					if (data && data.search) {
						data.search.search = '';
					}
					if (data && Array.isArray(data.columns)) {
						for (var i = 0; i < data.columns.length; i++) {
							if (data.columns[i] && data.columns[i].search) {
								data.columns[i].search.search = '';
							}
						}
					}
				}
			});

			$('#hl-events-search').on('input', function () {
				table.search(this.value).draw();
			});
		}
	});
})(jQuery);
