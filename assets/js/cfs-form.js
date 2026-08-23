/**
 * Contact Form Submissions — front-end JS.
 *
 * Features:
 *  - Floating label: label is positioned inside the input (replacing the
 *    placeholder). When the field is focused or has a value, the parent
 *    .cfs-field receives the class "focused" and the label animates upward.
 *  - Phone mask +7 (___) ___-__-__ (pure JS, no libs).
 *    Cursor tracked by digit-index — survives mid-string edits.
 *  - Per-field blur validation with aria-invalid + aria-describedby.
 *  - AJAX submission with aria-busy on the submit button.
 *  - Success message / form reset / optional redirect.
 *  - Dialog (modal) container support via <dialog> element.
 *
 * @package ContactFormSubmissions
 */
(function () {
	'use strict';

	/* ═══════════════════════════════════════════════════════════
	   DEBUG LOGGER
	   All log helpers are no-ops when cfsData.debug !== true,
	   so there is zero console noise in production.
	   Enable via: Заявки → Настройки → Режим отладки.
	   ═══════════════════════════════════════════════════════════ */
	/* wp_localize_script casts booleans to strings: true → "1", false → "" */
	var debug = (typeof cfsData !== 'undefined' && (cfsData.debug === true || cfsData.debug === '1'));

	function log() {
		if (!debug) { return; }
		var a = Array.prototype.slice.call(arguments);
		a.unshift('[CFS]');
		console.log.apply(console, a);
	}

	function logWarn() {
		if (!debug) { return; }
		var a = Array.prototype.slice.call(arguments);
		a.unshift('[CFS WARN]');
		console.warn.apply(console, a);
	}

	function logError() {
		if (!debug) { return; }
		var a = Array.prototype.slice.call(arguments);
		a.unshift('[CFS ERROR]');
		console.error.apply(console, a);
	}

	function logGroup(label) {
		if (!debug) { return; }
		console.group('[CFS] ' + label);
	}

	function logGroupEnd() {
		if (!debug) { return; }
		console.groupEnd();
	}

	/* ═══════════════════════════════════════════════════════════
	   SHARED HELPERS
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * Announce that a modal opened or closed.
	 *
	 * Themes that lock page scrolling while a modal is up (smooth-scroll
	 * libraries, scroll-jacking wrappers) can listen for this instead of the
	 * plugin knowing about them:
	 *
	 *   document.addEventListener('cfs:dialog-toggle', function (e) {
	 *       myScroller.toggle();   // e.detail.dialog is the <dialog> element
	 *   });
	 *
	 * The legacy global locomotiveToggleScroll() is still called when present,
	 * so existing sites keep working without changes.
	 *
	 * @param {HTMLElement|null} dialog
	 */
	function notifyDialogToggle(dialog) {
		if (typeof locomotiveToggleScroll === 'function') {
			locomotiveToggleScroll();
			log('locomotiveToggleScroll() called');
		}

		document.dispatchEvent(new CustomEvent('cfs:dialog-toggle', {
			detail: { dialog: dialog || null }
		}));
	}

	/**
	 * Walk up the DOM and return the nearest .cfs-field ancestor, or null.
	 *
	 * @param {HTMLElement} el
	 * @returns {HTMLElement|null}
	 */
	function getFieldWrap(el) {
		var node = el.parentNode;
		while (node && node !== document.body) {
			if (node.classList && node.classList.contains('cfs-field')) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	/**
	 * Find the .cfs-error span for a field.
	 *
	 * Primary lookup: element pointed to by aria-describedby (takes the first
	 * space-separated ID, as per ARIA spec).
	 * Fallback: first .cfs-error inside the nearest .cfs-field wrapper.
	 * This fallback handles the rare case where getElementById returns null
	 * (e.g. form rendered inside a shadow root, or browser quirk with IDs
	 * containing certain Unicode characters from sanitize_key output).
	 *
	 * @param {HTMLElement} field
	 * @returns {HTMLElement|null}
	 */
	function findErrorSpan(field) {
		var describedBy = field.getAttribute('aria-describedby');
		if (describedBy) {
			// aria-describedby may be a space-separated list; take only the first ID.
			var firstId = describedBy.split(' ')[0];
			var byId    = document.getElementById(firstId);
			if (byId) {
				log('findErrorSpan: found by ID "' + firstId + '"');
				return byId;
			}
			logWarn('findErrorSpan: getElementById failed for "' + firstId + '", using fallback');
		}
		// Fallback: search by class within the .cfs-field wrapper.
		var wrap = getFieldWrap(field);
		if (wrap) {
			var spanFallback = wrap.querySelector('.cfs-error');
			if (spanFallback) {
				log('findErrorSpan: found via .cfs-error fallback for', field.name || field.id || field);
			} else {
				logWarn('findErrorSpan: .cfs-error not found inside wrapper for', field.name || field.id || field);
			}
			return spanFallback;
		}
		logWarn('findErrorSpan: no .cfs-field wrapper for', field.name || field.id || field);
		return null;
	}

	/* ═══════════════════════════════════════════════════════════
	   FLOATING LABEL
	   Manages the "focused" class on .cfs-field.
	   Applies to every field with a visible text/select/textarea input.
	   Excluded: checkbox (.cfs-field--checkbox), submit (.cfs-field--submit).
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * Return true when the field has a non-empty value.
	 *
	 * @param {HTMLElement} field
	 * @returns {boolean}
	 */
	function fieldHasValue(field) {
		if (field.tagName === 'SELECT') {
			return field.value !== '';
		}
		return field.value.trim() !== '';
	}

	/**
	 * Add or remove the "focused" class on the .cfs-field wrapper.
	 * "focused" = label should be at the top (field is focused OR has a value).
	 *
	 * @param {HTMLElement} fieldWrap .cfs-field element.
	 * @param {boolean}     state     true = add, false = remove.
	 */
	function setFloatState(fieldWrap, state) {
		if (state) {
			fieldWrap.classList.add('focused');
		} else {
			fieldWrap.classList.remove('focused');
		}
	}

	/**
	 * Attach floating-label event listeners to all eligible fields in a form.
	 * Also runs an initial check so pre-filled / autofilled fields float correctly.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initFloatingLabels(form) {
		form.querySelectorAll('.cfs-field').forEach(function (fieldWrap) {
			/*
			 * Only apply to text-like inputs, textareas and selects.
			 * Hidden inputs and checkboxes are excluded by the selector.
			 */
			var field = fieldWrap.querySelector(
				'input:not([type="hidden"]):not([type="checkbox"]), textarea, select'
			);
			if (!field) {
				return;
			}

			function update() {
				setFloatState(
					fieldWrap,
					document.activeElement === field || fieldHasValue(field)
				);
			}

			field.addEventListener('focus', function () {
				setFloatState(fieldWrap, true);
			});

			field.addEventListener('blur', function () {
				// Keep label floated when the field retains a value after leaving.
				setFloatState(fieldWrap, fieldHasValue(field));
			});

			field.addEventListener('input',  update);
			field.addEventListener('change', update);

			// Initial state: covers pre-filled values and browser autofill.
			update();
		});

		/*
		 * Autofill detection via animationstart.
		 * Browsers apply :-webkit-autofill pseudo-class asynchronously, so we
		 * re-check a tick later to pick up autofilled fields.
		 */
		form.querySelectorAll('.cfs-field').forEach(function (fieldWrap) {
			var field = fieldWrap.querySelector(
				'input:not([type="hidden"]):not([type="checkbox"]), textarea, select'
			);
			if (!field) {
				return;
			}
			field.addEventListener('animationstart', function (e) {
				if (e.animationName === 'cfsAutofillStart') {
					setFloatState(fieldWrap, true);
				}
			});
		});
	}

	/**
	 * Reset all floating-label states in a form (called after form.reset()).
	 *
	 * @param {HTMLFormElement} form
	 */
	function resetFloatingLabels(form) {
		form.querySelectorAll('.cfs-field').forEach(function (fieldWrap) {
			fieldWrap.classList.remove('focused');
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   PHONE MASK  +7 (XXX) XXX-XX-XX
	   Pure JS. Cursor is tracked by digit-index (not char offset)
	   so it stays correct through insertions, deletions, and
	   reformatting that changes the overall string length.
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * Count digits in str[0 .. pos) (exclusive upper bound).
	 *
	 * @param {string} str
	 * @param {number} pos
	 * @returns {number}
	 */
	function digitsBeforePos(str, pos) {
		var count = 0;
		var limit = Math.min(pos, str.length);
		for (var i = 0; i < limit; i++) {
			if (str[i] >= '0' && str[i] <= '9') {
				count++;
			}
		}
		return count;
	}

	/**
	 * Return the character index right AFTER the n-th digit (0-based) in str.
	 * Returns str.length when fewer than n+1 digits exist.
	 *
	 * @param {string} str
	 * @param {number} n  0-based digit index.
	 * @returns {number}
	 */
	function posAfterNthDigit(str, n) {
		var count = 0;
		for (var i = 0; i < str.length; i++) {
			if (str[i] >= '0' && str[i] <= '9') {
				if (count === n) {
					return i + 1;
				}
				count++;
			}
		}
		return str.length;
	}

	/**
	 * Format a raw string as +7 (XXX) XXX-XX-XX.
	 * Leading 8 is converted to 7. Missing country code → 7 prepended.
	 * Returns '' when the string contains no digits.
	 *
	 * @param {string} raw
	 * @returns {string}
	 */
	function formatPhoneValue(raw) {
		var digits = raw.replace(/\D/g, '');

		if (digits.length === 0) {
			return '';
		}

		// Normalise country code to 7.
		if (digits.charAt(0) === '8') {
			digits = '7' + digits.slice(1);
		} else if (digits.charAt(0) !== '7') {
			digits = '7' + digits;
		}

		// Cap at 11 digits (country code + 10 local).
		digits = digits.slice(0, 11);

		var d      = digits.slice(1); // local digits (without the leading 7)
		var result = '+7';

		if (d.length > 0) { result += ' (' + d.slice(0, 3); }
		if (d.length >= 3) { result += ') ' + d.slice(3, 6); }
		if (d.length >= 6) { result += '-'  + d.slice(6, 8); }
		if (d.length >= 8) { result += '-'  + d.slice(8, 10); }

		return result;
	}

	/**
	 * Attach phone mask behaviour to an <input> element.
	 *
	 * @param {HTMLInputElement} input
	 */
	function applyPhoneMask(input) {
		// Prevents the 'input' event from running after we already handled
		// a Backspace manually in 'keydown'.
		var skipNextInput = false;

		/* ── KEYDOWN: smart Backspace — jumps over formatting chars ── */
		input.addEventListener('keydown', function (e) {
			if (e.key !== 'Backspace') {
				return;
			}

			var pos = input.selectionStart;
			var end = input.selectionEnd;

			// Text is selected — let the browser delete it; 'input' will reformat.
			if (pos !== end) {
				return;
			}

			var val    = input.value;
			var newPos = pos;

			// Step backwards past formatting characters ( , (, ), - , space ).
			while (newPos > 0 && (val[newPos - 1] < '0' || val[newPos - 1] > '9')) {
				newPos--;
			}

			// Cursor was already right after a digit → normal browser backspace.
			if (newPos === pos) {
				return;
			}

			e.preventDefault();
			skipNextInput = true;

			if (newPos === 0) {
				// Nothing left to delete.
				return;
			}

			/*
			 * D  = digits in val[0 .. newPos)
			 * We delete val[newPos-1] (a digit), so D-1 digits remain before cursor.
			 * After reformatting we place the cursor after the (D-1)-th digit,
			 * i.e. posAfterNthDigit(formatted, D-2)  [0-based index = D-2].
			 */
			var D         = digitsBeforePos(val, newPos);
			var dAfter    = D - 1;
			var newVal    = val.slice(0, newPos - 1) + val.slice(newPos);
			var formatted = formatPhoneValue(newVal);
			input.value   = formatted;

			var cursorPos = dAfter >= 1
				? posAfterNthDigit(formatted, dAfter - 1)
				: 0;
			input.setSelectionRange(cursorPos, cursorPos);
		});

		/* ── INPUT: reformat on every change, restore cursor by digit-index ── */
		input.addEventListener('input', function () {
			if (skipNextInput) {
				skipNextInput = false;
				return;
			}

			var pos       = input.selectionStart;
			var val       = input.value;

			// D digits were before the cursor in the unformatted string.
			var D         = digitsBeforePos(val, pos);
			var formatted = formatPhoneValue(val);
			input.value   = formatted;

			// Place cursor after the D-th digit (1-indexed) = index D-1 (0-based).
			var cursorPos = D >= 1
				? posAfterNthDigit(formatted, D - 1)
				: 0;
			input.setSelectionRange(cursorPos, cursorPos);
		});

		/* ── PASTE: format the pasted text and move cursor to end ── */
		input.addEventListener('paste', function (e) {
			e.preventDefault();
			var pasted    = (e.clipboardData || window.clipboardData).getData('text');
			var formatted = formatPhoneValue(pasted);
			input.value   = formatted;
			input.setSelectionRange(formatted.length, formatted.length);
		});

		/* ── FOCUS: seed '+7 (' so the user sees the mask immediately ── */
		input.addEventListener('focus', function () {
			if (!input.value) {
				input.value = '+7 (';
				input.setSelectionRange(4, 4);
			}
		});

		/* ── BLUR: clear if only the country-code prefix remains ── */
		input.addEventListener('blur', function () {
			var digits = input.value.replace(/\D/g, '');
			// Only country code present (≤ 1 digit) — treat as empty.
			if (digits.length <= 1) {
				input.value = '';
			}
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   ARIA + ERROR HELPERS
	   Both helpers also propagate an error class to the .cfs-field
	   wrapper so CSS can style the floated label in error colour.
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * Mark a field invalid: CSS class, aria-invalid, error span text,
	 * and cfs-field--has-error on the parent wrapper.
	 *
	 * Uses findErrorSpan() which tries aria-describedby first, then
	 * falls back to a DOM-proximity search for .cfs-error inside
	 * the nearest .cfs-field wrapper — ensuring the message is always
	 * displayed even when getElementById returns null (browser quirk,
	 * duplicate IDs on the page, etc.).
	 *
	 * @param {HTMLElement} field
	 * @param {string}      message
	 */
	function setFieldError(field, message) {
		log('setFieldError:', field.name || field.id || field, '→', message);
		field.classList.add('cfs-input--error');
		field.setAttribute('aria-invalid', 'true');

		var wrap = getFieldWrap(field);
		if (wrap) {
			wrap.classList.add('cfs-field--has-error');
		}

		var span = findErrorSpan(field);
		if (span) {
			span.textContent = message;
		} else {
			logWarn('setFieldError: no error span — message lost for field', field.name || field.id || field);
		}
	}

	/**
	 * Clear error state from a field.
	 *
	 * @param {HTMLElement} field
	 */
	function clearFieldError(field) {
		field.classList.remove('cfs-input--error');
		field.removeAttribute('aria-invalid');

		var wrap = getFieldWrap(field);
		if (wrap) {
			wrap.classList.remove('cfs-field--has-error');
		}

		var span = findErrorSpan(field);
		if (span) {
			span.textContent = '';
		}
	}

	/* ═══════════════════════════════════════════════════════════
	   VALIDATION
	   ═══════════════════════════════════════════════════════════ */

	var i18n = (typeof cfsData !== 'undefined' && cfsData.i18n) ? cfsData.i18n : {};

	/*
	 * Unicode letter class for name validation. Built at runtime because a
	 * literal /\p{L}/u is a *parse-time* error in engines without ES2018
	 * property escapes — that would kill the whole script, not just this rule.
	 */
	var LETTERS_RE;
	try {
		LETTERS_RE = new RegExp("^[\\p{L}\\s\\-']+$", 'u');
	} catch (e) {
		LETTERS_RE = /^[A-Za-zА-ЯЁа-яё\s\-']+$/;
	}

	/**
	 * Read the compiled configuration the form carries in data-cfs-config.
	 * Parsed once per form and cached on the element.
	 *
	 * @param {HTMLFormElement} form
	 * @returns {Object}
	 */
	function getConfig(form) {
		if (form._cfsConfig) {
			return form._cfsConfig;
		}

		var config = { fields: {}, steps: [], after: {} };
		var raw    = form.getAttribute('data-cfs-config');

		if (raw) {
			try {
				config = JSON.parse(raw);
			} catch (err) {
				logError('Cannot parse data-cfs-config:', err);
			}
		}

		config.fields = config.fields || {};
		config.steps  = config.steps  || [];
		config.after  = config.after  || {};

		form._cfsConfig = config;
		return config;
	}

	/**
	 * Field name of a control: data-cfs-field, or parsed out of name="cfs[x]".
	 *
	 * @param {HTMLElement} el
	 * @returns {string}
	 */
	function fieldNameOf(el) {
		if (!el) {
			return '';
		}
		if (el.dataset && el.dataset.cfsField) {
			return el.dataset.cfsField;
		}
		var match = /^cfs\[([^\]]+)\]/.exec(el.name || '');
		return match ? match[1] : '';
	}

	/**
	 * Config entry describing one control, or null when there is none.
	 *
	 * @param {HTMLElement} el
	 * @returns {Object|null}
	 */
	function fieldConfig(el) {
		var form = el.form || (el.closest ? el.closest('.cfs-form') : null);
		if (!form) {
			return null;
		}
		var name = fieldNameOf(el);
		return name ? (getConfig(form).fields[name] || null) : null;
	}

	/**
	 * @param {Object|null} cfg
	 * @param {string}      rule
	 * @returns {boolean}
	 */
	function hasRule(cfg, rule) {
		return !!(cfg && cfg.rules && cfg.rules.indexOf(rule) !== -1);
	}

	/**
	 * Validate one field and return an error string, or '' when valid.
	 *
	 * Rules come from the form's compiled schema rather than from the field's
	 * name, so a field called "your-phone" is validated as a phone because the
	 * template said so — not because of what it happens to be called.
	 *
	 * @param {HTMLElement} field
	 * @returns {string}
	 */
	function validateSingleField(field) {
		var cfg        = fieldConfig(field);
		var isCheckbox = field.type === 'checkbox';
		var value      = isCheckbox ? (field.checked ? '1' : '') : String(field.value || '').trim();
		var custom     = (cfg && cfg.error) ? cfg.error : '';
		var required   = cfg ? !!cfg.required : field.getAttribute('aria-required') === 'true';

		if (required && !value) {
			return custom || i18n.required || 'Обязательное поле';
		}

		// Optional and empty — nothing else to check.
		if (!value) {
			return '';
		}

		if (hasRule(cfg, 'email') || field.type === 'email') {
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
				return custom || i18n.invalid_email || 'Некорректный email';
			}
		}

		if (hasRule(cfg, 'phone')) {
			var digits = value.replace(/\D/g, '');
			if (digits.length < 10 || digits.length > 11) {
				return custom || i18n.invalid_phone || 'Некорректный номер телефона';
			}
		}

		if (hasRule(cfg, 'letters')) {
			if (!LETTERS_RE.test(value)) {
				return custom || i18n.invalid_name || 'Допустимы только буквы, дефис и пробел.';
			}
		}

		if (cfg && cfg.maxlength && value.length > parseInt(cfg.maxlength, 10)) {
			return custom || (i18n.too_long || 'Слишком длинное значение.');
		}

		if (cfg && cfg.minlength && value.length < parseInt(cfg.minlength, 10)) {
			return custom || (i18n.too_short || 'Слишком короткое значение.');
		}

		// Browser constraint validation covers pattern, type, min/max and step;
		// the config only decides which wording fits the field.
		var validity = field.validity;
		if (!validity || validity.valid) {
			return '';
		}

		if (validity.patternMismatch) {
			if (hasRule(cfg, 'phone')) {
				return custom || i18n.invalid_phone || 'Некорректный номер телефона';
			}
			if (hasRule(cfg, 'email')) {
				return custom || i18n.invalid_email || 'Некорректный email';
			}
			if (hasRule(cfg, 'letters')) {
				return custom || i18n.invalid_name || 'Допустимы только буквы, дефис и пробел.';
			}
			return custom || i18n.error_general || 'Некорректное значение.';
		}

		if (validity.typeMismatch) {
			if (field.type === 'url') {
				return custom || i18n.invalid_url || 'Введите корректный URL (например, https://...).';
			}
			if (field.type === 'email') {
				return custom || i18n.invalid_email || 'Некорректный email';
			}
		}

		if (field.type === 'date') {
			if (validity.rangeUnderflow) {
				return custom || (i18n.date_min || 'Дата не может быть раньше ') + (field.min || '');
			}
			if (validity.rangeOverflow) {
				return custom || (i18n.date_max || 'Дата не может быть позже ') + (field.max || '');
			}
			if (validity.badInput) {
				return custom || i18n.invalid_date || 'Некорректная дата.';
			}
		}

		if (field.type === 'number') {
			if (validity.rangeUnderflow) {
				return custom || (i18n.num_min || 'Минимальное значение: ') + (field.min || '');
			}
			if (validity.rangeOverflow) {
				return custom || (i18n.num_max || 'Максимальное значение: ') + (field.max || '');
			}
			if (validity.stepMismatch) {
				return custom || i18n.num_step || 'Значение не соответствует шагу.';
			}
			if (validity.badInput) {
				return custom || i18n.invalid_number || 'Введите числовое значение.';
			}
		}

		return '';
	}

	/**
	 * Validate every interactive field inside a scope (form or a single step).
	 * Returns { valid, firstError } without scrolling/focusing — callers decide.
	 *
	 * @param {HTMLElement} root
	 * @param {boolean}     verbose  Emit per-field debug logs.
	 * @returns {{ valid: boolean, firstError: HTMLElement|null }}
	 */
	function validateScope(root, verbose) {
		var valid      = true;
		var firstError = null;

		var inputs       = root.querySelectorAll('.cfs-input, .cfs-checkbox');
		var multichecks  = root.querySelectorAll('.cfs-multicheck-fieldset');
		var multiReq     = root.querySelectorAll('.cfs-multicheck-fieldset[data-required="true"]');
		var radioReq     = root.querySelectorAll('.cfs-field--radio[data-required="true"]');

		inputs.forEach(clearFieldError);
		multichecks.forEach(function (fs) {
			fs.classList.remove('cfs-field--has-error');
			var sp = fs.querySelector('.cfs-error');
			if (sp) { sp.textContent = ''; }
		});

		inputs.forEach(function (field) {
			var msg = validateSingleField(field);
			if (msg) {
				setFieldError(field, msg);
				if (verbose) { log('Field invalid:', field.name, '→', msg); }
				if (!firstError) { firstError = field; }
				valid = false;
			}
		});

		multiReq.forEach(function (fs) {
			var checked = fs.querySelectorAll('.cfs-multicheck-input:checked');
			if (checked.length === 0) {
				var msg = i18n.select_one || 'Выберите хотя бы один вариант.';
				var sp  = fs.querySelector('.cfs-error');
				fs.classList.add('cfs-field--has-error');
				if (sp) { sp.textContent = msg; }
				if (verbose) { log('Multicheck required:', fs.dataset.field, '→', msg); }
				if (!firstError) { firstError = fs; }
				valid = false;
			}
		});

		radioReq.forEach(function (fs) {
			fs.classList.remove('cfs-field--has-error');
			var sp = fs.querySelector('.cfs-error');
			if (sp) { sp.textContent = ''; }
			var checked = fs.querySelectorAll('.cfs-radio:checked');
			if (checked.length === 0) {
				var msg = i18n.required || 'Обязательное поле';
				fs.classList.add('cfs-field--has-error');
				if (sp) { sp.textContent = msg; }
				if (verbose) { log('Radio required:', fs.dataset.field, '→', msg); }
				if (!firstError) { firstError = fs; }
				valid = false;
			}
		});

		return { valid: valid, firstError: firstError };
	}

	/**
	 * Validate the whole form. Scrolls to and focuses the first error.
	 *
	 * @param {HTMLFormElement} form
	 * @returns {boolean}
	 */
	function validateForm(form) {
		logGroup('Client validation · form#' + (form.dataset.formId || '?'));

		var result     = validateScope(form, true);
		var firstError = result.firstError;

		if (firstError) {
			// Multi-step: if the first error is inside a hidden step, reveal it.
			var stepState = form._cfsStepState;
			if (stepState) {
				var stepEl = firstError.closest ? firstError.closest('.cfs-step') : null;
				if (stepEl) {
					var idx = parseInt(stepEl.getAttribute('data-step'), 10);
					if (!isNaN(idx)) { stepState.goTo(idx); }
				}
			}
			firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
			if (typeof firstError.focus === 'function') { firstError.focus(); }
		}

		log('Result:', result.valid ? '✓ PASS' : '✗ FAIL — first error: ' + (firstError ? firstError.name : '?'));
		logGroupEnd();
		return result.valid;
	}

	/* ═══════════════════════════════════════════════════════════
	   MULTI-STEP WIZARD
	   Activated when the form contains more than one .cfs-step
	   panel. Handles Back / Next navigation, per-step validation
	   and clickable stepper items for jumping back to completed
	   steps.
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * Validate fields within a single step panel and focus the first error.
	 *
	 * @param {HTMLElement} stepEl
	 * @returns {boolean}
	 */
	function validateStep(stepEl) {
		var result = validateScope(stepEl, false);
		if (result.firstError) {
			result.firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
			if (typeof result.firstError.focus === 'function') {
				result.firstError.focus();
			}
		}
		return result.valid;
	}

	/**
	 * Initialise a multi-step wizard form. No-op when fewer than 2 steps.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initStepForm(form) {
		if (form._cfsStepState) { return; }

		var steps = form.querySelectorAll('.cfs-step');
		if (steps.length < 2) { return; }

		var stepper      = form.querySelector('.cfs-stepper');
		var stepperItems = stepper ? stepper.querySelectorAll('.cfs-stepper-item') : [];
		var backBtn      = form.querySelector('.cfs-btn--back');
		var nextBtn      = form.querySelector('.cfs-btn--next');
		var submitBtn    = form.querySelector('.cfs-btn--submit');
		var total        = steps.length;

		var current = 0;

		function syncUI() {
			steps.forEach(function (s, i) {
				s.hidden = (i !== current);
			});

			stepperItems.forEach(function (item, i) {
				item.classList.remove('is-active', 'is-complete');
				if (i < current) { item.classList.add('is-complete'); }
				if (i === current) { item.classList.add('is-active'); }
				item.setAttribute('aria-current', (i === current) ? 'step' : 'false');
			});

			if (backBtn)   { backBtn.hidden   = (current === 0); }
			if (nextBtn)   { nextBtn.hidden   = (current === total - 1); }
			if (submitBtn) { submitBtn.hidden = (current !== total - 1); }
		}

		function goTo(index) {
			if (index < 0 || index >= total) { return; }
			current = index;
			syncUI();
		}

		function goNext() {
			if (!validateStep(steps[current])) {
				return;
			}
			if (current < total - 1) {
				goTo(current + 1);
				if (stepper) {
					stepper.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			}
		}

		function goBack() {
			if (current > 0) {
				goTo(current - 1);
				if (stepper) {
					stepper.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			}
		}

		if (nextBtn) {
			nextBtn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				goNext();
			});
		}

		if (backBtn) {
			backBtn.addEventListener('click', function (e) {
				e.preventDefault();
				/*
				 * stopPropagation() prevents the dialog backdrop-click handler
				 * from receiving this event. Without it, switching to a shorter
				 * step shrinks the dialog after the click is processed, and the
				 * (now stale) click coordinates fall outside the new dialog rect,
				 * causing the modal to close unexpectedly.
				 */
				e.stopPropagation();
				goBack();
			});
		}

		// Enter key inside an input within a step should advance, not submit
		// (except on the last step where submit is intentional).
		form.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter') { return; }
			var target = e.target;
			if (!target || target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON') { return; }
			if (current < total - 1) {
				e.preventDefault();
				goNext();
			}
		});

		// Expose state for validateForm() so submit-time errors on hidden
		// steps can reveal their panel before scrolling into view.
		form._cfsStepState = { goTo: goTo };

		syncUI();
		log('Step wizard initialised · total steps:', total);
	}

	/**
	 * Attach real-time blur + input validation to every field in a form.
	 *
	 * @param {HTMLFormElement} form
	 */
	function attachBlurValidation(form) {
		form.querySelectorAll('.cfs-input, .cfs-checkbox').forEach(function (field) {
			field.addEventListener('blur', function () {
				var msg = validateSingleField(field);
				if (msg) {
					setFieldError(field, msg);
				} else {
					clearFieldError(field);
				}
			});

			// Dismiss the error as soon as the user starts correcting.
			field.addEventListener('input', function () {
				if (field.classList.contains('cfs-input--error')) {
					clearFieldError(field);
				}
			});

			field.addEventListener('change', function () {
				if (field.classList.contains('cfs-input--error')) {
					clearFieldError(field);
				}
			});
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   MESSAGE BANNER
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * @param {HTMLElement|null}   msgEl
	 * @param {string}             text
	 * @param {'success'|'error'}  type
	 */
	function showMessage(msgEl, text, type) {
		if (!msgEl) {
			return;
		}
		msgEl.textContent   = text;
		msgEl.className     = 'cfs-form-message cfs-form-message--' + type;
		msgEl.style.display = 'block';
		msgEl.setAttribute('tabindex', '-1');
		msgEl.focus();
	}

	/* ═══════════════════════════════════════════════════════════
	   AJAX SUBMISSION
	   ═══════════════════════════════════════════════════════════ */

	function unlockButton(btnEl, origHTML) {
		btnEl.disabled = false;
		btnEl.removeAttribute('aria-busy');
		btnEl.innerHTML = origHTML;
	}

	/**
	 * Handle a successful form submission.
	 * Shows the success message, resets the form, and — if the form lives
	 * inside a <dialog> (modal mode) and no redirect is set — closes the
	 * dialog after the redirect-delay timeout.
	 *
	 * @param {HTMLFormElement} form
	 * @param {HTMLElement|null} msgEl
	 */
	function handleSuccess(form, msgEl, res) {
		var data  = (res && res.data) ? res.data : {};
		var after = getConfig(form).after || {};

		/*
		 * What happens after a submission is decided by the server: an action
		 * can change the message or add a redirect at run time. The config in
		 * data-cfs-config is only the fallback for an older cached response.
		 */
		var message     = (typeof data.message === 'string') ? data.message : (after.message || 'Спасибо!');
		var redirectUrl = (data.redirect && data.redirect.url) ? data.redirect.url : '';
		var delaySec    = (data.redirect && typeof data.redirect.delay !== 'undefined')
			? data.redirect.delay
			: after.redirectDelay;
		var delay       = (parseInt(delaySec, 10) || 0) * 1000;
		var doReset     = (typeof data.reset === 'boolean') ? data.reset : (after.resetForm !== false);
		var doScroll    = (typeof data.scroll === 'boolean') ? data.scroll : (after.scrollToMessage !== false);
		var closeModal  = (typeof data.closeModal === 'boolean') ? data.closeModal : !!after.closeModal;

		log('handleSuccess — message:', message, 'redirect:', redirectUrl || '(none)');

		form.querySelectorAll('.cfs-input, .cfs-checkbox').forEach(clearFieldError);

		if (doReset) {
			form.reset();
			// After reset every value is empty, so the labels must un-float.
			resetFloatingLabels(form);
			if (form._cfsStepState) {
				form._cfsStepState.goTo(0);
			}
		}

		if (message) {
			showMessage(msgEl, message, 'success');
			if (doScroll && msgEl && typeof msgEl.scrollIntoView === 'function') {
				msgEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}

		if (redirectUrl) {
			setTimeout(function () {
				window.location.href = redirectUrl;
			}, delay);
			return;
		}

		// Modal forms close only when the form asks for it — otherwise the
		// success message would vanish before it could be read.
		var dialog = form.closest ? form.closest('dialog') : null;
		if (closeModal && dialog && typeof dialog.close === 'function') {
			setTimeout(function () {
				dialog.close();
				notifyDialogToggle(dialog);
				// Reset the banner so it does not reappear on the next open.
				if (msgEl) {
					msgEl.style.display = 'none';
					msgEl.className     = 'cfs-form-message';
					msgEl.textContent   = '';
				}
			}, delay || 2000);
		}
	}

	/**
	 * Handle a failed submission response from the server.
	 * Shows the general error banner, maps per-field errors to inputs,
	 * and scrolls to + focuses the first errored element.
	 *
	 * Radio groups are handled specially: querySelector finds the first
	 * <input type="radio"> in the group; we walk up to the <fieldset>
	 * wrapper so the legend turns red and the error span is populated.
	 *
	 * @param {HTMLFormElement} form
	 * @param {HTMLElement|null} msgEl
	 * @param {Object} res  Parsed JSON response from wp_send_json_error().
	 */
	function handleError(form, msgEl, res) {
		logGroup('handleError · server errors');
		var errorMessage = (res.data && res.data.message)
			? res.data.message
			: (i18n.error_general || 'Произошла ошибка. Попробуйте ещё раз.');
		log('Message:', errorMessage);

		showMessage(msgEl, errorMessage, 'error');

		var firstErrorEl = null;

		// Map server field-level errors to inputs and show inline messages.
		if (res.data && res.data.errors) {
			Object.keys(res.data.errors).forEach(function (field) {
				var msg = res.data.errors[field];

				/*
				 * Checkbox and radio groups are <fieldset>s, not inputs, so
				 * they are resolved first: the error belongs on the group, not
				 * on whichever option happens to come first.
				 */
				var groupEl = form.querySelector('fieldset[data-cfs-field="' + field + '"]');
				if (groupEl) {
					groupEl.classList.add('cfs-field--has-error');
					var groupSpan = groupEl.querySelector('.cfs-error');
					if (groupSpan) { groupSpan.textContent = msg; }
					if (!firstErrorEl) { firstErrorEl = groupEl; }
					log('Mapping error: group "' + field + '" →', msg);
					return;
				}

				var input = form.querySelector('[data-cfs-field="' + field + '"]');
				if (input) {
					log('Mapping error: field="' + field + '" input=', input, '→', msg);
				} else {
					logWarn('handleError: no control found for field "' + field + '"');
					return;
				}

				if (input.type === 'radio') {
					/*
					 * Radio group: the individual <input> carries no
					 * aria-describedby. Mark the <fieldset> wrapper directly.
					 */
					var radioWrap = getFieldWrap(input);
					if (radioWrap) {
						radioWrap.classList.add('cfs-field--has-error');
						var radioSpan = radioWrap.querySelector('.cfs-error');
						if (radioSpan) {
							radioSpan.textContent = msg;
						}
						if (!firstErrorEl) {
							firstErrorEl = radioWrap;
						}
					}
				} else {
					setFieldError(input, msg);
					if (!firstErrorEl) {
						firstErrorEl = input;
					}
				}
			});
		}

		// Scroll to the first errored element so the user doesn't have to hunt.
		if (firstErrorEl) {
			firstErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
			if (typeof firstErrorEl.focus === 'function') {
				firstErrorEl.focus();
			}
		}
		logGroupEnd();
	}

	/**
	 * Wire up one form: floating labels, validation, AJAX.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initForm(form) {
		var ajaxUrl = (typeof cfsData !== 'undefined') ? cfsData.ajaxUrl : '/wp-admin/admin-ajax.php';
		var nonce   = (typeof cfsData !== 'undefined') ? cfsData.nonce   : '';
		var formId  = form.dataset.formId || '';
		// Walking up beats an ID lookup: two instances of the same form share
		// the form id and differ only by their instance suffix.
		var wrap    = form.closest ? form.closest('.cfs-form-wrap') : null;
		var msgEl   = wrap ? wrap.querySelector('.cfs-form-message') : null;
		var btnEl   = form.querySelector('[type="submit"]');

		logGroup('initForm · ' + formId + ' #' + (form.dataset.instance || '1'));
		log('form el:', form);
		log('wrap el:', wrap || 'NOT FOUND');
		log('msgEl:', msgEl || 'NOT FOUND');
		log('btnEl:', btnEl || 'NOT FOUND');
		log('config:', getConfig(form));
		logGroupEnd();

		// ① Floating labels.
		initFloatingLabels(form);

		// ② Blur-time validation.
		attachBlurValidation(form);

		// ③ Multi-step wizard (no-op for single-step forms).
		initStepForm(form);

		// ④ Hidden fields fed from the URL or a cookie. Filled here rather than
		// on the server so a page served from a full-page cache still carries
		// the current visitor's values.
		fillHiddenSources(form);

		// A form without a submit button cannot be sent; bail before wiring up
		// handlers that would dereference it.
		if (!btnEl) {
			logWarn('initForm: no submit button found — AJAX submit not wired for', formId);
			return;
		}

		// ⑤ AJAX submit.
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			logGroup('Submit · ' + formId);

			if (!validateForm(form)) {
				return;
			}
			log('Client validation passed — sending AJAX');

			var origHTML      = btnEl.innerHTML;
			btnEl.disabled    = true;
			btnEl.setAttribute('aria-busy', 'true');
			btnEl.textContent = i18n.sending || 'Отправка...';

			if (msgEl) {
				msgEl.style.display = 'none';
				msgEl.className     = 'cfs-form-message';
				msgEl.textContent   = '';
			}

			/**
			 * Build the payload afresh for every attempt: a retry after a nonce
			 * refresh must carry the new token.
			 *
			 * @returns {FormData}
			 */
			function buildPayload() {
				var data = new FormData(form);
				data.set('nonce', nonce);
				data.set('cfs_page_url', window.location.href);

				// Phones travel as digits only — the mask is presentation.
				form.querySelectorAll('input.cfs-input--phone').forEach(function (phoneInput) {
					if (phoneInput.name) {
						data.set(phoneInput.name, phoneInput.value.replace(/\D/g, ''));
					}
				});

				return data;
			}

			if (debug) {
				log('POST to:', ajaxUrl);
				try {
					buildPayload().forEach(function (val, key) {
						if (key !== 'nonce') { log(' FormData:', key, '=', typeof val === 'string' ? val : '[File/Blob]'); }
					});
				} catch (fe) { logWarn('Could not iterate FormData:', fe); }
			}
			logGroupEnd();

			/**
			 * @param {boolean} allowRetry Whether a stale nonce may be refreshed.
			 */
			function send(allowRetry) {
				fetch(ajaxUrl, {
					method:      'POST',
					body:        buildPayload(),
					credentials: 'same-origin',
				})
					.then(function (r) {
						if (!r.ok) {
							throw new Error('HTTP ' + r.status);
						}
						return r.json();
					})
					.then(function (res) {
						log('Server response:', res);

						/*
						 * A page held open, or served from a full-page cache,
						 * outlives its nonce. Fetch a fresh one and retry once
						 * rather than making the visitor reload and retype.
						 */
						if (!res.success && allowRetry && res.data && res.data.code === 'nonce') {
							logWarn('Nonce rejected — refreshing and retrying once');
							refreshNonce(ajaxUrl).then(function (fresh) {
								if (fresh) {
									nonce = fresh;
									send(false);
								} else {
									unlockButton(btnEl, origHTML);
									handleError(form, msgEl, res);
								}
							});
							return;
						}

						unlockButton(btnEl, origHTML);

						if (res.success) {
							handleSuccess(form, msgEl, res);
						} else {
							handleError(form, msgEl, res);
						}
					})
					.catch(function (err) {
						logError('Fetch/network error', err);
						unlockButton(btnEl, origHTML);
						showMessage(
							msgEl,
							i18n.error_general || 'Произошла ошибка. Попробуйте ещё раз.',
							'error'
						);
					});
			}

			send(true);
		});
	}

	/**
	 * Ask the server for a fresh submit nonce.
	 *
	 * @param {string} ajaxUrl
	 * @returns {Promise<string>} The new nonce, or '' when it could not be had.
	 */
	function refreshNonce(ajaxUrl) {
		var data = new FormData();
		data.set('action', 'cfs_refresh_nonce');

		return fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (res) {
				return (res && res.success && res.data && res.data.nonce) ? res.data.nonce : '';
			})
			.catch(function () { return ''; });
	}

	/**
	 * Fill hidden fields that declare a client-side source, e.g.
	 * [hidden utm_source source="query:utm_source"].
	 *
	 * @param {HTMLFormElement} form
	 */
	function fillHiddenSources(form) {
		form.querySelectorAll('input[type="hidden"][data-cfs-source]').forEach(function (input) {
			var source = input.getAttribute('data-cfs-source') || '';
			var parts  = source.split(':');
			var kind   = parts[0];
			var key    = parts.slice(1).join(':');
			var value  = '';

			if (kind === 'query') {
				try {
					value = new URLSearchParams(window.location.search).get(key) || '';
				} catch (err) {
					value = '';
				}
			} else if (kind === 'cookie') {
				var match = document.cookie.match(new RegExp('(?:^|; )' + key.replace(/([.$?*|{}()\[\]\\\/+^])/g, '\\$1') + '=([^;]*)'));
				value = match ? decodeURIComponent(match[1]) : '';
			}

			if (value) {
				input.value = value;
				log('Hidden source', source, '→', value);
			}
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   DIALOG (MODAL) SUPPORT
	   Wires up .cfs-modal-btn open buttons, .cfs-modal-close buttons,
	   and backdrop-click dismissal for every dialog.cfs-form-wrap--dialog.
	   ═══════════════════════════════════════════════════════════ */

	function initDialogs() {
		// Open buttons — rendered BEFORE the <dialog> in the shortcode output.
		document.querySelectorAll('.cfs-modal-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var dialogId = btn.getAttribute('data-dialog');
				var dialog   = dialogId ? document.getElementById(dialogId) : null;
				if (dialog && typeof dialog.showModal === 'function') {
					dialog.showModal();
					notifyDialogToggle(dialog);
					log('Dialog opened:', dialogId);
				}
			});
		});

		// Close buttons — rendered INSIDE the <dialog>.
		document.querySelectorAll('.cfs-modal-close').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.stopPropagation(); // Prevent click bubbling to the dialog backdrop handler.
				var dialogId = btn.getAttribute('data-dialog');
				var dialog   = dialogId ? document.getElementById(dialogId) : null;
				if (dialog && typeof dialog.close === 'function') {
					dialog.close();
					notifyDialogToggle(dialog);
					log('Dialog closed:', dialogId);
				}
			});
		});

		/*
		 * Backdrop click — close the modal only when the click lands OUTSIDE
		 * the visible dialog box (i.e. on the ::backdrop area).
		 * We compare the click coordinates against the dialog's bounding rect
		 * so that clicks on inner content (title, fields, padding) never
		 * trigger a close.
		 */
		document.querySelectorAll('dialog.cfs-form-wrap--dialog').forEach(function (dialog) {
			dialog.addEventListener('click', function (e) {
				var rect = dialog.getBoundingClientRect();
				var outsideX = e.clientX < rect.left || e.clientX > rect.right;
				var outsideY = e.clientY < rect.top  || e.clientY > rect.bottom;
				if (outsideX || outsideY) {
					dialog.close();
					notifyDialogToggle(dialog);
					log('Dialog closed via backdrop click');
				}
			});

			/*
			 * Escape key — the browser closes <dialog> natively on Escape,
			 * but listeners still need to hear about it.
			 */
			dialog.addEventListener('cancel', function () {
				notifyDialogToggle(dialog);
				log('Dialog closed via Escape key');
			});
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   BOOT
	   ═══════════════════════════════════════════════════════════ */
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.cfs-input--phone').forEach(applyPhoneMask);
		document.querySelectorAll('.cfs-form').forEach(initForm);
		initDialogs();
	});
}());
