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

	/* Always emit one boot line so you can verify the latest JS is loaded. */
	console.log('[CFS] cfs-form.js loaded · debug =', debug, '· cfsData defined =', typeof cfsData !== 'undefined');

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

	/**
	 * Validate one field and return an error string, or '' when valid.
	 *
	 * @param {HTMLElement} field
	 * @returns {string}
	 */
	function validateSingleField(field) {
		var isCheckbox = field.type === 'checkbox';
		var val        = isCheckbox ? field.checked : field.value.trim();
		var name       = field.name || '';

		// Required.
		if (field.getAttribute('aria-required') === 'true') {
			if (!val) {
				return i18n.required || 'Обязательное поле';
			}
		}

		// Skip format checks when empty (and not required).
		if (!isCheckbox && !field.value.trim()) {
			return '';
		}

		// Email format — matches cfs_email and indexed variants like cfs_email_2.
		if (/^cfs_email/.test(name)) {
			var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
			if (!emailRe.test(field.value.trim())) {
				return i18n.invalid_email || 'Некорректный email';
			}
		}

		// Phone: 10–11 digits — matches cfs_phone and indexed variants like cfs_phone_2.
		if (/^cfs_phone/.test(name)) {
			var digits = field.value.replace(/\D/g, '');
			if (digits.length < 10 || digits.length > 11) {
				return i18n.invalid_phone || 'Некорректный номер телефона';
			}
		}


	// Pattern mismatch — field-specific messages based on field name.
	if (field.validity && field.validity.patternMismatch) {
		if (/^cfs_phone/.test(name)) {
			return i18n.invalid_phone || 'Некорректный номер телефона';
		}
		if (/^cfs_email/.test(name)) {
			return i18n.invalid_email || 'Некорректный email';
		}
		return i18n.invalid_name || 'Допустимы только буквы, дефис, пробел.';
	}

	// Type mismatch — e.g. type="email" browser validation.
	if (field.validity && field.validity.typeMismatch) {
		if (field.type === 'email') {
			return i18n.invalid_email || 'Некорректный email';
		}
	}

	// Date constraint validation (min / max).
	if (field.type === 'date') {
		if (field.validity && !field.validity.valid) {
			if (field.validity.rangeUnderflow) {
				return (i18n.date_min || 'Дата не может быть раньше ') + (field.min || '');
			}
			if (field.validity.rangeOverflow) {
				return (i18n.date_max || 'Дата не может быть позже ') + (field.max || '');
			}
			if (field.validity.badInput) {
				return i18n.invalid_date || 'Некорректная дата.';
			}
		}
	}

	// Number constraint validation (min / max / step).
	if (field.type === 'number') {
		if (field.validity && !field.validity.valid) {
			if (field.validity.rangeUnderflow) {
				return (i18n.num_min || 'Минимальное значение: ') + (field.min || '');
			}
			if (field.validity.rangeOverflow) {
				return (i18n.num_max || 'Максимальное значение: ') + (field.max || '');
			}
			if (field.validity.stepMismatch) {
				return i18n.num_step || 'Значение не соответствует шагу.';
			}
			if (field.validity.badInput) {
				return i18n.invalid_number || 'Введите числовое значение.';
			}
		}
	}

		return '';
	}

	/**
	 * Validate every interactive field. Scrolls to and focuses the first error.
	 *
	 * @param {HTMLFormElement} form
	 * @returns {boolean}
	 */
	function validateForm(form) {
		logGroup('Client validation · form#' + (form.dataset.formId || '?'));
		var valid      = true;
		var firstError = null;

		form.querySelectorAll('.cfs-input, .cfs-checkbox').forEach(function (field) {
			clearFieldError(field);
		});

		form.querySelectorAll('.cfs-input, .cfs-checkbox').forEach(function (field) {
			var msg = validateSingleField(field);
			if (msg) {
				setFieldError(field, msg);
				log('Field invalid:', field.name, '→', msg);
				if (!firstError) {
					firstError = field;
				}
				valid = false;
			}
		});

		if (firstError) {
			firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
			firstError.focus();
		}

		log('Result:', valid ? '✓ PASS' : '✗ FAIL — first error: ' + (firstError ? firstError.name : '?'));
		logGroupEnd();
		return valid;
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
	function handleSuccess(form, msgEl) {
		log('handleSuccess — resetting form, showing success message');
		var successMsg  = form.dataset.successMessage || 'Спасибо!';
		var redirectUrl = form.dataset.redirectUrl    || '';
		var delay       = parseInt(form.dataset.redirectDelay || '2', 10) * 1000;

		// Clear all error states.
		form.querySelectorAll('.cfs-input, .cfs-checkbox').forEach(clearFieldError);

		// Reset field values.
		form.reset();

		// Reset floating labels: after reset all values are empty, so un-float.
		resetFloatingLabels(form);

		showMessage(msgEl, successMsg, 'success');

		if (redirectUrl) {
			setTimeout(function () {
				window.location.href = redirectUrl;
			}, delay);
		} else {
			/*
			 * No redirect — check whether the form is inside a <dialog>.
			 * If so, close it after the same delay so the user can read the
			 * success message briefly before the modal disappears.
			 */
			var dialog = form.closest ? form.closest('dialog') : null;
			if (dialog && typeof dialog.close === 'function') {
				setTimeout(function () {
					dialog.close();
					tryLocomotiveToggle();
					// Reset the banner so it does not reappear on next open.
					if (msgEl) {
						msgEl.style.display = 'none';
						msgEl.className     = 'cfs-form-message';
						msgEl.textContent   = '';
					}
				}, delay);
			}
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
				var msg   = res.data.errors[field];
				var input = form.querySelector('[name="cfs_' + field + '"]');
				if (input) {
					log('Mapping error: field="' + field + '" input=', input, '→', msg);
				} else {
					logWarn('handleError: no input found for field token "' + field + '" (looked for [name="cfs_' + field + '"])');
				}
				if (!input) {
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
		var wrap    = document.getElementById('cfs-wrap-' + formId);
		var msgEl   = wrap ? wrap.querySelector('.cfs-form-message') : null;
		var btnEl   = form.querySelector('[type="submit"]');

		logGroup('initForm · ' + formId);
		log('form el:', form);
		log('wrap el:', wrap || 'NOT FOUND (id: cfs-wrap-' + formId + ')');
		log('msgEl:', msgEl || 'NOT FOUND');
		log('btnEl:', btnEl || 'NOT FOUND');
		logGroupEnd();

		// ① Floating labels.
		initFloatingLabels(form);

		// ② Blur-time validation.
		attachBlurValidation(form);

		// ③ AJAX submit.
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			logGroup('Submit · ' + formId);

			if (!validateForm(form)) {
				return;
			}
			log('Client validation passed — sending AJAX');

			var origHTML      = btnEl.innerHTML;
			log('Button locked, origHTML saved');
			btnEl.disabled    = true;
			btnEl.setAttribute('aria-busy', 'true');
			btnEl.textContent = i18n.sending || 'Отправка...';

			if (msgEl) {
				msgEl.style.display = 'none';
				msgEl.className     = 'cfs-form-message';
				msgEl.textContent   = '';
			}

			var data = new FormData(form);
			data.set('nonce',    nonce);
			data.set('page_url', window.location.href);

			// Send only digits for all phone fields (cfs_phone, cfs_phone_2, …).
			form.querySelectorAll('input.cfs-input--phone').forEach(function (phoneInput) {
				if (phoneInput.name) {
					data.set(phoneInput.name, phoneInput.value.replace(/\D/g, ''));
				}
			});

			if (debug) {
				log('POST to:', ajaxUrl);
				try {
					data.forEach(function (val, key) {
						if (key !== 'nonce') { log(' FormData:', key, '=', typeof val === 'string' ? val : '[File/Blob]'); }
					});
				} catch (fe) { logWarn('Could not iterate FormData:', fe); }
			}
			logGroupEnd();
			fetch(ajaxUrl, {
				method:      'POST',
				body:        data,
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
					unlockButton(btnEl, origHTML);

					if (res.success) {
						handleSuccess(form, msgEl);
					} else {
						handleError(form, msgEl, res);
					}
				})
				.catch(function () {
					logError('Fetch/network error');
					unlockButton(btnEl, origHTML);
					showMessage(
						msgEl,
						i18n.error_general || 'Произошла ошибка. Попробуйте ещё раз.',
						'error'
					);
				});
		});
	}

	/* ═══════════════════════════════════════════════════════════
	   DIALOG (MODAL) SUPPORT
	   Wires up .cfs-modal-btn open buttons, .cfs-modal-close buttons,
	   and backdrop-click dismissal for every dialog.cfs-form-wrap--dialog.
	   ═══════════════════════════════════════════════════════════ */

	/**
	 * Call locomotiveToggleScroll() if it exists on the global scope.
	 * Used to lock/unlock page scroll when a modal is opened/closed.
	 */
	function tryLocomotiveToggle() {
		if (typeof locomotiveToggleScroll === 'function') {
			locomotiveToggleScroll();
			log('locomotiveToggleScroll() called');
		}
	}

	function initDialogs() {
		// Open buttons — rendered BEFORE the <dialog> in the shortcode output.
		document.querySelectorAll('.cfs-modal-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var dialogId = btn.getAttribute('data-dialog');
				var dialog   = dialogId ? document.getElementById(dialogId) : null;
				if (dialog && typeof dialog.showModal === 'function') {
					dialog.showModal();
					tryLocomotiveToggle();
					log('Dialog opened:', dialogId);
				}
			});
		});

		// Close buttons — rendered INSIDE the <dialog>.
		document.querySelectorAll('.cfs-modal-close').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var dialogId = btn.getAttribute('data-dialog');
				var dialog   = dialogId ? document.getElementById(dialogId) : null;
				if (dialog && typeof dialog.close === 'function') {
					dialog.close();
					tryLocomotiveToggle();
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
					tryLocomotiveToggle();
					log('Dialog closed via backdrop click');
				}
			});

			/*
			 * Escape key — the browser closes <dialog> natively on Escape,
			 * but we need to call locomotiveToggleScroll() as well.
			 */
			dialog.addEventListener('cancel', function () {
				tryLocomotiveToggle();
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
