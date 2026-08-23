# Contact Form Submissions

WordPress plugin. A form is a text template you write in the admin: anything in square
brackets becomes a field, everything else is ordinary HTML. Submissions are stored in a
dedicated table and managed through a built-in admin panel.

- **Requires WordPress:** 5.0+
- **Requires PHP:** 7.2+
- **Version:** 3.0.0
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

Common attributes: `label`, `placeholder`, `default`, `class`, `icon`, `help`, `error`,
`role`, `width` (`1/2`, `1/3`, `2/3`, `1/4`, `3/4`, inside a `.cfs-row` wrapper).

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
- SVG icon library, floating labels, six field style variants
- AJAX submission with matching client- and server-side validation
- Spam protection: nonce, two honeypots, timestamp window, referer check, per-IP rate
  limiting, banned words, and a strict field whitelist
- Submissions list with filters, bulk actions and a detail card built from the schema
  stored inside each submission
- CSV export with one column per field, UTF-8 BOM, formula injection neutralised
- Dashboard widget, per-submission action log
- No third-party JavaScript: the phone mask, modal and wizard are plain JS

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

---

## Tests

The suites run inside a real WordPress, so kses, `sanitize_*()` and `wp_mail()` behave
exactly as they do in production:

```bash
docker compose exec -T wordpress php -r "define('DOING_AJAX', true); define('WP_ADMIN', true); define('WP_USE_THEMES', false); require '/var/www/html/wp-load.php'; require '/var/www/html/wp-content/plugins/contact-form/tests/run-all.php';"
```
