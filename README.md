# Contact Form Submissions

WordPress plugin. A form is a text template you write in the admin: anything in square
brackets becomes a field, everything else is ordinary HTML. Submissions are stored in a
dedicated table and managed through a built-in admin panel.

- **Requires WordPress:** 5.0+
- **Requires PHP:** 7.2+
- **Version:** 4.3.0
- **License:** GPLv2 or later

---

## Installation

1. Upload the `contact-form` folder to `/wp-content/plugins/` and rename it to
   `contact-form-submissions`.
2. Activate the plugin.
3. **Submissions → Forms → Add form**, then paste the shortcode into a page.

Upgrading from 2.x? Existing `[contact_form fields="…"]` shortcodes keep working.
**Submissions → Migration** converts them into editable forms and explains how to drop
the compatibility module afterwards.

---

## The template

```html
<p>Leave a request — we call back within 15 minutes.</p>

<div class="cfs-row">
	[name* first_name label="Name" icon="user" width="1/2"]
	[phone* phone label="Phone" icon="phone" width="1/2"]
</div>

[select* topic label="Subject" options="Consultation:consult,Quote:calc"]
[textarea comment label="Message" rows="4"]
[agreement* consent label="I agree to the <a href='/privacy/'>privacy policy</a>"]
[hidden utm_source source="query:utm_source"]
[submit "Send" icon_after="arrow-right"]
```

Render it with:

```
[contact_form id="12"]
[contact_form slug="callback"]
[contact_form id="12" class="my-form"]
```

### Tag syntax

```
[type* name attribute="value"]
```

| Part | Meaning |
|---|---|
| `type` | the field type — see below |
| `*` | the field is required |
| `name` | latin letters, digits, `-` and `_`; omit it and one is generated |
| `attribute="value"` | named, any order; the value may contain spaces and brackets |

`[# comment]` renders nothing. `\[` is a literal bracket.

### Field types

| Type | Notes |
|---|---|
| `text`, `name`, `surname`, `patronymic` | single-line text; the last three validate as names |
| `email`, `phone`, `url` | format-checked; phone gets the `+7 (___) ___-__-__` mask |
| `number`, `date` | `min`, `max`, `step` |
| `textarea` | `rows`, `maxlength` |
| `select`, `radio`, `multicheck` | `options="Label:value,Label 2:value2"` |
| `checkbox`, `agreement` | agreement labels may contain a link |
| `hidden` | `value`, or `source="query:utm_source"` / `cookie:` / `page:` / `user:` |
| `submit` | `text`, `icon_before`, `icon_after`, `class` |
| `step` | splits the form into a wizard; `label` names the step |
| `unstep` | leaves the wizard: what follows belongs to no step and stays on screen |

Common attributes: `label`, `placeholder`, `default`, `class`, `icon`, `help`, `error`,
`role`, `width` (`1/2`, `1/3`, `2/3`, `1/4`, `3/4`, inside a `.cfs-row` wrapper).

### Naming the modal window

A form with `container="dialog"` always renders as
`<dialog … aria-labelledby="dialog-label">`. The heading it points at is yours to write,
in the template, as the first line:

```html
<h2 id="dialog-label">Tell us what happened</h2>
```

Then the window is announced by that heading instead of by the first field, and the same
line gives the modal a visible title. Leave the ID out and nothing breaks: a reference
that resolves to no element is skipped when the accessible name is computed — the window
is simply unnamed.

The ID is fixed, so it names one window per page. Put two modal forms on the same page
and both dialogs resolve to whichever heading carries the ID first — the second window
would answer to the first one's title. Where that happens, drop the ID from one heading
and give that window a name another way.

Inside the window the scrolling happens in `.cfs-form-body`, the box around the `<form>`,
not in the `<dialog>` itself. The close button and the result banner are positioned against
the dialog, so that is what keeps them on screen however far a long form is scrolled, and
the dialog's padding frames the window instead of scrolling away with the content. The
wrapper is rendered for every form; outside a modal it carries no styles of its own.

### Content that stays put while the steps change

`[step]` divides a template into panels and the wizard shows one at a time, so anything
written between two `[step]` tags disappears along with its panel. A heading for the form
is the obvious casualty: put it inside the first step and it is gone by the second.

`[unstep]` leaves the wizard. Everything after it belongs to no step and is rendered
outside the panels, so it stays on screen throughout:

```html
[unstep]
<h2 id="dialog-label">Tell us what happened</h2>

[step label="Details"]
[name* first_name label="Name"]

[step label="Your question"]
[textarea* message label="Question"]
[submit "Send"]
```

The next `[step]` closes the region and opens a panel again, so the two tags alternate and
a step's contents are always in one piece.

Where it lands:

- **Before the first `[step]`** — above the progress bar. This is the header case, and it
  is the reason the tag exists. The bar itself is never written in the template, so the
  template cannot place itself relative to it; the plugin lifts the leading run for you.
- **Anywhere after** — in the flow, between the panels. Since only one panel is on screen,
  such content sits below the step you are on and above the one you are going to. That is
  predictable but rarely what anyone wants: keep it to a footer after the last step.

A field may stand in an `[unstep]` region. It is on screen the whole time and is validated
when the form is submitted rather than when a step is left, because that pass walks the
whole form. It is not counted into any step.

Without any `[step]` in the template `[unstep]` does nothing at all — there is no wizard to
leave, and the content renders in place.

### The buttons the plugin draws itself

`[submit]` is written in the template, so it takes its classes there — `class="btn
btn--primary"`, added to the `cfs-btn cfs-btn--submit` it always carries rather than
replacing them. The other four buttons are chrome — the plugin decides they exist — so
they are configured on **Дополнительно** instead, each with a text, an icon on either side
and its own CSS classes:

| Button | Settings | Shown when |
|---|---|---|
| modal trigger | `modal_button_text`, `modal_button_icon_before`, `modal_button_icon_after`, `modal_button_class` | container is `dialog` |
| modal close | `close_icon`, `close_class` | container is `dialog` |
| back | `back_text`, `back_icon_before`, `back_icon_after`, `back_class` | template has `[step]` |
| next | `next_text`, `next_icon_before`, `next_icon_after`, `next_class` | template has `[step]` |

The classes those four settings add sit next to the base ones the stylesheet targets:
`cfs-btn cfs-btn--next`, `cfs-btn cfs-btn--back`, `cfs-modal-btn` and `cfs-modal-close`.
The back button is `cfs-btn--back` — there is no `cfs-btn--prev`.

The close button carries an icon, not a `✕` character: a character is whatever shape the
theme's font gives it and has no size of its own to style against. It defaults to `close`;
leaving the field empty falls back to the same icon rather than to an empty button.

Back and next default to `angle-small-left` and `angle-small-right`. A chevron points at
the next part of the same form; a full arrow reads as leaving, which is what the submit
button means. Both sides are offered on both buttons because which side reads correctly
depends on the wording, not on the direction.

### Using your own icons

**Submissions → Настройки → Директория с иконками** takes a path relative to
`wp-content`. Every `*.svg` directly inside it becomes an icon, named after its file, and
offered everywhere an icon is chosen:

```
themes/my-theme/assets/icons
```

A directory is **layered over** the built-in set name by name: `user.svg` replaces the
built-in `user`, and every name the folder does not carry keeps the icon it had. Up to
4.4.0 the directory replaced the set outright, and the cost fell on the icons no template
ever mentions — `close` and the wizard's two chevrons are picked by the plugin, not by the
author, so a folder of four hand-drawn field icons silently emptied the close button and
both step buttons. The settings screen reports how many icons the directory gave, how many
of them replaced a built-in, and how large the resulting set is.

A site that does want its folder to be the only source says so with the filter, which runs
last either way:

```php
add_filter( 'cfs_icon_library', function ( array $icons ): array {
	$own = CFS_Icons::directory_icons();
	return empty( $own ) ? $icons : $own;
} );
```

Two things decide whether a file works:

- **The colour has to be inheritable.** The plugin paints icons with `fill: currentColor`
  in buttons and `fill: var(--cfs-icon)` in fields, so a file with a hardcoded `fill="#000"`
  keeps that colour, and one drawn with `stroke` ignores both. Leave `fill` off the shapes
  or set it to `currentColor`.
- **The file name is the icon name.** It is lowercased and reduced to `a-z 0-9 - _`; a name
  that survives as nothing — one written in Cyrillic, say — is skipped.

Files are sanitised as they are read, against an allowlist of drawing elements. `<script>`,
`<style>`, `<use>`, `<image>`, `<a>`, `<foreignObject>`, the animation elements, every
event handler and every form of `href` are removed, along with all text content: an icon is
geometry. Files over 64 KB are skipped. The result is cached against the names and
modification times of the files, so editing one is noticed without a manual flush.

---

## After a submission

| Tab | What it does |
|---|---|
| **After submit** | message, redirect or both; clear the form; close the modal; custom error texts |
| **Mail** | notification and auto-reply, each with recipients, subject, body and `{field}` substitutions |
| **Integrations** | every installed add-on, enabled per form, with its own settings and field mapping |

Mail substitutions: `{field_name}`, `{field_name_label}`, `{all_fields}`, `{site_name}`,
`{form_title}`, `{submission_id}`, `{admin_url}`, `{date}`, `{ip}`, `{page_url}`.

---

## Features

- Template-driven forms with your own HTML around the fields
- Tag generator, live preview and template history in the editor
- Form export/import as JSON — move a form between sites
- Multi-step wizard and native `<dialog>` modal mode
- SVG icon library — the built-in set, or a directory of your own files
- Floating labels, six field style variants
- AJAX submission with matching client- and server-side validation
- Spam protection: nonce, two honeypots, timestamp window, referer check, per-IP rate
  limiting, banned words, and a strict field whitelist
- Submissions list with filters, bulk actions and a detail card built from the schema
  stored inside each submission
- CSV export with one column per field, UTF-8 BOM, formula injection neutralised
- Dashboard widget, per-submission action log
- No third-party JavaScript: the phone mask, modal and wizard are plain JS

---

## Text colours

Every colour is a CSS variable read from the form wrapper, so a theme sets them once
and the plugin's own stylesheet stays untouched:

```css
.cfs-form-wrap {
	--cfs-label: #6e6459;
	--cfs-text:  #4a423a;
}
```

| Variable | What it colours | Default |
|---|---|---|
| `--cfs-text` | the text the visitor types; steps already passed | `#222` |
| `--cfs-label` | the field label, at rest, in all six field styles | the style's own colour: `--cfs-text`, or `--cfs-legend` in outlined-top, `--cfs-text-muted` in left-label |
| `--cfs-label-active` | the label once it is raised — focused field or one holding a value | `--cfs-accent` |
| `--cfs-placeholder` | the native placeholder, in the styles that show one | `#aaa` |
| `--cfs-hint` | help text under a field | `#666` |
| `--cfs-legend` | heading of a radio or checkbox group | `#333` |
| `--cfs-text-muted` | static text blocks between fields | `#444` |
| `--cfs-stepper-text` | steps not yet reached | `#999` |
| `--cfs-error-dark` | error text under a field | `#c0392b` |

The label and the typed text are separate on purpose: at rest the label sits exactly
where the text will appear, and one colour for both leaves no way to tell an empty field
from a filled one at a glance. Set neither variable and nothing changes — `--cfs-label`
falls back to whatever each field style used before it existed.

One variable is not a colour: `--cfs-focus-width` sets how thick the underline grows
when a field in the underline style takes focus — `2px` by default, counting the 1px
border. The extra thickness is a shadow hanging below the field, so focus never
changes its height.

---

## For developers

An integration registers itself once and the core does the rest — settings card,
sanitising, execution after save, retries and logging:

```php
add_filter( 'cfs_integrations', function ( array $items ): array {
	$items['my_crm'] = array(
		'label'    => 'My CRM',
		'fields'   => array(
			'webhook_url' => array( 'type' => 'url', 'label' => 'Webhook', 'required' => true ),
			'map'         => array(
				'type'    => 'field_map',
				'label'   => 'Field mapping',
				'targets' => array( 'PHONE' => 'Phone', 'EMAIL' => 'Email' ),
			),
		),
		'run'      => function ( array $data, array $settings, int $submission_id ) {
			// … deliver …
			return CFS_Action_Result::success( 'Lead #500 created' );
		},
		'deferred' => true,
	);

	return $items;
} );
```

A custom field type is a descriptor on `cfs_field_types`; a custom icon is one entry on
`cfs_icon_library`. The full hook list is on **Submissions → Help**.

### The config the browser reads

Client-side validation runs off a compiled copy of the schema. It is rendered as a JSON
script tag and the form points at it:

```html
<form class="cfs-form" data-cfs-config-id="cfs-config-12-1"> … </form>
<script type="application/json" id="cfs-config-12-1">{"formId":12,…}</script>
```

```js
const el  = document.getElementById(form.dataset.cfsConfigId);
const cfg = JSON.parse(el.textContent);
```

Per field it carries `type`, `required`, `multiple`, `error`, `rules` and whichever of
`min`, `max`, `step`, `minlength`, `maxlength`, `options` apply — enough to validate, and
nothing that is not already visible in the form. Labels are not in it: a field is named by
the `<label>` beside it. Mail recipients, integration settings and spam thresholds never
leave the server.

Up to 3.2.0 the same JSON sat in a `data-cfs-config` attribute on the form. The script
still falls back to it, so a cached page keeps working, but nothing renders it any more.

---

## Tests

The suites run inside a real WordPress, so kses, `sanitize_*()` and `wp_mail()` behave
exactly as they do in production:

```bash
docker compose exec -T wordpress php -r "define('DOING_AJAX', true); define('WP_ADMIN', true); define('WP_USE_THEMES', false); require '/var/www/html/wp-load.php'; require '/var/www/html/wp-content/plugins/contact-form/tests/run-all.php';"
```
