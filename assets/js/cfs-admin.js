/**
 * Contact Form Submissions — admin screens JS.
 *
 * Covers the "select all" checkbox on the submissions list and the
 * status-update / add-on panel actions on the submission detail screen.
 * Enqueued only on the plugin's own admin pages, see CFS_Admin::enqueue_assets().
 *
 * @package ContactFormSubmissions
 */
(function () {
	'use strict';

	/**
	 * Submissions list: "select all" checkbox toggles every row checkbox.
	 */
	function initSelectAll() {
		var selectAll = document.getElementById('cfs-select-all');
		if (!selectAll) {
			return;
		}
		selectAll.addEventListener('change', function () {
			var boxes = document.querySelectorAll('input[name="submission_ids[]"]');
			boxes.forEach(function (box) { box.checked = selectAll.checked; });
		});
	}

	/**
	 * Detail screen: inline status update via AJAX.
	 *
	 * @param {string} ajaxUrl
	 * @param {string} nonce
	 */
	function initStatusUpdate(ajaxUrl, nonce) {
		var btn = document.getElementById('cfs-save-status');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function () {
			var sel = document.getElementById('cfs-status-select');
			var msg = document.getElementById('cfs-status-msg');
			var data = new FormData();
			data.append('action', 'cfs_update_status');
			data.append('nonce', nonce);
			data.append('id', sel.dataset.id);
			data.append('status', sel.value);
			fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					msg.textContent = res.data && res.data.message ? res.data.message : '';
				});
		});
	}

	/**
	 * Detail screen: generic handler for buttons contributed by add-ons
	 * through the cfs_submission_panels filter. Every button carries its own
	 * AJAX action and payload, so add-ons ship no JavaScript at all.
	 *
	 * @param {string} ajaxUrl
	 * @param {string} nonce
	 * @param {string} networkErrorText
	 */
	function initPanelActions(ajaxUrl, nonce, networkErrorText) {
		document.querySelectorAll('.cfs-panel-action').forEach(function (button) {
			button.addEventListener('click', function () {
				var msg = button.parentNode.querySelector('.cfs-panel-action-msg');
				var setMsg = function (text, color) {
					if (!msg) { return; }
					msg.textContent = text;
					msg.style.color = color || '';
				};

				button.disabled = true;
				setMsg(button.dataset.busyLabel || '…');

				var data = new FormData();
				data.append('action', button.dataset.action);
				data.append('nonce', nonce);
				data.append('id', button.dataset.id);

				if (button.dataset.payload) {
					try {
						var extra = JSON.parse(button.dataset.payload);
						Object.keys(extra).forEach(function (key) {
							data.append(key, extra[key]);
						});
					} catch (e) { /* malformed payload — send without it */ }
				}

				fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						setMsg(
							(res.data && res.data.message) ? res.data.message : '',
							res.success ? '#00a32a' : '#d63638'
						);
						if (res.success && button.dataset.reload === '1') {
							setTimeout(function () { window.location.reload(); }, 1200);
						} else {
							button.disabled = false;
						}
					})
					.catch(function () {
						button.disabled = false;
						setMsg(networkErrorText, '#d63638');
					});
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var cfg = (typeof cfsAdminData !== 'undefined') ? cfsAdminData : {};

		initSelectAll();
		initStatusUpdate(cfg.ajaxUrl || '', cfg.nonce || '');
		initPanelActions(cfg.ajaxUrl || '', cfg.nonce || '', (cfg.i18n && cfg.i18n.networkError) || 'Network error.');
	});
}());
