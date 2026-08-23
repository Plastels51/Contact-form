/**
 * Form editor: tabs, tag generator, live preview.
 *
 * Vanilla JS on purpose — the plugin ships no front-end or admin libraries.
 *
 * @package ContactFormSubmissions
 */
(function () {
	'use strict';

	var data = (typeof cfsEditor !== 'undefined') ? cfsEditor : { types: {}, icons: [], i18n: {} };
	var i18n = data.i18n || {};

	/**
	 * @param {string} key
	 * @param {string} fallback
	 * @returns {string}
	 */
	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	/* ═══════════════════════════════════════════════════════════
	   TABS
	   ═══════════════════════════════════════════════════════════ */

	function initTabs(root) {
		var buttons = root.querySelectorAll('.cfs-tabs .nav-tab');
		var panels  = root.querySelectorAll('.cfs-tab-panel');
		var input   = root.querySelector('.cfs-active-tab-input');

		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				var target = button.getAttribute('data-tab');

				buttons.forEach(function (other) {
					other.classList.toggle('nav-tab-active', other === button);
				});

				panels.forEach(function (panel) {
					panel.hidden = panel.getAttribute('data-panel') !== target;
				});

				// Carried through the save so the editor reopens where it was.
				if (input) {
					input.value = target;
				}
			});
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   TAG GENERATOR
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * Escape a value for use inside a double-quoted tag attribute.
	 *
	 * @param {string} value
	 * @returns {string}
	 */
	function escapeAttr(value) {
		return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
	}

	/**
	 * Names already used in the template, so the generator can refuse a clash.
	 *
	 * @param {string} template
	 * @returns {string[]}
	 */
	function usedNames(template) {
		var names = [];
		var re    = /\[\s*([a-z][a-z0-9_-]*)\*?\s+([a-z][a-z0-9_-]*)/gi;
		var match;

		while ((match = re.exec(template)) !== null) {
			names.push(match[2].toLowerCase());
		}

		return names;
	}

	/**
	 * Build the control set for one field type.
	 *
	 * @param {string} type
	 * @returns {Array<Object>}
	 */
	function controlsFor(type) {
		var descriptor = data.types[type] || { supports: [], options: false, submits: true };
		var supports   = descriptor.supports || [];
		var controls   = [];

		function has(name) {
			return supports.indexOf(name) !== -1;
		}

		if (descriptor.submits && type !== 'step') {
			controls.push({ key: '__name', label: t('name', 'Имя поля'), type: 'text', required: true });
		}

		if (type === 'submit') {
			controls.push({ key: 'text', label: t('text', 'Текст кнопки'), type: 'text' });
		} else if (has('label') || type === 'step') {
			controls.push({ key: 'label', label: t('label', 'Метка'), type: 'text' });
		}

		if (descriptor.submits && type !== 'step') {
			controls.push({ key: '__required', label: t('required', 'Обязательное'), type: 'checkbox' });
		}

		if (descriptor.options) {
			controls.push({ key: 'options', label: t('options', 'Варианты'), type: 'text', placeholder: 'Да:yes,Нет:no' });
		}

		if (has('placeholder')) {
			controls.push({ key: 'placeholder', label: t('placeholder', 'Подсказка в поле'), type: 'text' });
		}

		if (has('icon') || type === 'submit') {
			controls.push({ key: type === 'submit' ? 'icon_after' : 'icon', label: t('icon', 'Иконка'), type: 'icon' });
		}

		['min', 'max', 'step', 'rows', 'maxlength'].forEach(function (key) {
			if (has(key)) {
				controls.push({ key: key, label: key, type: 'text' });
			}
		});

		if (has('value')) {
			controls.push({ key: 'value', label: 'value', type: 'text' });
		}

		if (has('source')) {
			controls.push({ key: 'source', label: 'source', type: 'text', placeholder: 'query:utm_source' });
		}

		if (has('help')) {
			controls.push({ key: 'help', label: t('help', 'Подсказка под полем'), type: 'text' });
		}

		if (has('width')) {
			controls.push({
				key: 'width',
				label: t('width', 'Ширина'),
				type: 'select',
				options: ['', '1/2', '1/3', '2/3', '1/4', '3/4'],
			});
		}

		return controls;
	}

	/**
	 * Build the tag string from the dialog's values.
	 *
	 * @param {string} type
	 * @param {Object} values
	 * @returns {string}
	 */
	function buildTag(type, values) {
		var out = '[' + type;

		if (values.__required) {
			out += '*';
		}
		if (values.__name) {
			out += ' ' + values.__name;
		}

		Object.keys(values).forEach(function (key) {
			if (key.indexOf('__') === 0) {
				return;
			}
			var value = values[key];
			if (value === '' || value === null || typeof value === 'undefined') {
				return;
			}
			out += ' ' + key + '="' + escapeAttr(value) + '"';
		});

		return out + ']';
	}

	/**
	 * Insert text at the caret, keeping the caret after it.
	 *
	 * @param {HTMLTextAreaElement} textarea
	 * @param {string}              text
	 */
	function insertAtCaret(textarea, text) {
		var start = textarea.selectionStart || 0;
		var end   = textarea.selectionEnd || 0;
		var value = textarea.value;

		textarea.value = value.slice(0, start) + text + value.slice(end);
		textarea.focus();
		textarea.selectionStart = start + text.length;
		textarea.selectionEnd   = textarea.selectionStart;
	}

	function initTagGenerator(root) {
		var textarea = root.querySelector('#cfs-template');
		var bar      = root.querySelector('.cfs-tag-bar');

		if (!textarea || !bar) {
			return;
		}

		var dialog = document.createElement('div');
		dialog.className = 'cfs-tag-dialog';
		dialog.hidden = true;
		bar.parentNode.insertBefore(dialog, bar.nextSibling);

		function close() {
			dialog.hidden = true;
			dialog.innerHTML = '';
		}

		function open(type) {
			var controls = controlsFor(type);
			dialog.innerHTML = '';
			dialog.hidden = false;

			var title = document.createElement('h4');
			title.textContent = (data.types[type] && data.types[type].label) ? data.types[type].label : type;
			dialog.appendChild(title);

			var grid = document.createElement('div');
			grid.className = 'cfs-tag-grid';
			dialog.appendChild(grid);

			var inputs = {};

			controls.forEach(function (control) {
				var wrap  = document.createElement('label');
				wrap.className = 'cfs-tag-field';

				var span = document.createElement('span');
				span.textContent = control.label;
				wrap.appendChild(span);

				var input;

				if (control.type === 'checkbox') {
					input = document.createElement('input');
					input.type = 'checkbox';
					wrap.classList.add('cfs-tag-field--check');
				} else if (control.type === 'select') {
					input = document.createElement('select');
					(control.options || []).forEach(function (option) {
						var el = document.createElement('option');
						el.value = option;
						el.textContent = option === '' ? '—' : option;
						input.appendChild(el);
					});
				} else if (control.type === 'icon') {
					input = document.createElement('select');
					var empty = document.createElement('option');
					empty.value = '';
					empty.textContent = '—';
					input.appendChild(empty);
					(data.icons || []).forEach(function (icon) {
						var el = document.createElement('option');
						el.value = icon;
						el.textContent = icon;
						input.appendChild(el);
					});
				} else {
					input = document.createElement('input');
					input.type = 'text';
					if (control.placeholder) {
						input.placeholder = control.placeholder;
					}
				}

				inputs[control.key] = input;
				wrap.appendChild(input);
				grid.appendChild(wrap);
			});

			var error = document.createElement('p');
			error.className = 'cfs-tag-error';
			error.hidden = true;
			dialog.appendChild(error);

			var actions = document.createElement('div');
			actions.className = 'cfs-tag-actions';

			var insert = document.createElement('button');
			insert.type = 'button';
			insert.className = 'button button-primary';
			insert.textContent = t('insert', 'Вставить');

			var cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = t('cancel', 'Отмена');

			actions.appendChild(insert);
			actions.appendChild(cancel);
			dialog.appendChild(actions);

			cancel.addEventListener('click', close);

			insert.addEventListener('click', function () {
				var values = {};

				Object.keys(inputs).forEach(function (key) {
					var input = inputs[key];
					values[key] = (input.type === 'checkbox') ? input.checked : input.value.trim();
				});

				if (typeof values.__name !== 'undefined') {
					var name = String(values.__name || '').toLowerCase();

					if (name && !/^[a-z][a-z0-9_-]*$/.test(name)) {
						error.textContent = t('nameInvalid', 'Некорректное имя поля.');
						error.hidden = false;
						return;
					}
					if (name && usedNames(textarea.value).indexOf(name) !== -1) {
						error.textContent = t('nameTaken', 'Такое имя уже занято.');
						error.hidden = false;
						return;
					}

					values.__name = name;
				}

				insertAtCaret(textarea, buildTag(type, values) + '\n');
				close();
			});

			var first = dialog.querySelector('input, select');
			if (first) {
				first.focus();
			}
		}

		bar.querySelectorAll('.cfs-tag-btn').forEach(function (button) {
			button.addEventListener('click', function () {
				open(button.getAttribute('data-type'));
			});
		});

		dialog.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				close();
			}
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   PREVIEW
	   ═══════════════════════════════════════════════════════════ */

	function initPreview(root) {
		var button   = root.querySelector('.cfs-preview-btn');
		var area     = root.querySelector('.cfs-preview-area');
		var textarea = root.querySelector('#cfs-template');

		if (!button || !area || !textarea) {
			return;
		}

		button.addEventListener('click', function () {
			var payload = new FormData();
			payload.set('action', 'cfs_preview_form');
			payload.set('nonce', data.nonce || '');
			payload.set('template', textarea.value);

			// Preview with the presentation settings currently on screen, not
			// the ones last saved.
			root.querySelectorAll('[name^="cfs_settings["]').forEach(function (input) {
				if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) {
					return;
				}
				payload.set(input.name.replace(/^cfs_settings\[/, 'settings['), input.value);
			});

			button.disabled = true;
			area.classList.add('is-loading');

			fetch(data.ajaxUrl, { method: 'POST', body: payload, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					button.disabled = false;
					area.classList.remove('is-loading');

					if (!res || !res.success) {
						area.textContent = t('previewFailed', 'Не удалось построить предпросмотр.');
						return;
					}

					area.innerHTML = res.data.html || ('<p>' + t('noFields', 'В шаблоне нет полей.') + '</p>');
					renderPreviewErrors(area, res.data.errors || []);
				})
				.catch(function () {
					button.disabled = false;
					area.classList.remove('is-loading');
					area.textContent = t('previewFailed', 'Не удалось построить предпросмотр.');
				});
		});
	}

	/**
	 * @param {HTMLElement} area
	 * @param {Array}       errors
	 */
	function renderPreviewErrors(area, errors) {
		if (!errors.length) {
			return;
		}

		var list = document.createElement('ul');
		list.className = 'cfs-preview-errors';

		errors.forEach(function (error) {
			var item = document.createElement('li');
			item.className = 'is-' + (error.level || 'error');
			item.textContent = (error.line ? ('строка ' + error.line + ': ') : '') + error.message;
			list.appendChild(item);
		});

		area.appendChild(list);
	}

	/* ═══════════════════════════════════════════════════════════
	   SMALL HELPERS
	   ═══════════════════════════════════════════════════════════ */

	function initCopyShortcode() {
		document.querySelectorAll('.cfs-shortcode').forEach(function (el) {
			el.addEventListener('click', function () {
				var text = el.textContent.trim();

				var done = function () {
					el.classList.add('is-copied');
					setTimeout(function () { el.classList.remove('is-copied'); }, 1200);
				};

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done, function () {});
					return;
				}

				// Older browsers: select the text so Ctrl+C works.
				var range = document.createRange();
				range.selectNodeContents(el);
				var selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange(range);
			});
		});
	}

	function initConfirmLinks() {
		document.querySelectorAll('.cfs-confirm').forEach(function (link) {
			link.addEventListener('click', function (e) {
				// eslint-disable-next-line no-alert
				if (!window.confirm(link.getAttribute('data-confirm') || '?')) {
					e.preventDefault();
				}
			});
		});
	}

	function initPagePicker(root) {
		root.querySelectorAll('.cfs-page-picker').forEach(function (select) {
			select.addEventListener('change', function () {
				var target = document.getElementById(select.getAttribute('data-target'));
				if (target && select.value) {
					target.value = select.value;
				}
			});
		});
	}

	function initHistory(root) {
		var textarea = root.querySelector('#cfs-template');
		if (!textarea) {
			return;
		}

		root.querySelectorAll('.cfs-restore').forEach(function (button) {
			button.addEventListener('click', function () {
				textarea.value = button.getAttribute('data-template') || '';
				textarea.focus();
			});
		});
	}

	function initMailTags() {
		document.querySelectorAll('.cfs-mailtag').forEach(function (tag) {
			tag.addEventListener('click', function () {
				var active = document.activeElement;
				if (active && (active.tagName === 'TEXTAREA' || active.tagName === 'INPUT')) {
					insertAtCaret(active, tag.textContent);
				}
			});
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   BOOT
	   ═══════════════════════════════════════════════════════════ */

	document.addEventListener('DOMContentLoaded', function () {
		initCopyShortcode();
		initConfirmLinks();

		var editor = document.querySelector('.cfs-editor');
		if (!editor) {
			return;
		}

		initTabs(editor);
		initTagGenerator(editor);
		initPreview(editor);
		initPagePicker(editor);
		initHistory(editor);
		initMailTags();
	});
}());
