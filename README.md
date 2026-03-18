# Contact Form Submissions

WordPress plugin for flexible contact forms via shortcode. Submissions are saved to a dedicated database table and managed through a built-in admin panel.

- **Requires WordPress:** 5.0+
- **Requires PHP:** 7.2+
- **License:** GPLv2 or later

---

## Features

- Shortcode `[contact_form]` with fully configurable fields
- Field types: `name`, `surname`, `patronymic`, `phone`, `email`, `comment`, `select`, `radio`, `checkbox`, `agreement`, `text`, `hidden`
- Star (`*`) notation to mark required fields inline: `fields="name*,phone*,email"`
- Indexed field tokens for repeated types: `name_2`, `comment_3`, etc.
- Dialog / modal mode via native `<dialog>` element
- SVG icon library for field inputs and buttons — no external dependencies
- Phone mask (`+7` format) in pure JavaScript — no third-party libraries
- Floating labels with animation
- AJAX submission with client-side and server-side validation
- Spam protection: honeypot fields + form timestamp + rate limiting (5/min, 20/hour per IP)
- Admin panel: list view, filters, sorting, bulk actions, detail view (postbox layout)
- HTML email notifications with `Reply-To` and configurable recipients
- CSV export with UTF-8 BOM for correct Excel display
- Dashboard widget with submission counters and latest entries
- Full i18n support

---

## Installation

1. Upload the `contact-form` folder to `/wp-content/plugins/` and rename it to `contact-form-submissions`
2. Activate via **Plugins** in the WordPress admin
3. Add `[contact_form]` to any page or post

---

## Shortcode Usage

### Basic

```
[contact_form]

[contact_form fields="name*,phone*,email" title="Contact us"]
```

### Required fields via star notation

```
[contact_form fields="name*,phone*,email,comment"]
```

### Select field

```
[contact_form fields="name*,phone*,select*"
  select_label="Topic"
  select_options="Support:support,Sales:sales,Other:other"]
```

### Radio button group

```
[contact_form fields="name*,phone*,radio"
  radio_label="Have you volunteered before?"
  radio_options="Yes:yes,No:no"]
```

### Modal / dialog mode

```
[contact_form container="dialog"
  modal_button_text="Request a call"
  modal_button_icon_after="phone"
  fields="name*,phone*"]
```

### Field and button icons

```
[contact_form fields="name*,phone*,email"
  name_icon="user"
  phone_icon="phone"
  email_icon="email"
  button_icon_after="arrow"]
```

### Agreement field

```
[contact_form fields="name*,phone*,agreement*"
  agreement_label="I agree to the <a href='/privacy'>Privacy Policy</a>"]
```

### Hidden fields (UTM)

```
[contact_form fields="name*,phone*,hidden"
  hidden_name="utm_source"
  hidden_value="google"]
```

### Redirect after submission

```
[contact_form success_message="Thank you!" redirect_url="/thank-you/" redirect_delay="3"]
```

### Multiple forms on one page

```
[contact_form form_id="form_main" title="Main form"]
[contact_form form_id="form_quick" fields="name*,phone*" button_text="Call me back"]
```

---

## Shortcode Parameters

| Parameter | Default | Description |
|---|---|---|
| `form_id` | auto | Unique form identifier |
| `title` | — | Heading displayed above the form |
| `fields` | `name,phone,email` | Comma-separated field tokens. Append `*` to mark required. |
| `button_text` | `Send` | Submit button label |
| `button_icon_before` | — | Icon key before submit button label |
| `button_icon_after` | — | Icon key after submit button label |
| `class` | — | Extra CSS class on the form wrapper |
| `success_message` | `Thank you! We will be in touch.` | Message after successful submission |
| `redirect_url` | — | URL to redirect to after submission |
| `redirect_delay` | `2` | Redirect delay in seconds |
| `container` | `div` | `div` for inline, `dialog` for modal mode |
| `modal_button_text` | `Open form` | Label of the modal trigger button |
| `modal_button_icon_before` | — | Icon key before modal trigger label |
| `modal_button_icon_after` | — | Icon key after modal trigger label |

### Per-field attributes

Pattern: `{field_token}_{attr}` — e.g. `name_label`, `comment_2_required`, `phone_icon`.

| Attribute | Description |
|---|---|
| `{field}_label` | Field label text |
| `{field}_required` | `yes` or `no` |
| `{field}_placeholder` | Placeholder text |
| `{field}_icon` | Icon key from the built-in library |

---

## Icon Library

Built-in keys: `user`, `phone`, `email`, `comment`, `select`, `company`, `location`, `calendar`, `lock`, `link`, `search`, `star`.

Add custom icons via filter:

```php
add_filter( 'cfs_icon_library', function( $icons ) {
    $icons['arrow'] = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" ...>...</svg>';
    return $icons;
} );
```

SVG requirements: `width="20" height="20"`, `fill="none" stroke="currentColor"`, `aria-hidden="true" focusable="false"`.

---

## Developer Hooks

```php
apply_filters( 'cfs_before_save',      $data, $form_id )
do_action(    'cfs_after_save',         $submission_id, $data )
apply_filters( 'cfs_validate_field',   $error, $field, $value, $form_id )
apply_filters( 'cfs_email_recipients', $recipients, $data )
apply_filters( 'cfs_email_headers',    $headers, $data )
apply_filters( 'cfs_email_body',       $body, $data )
apply_filters( 'cfs_form_fields',      $fields, $form_id, $atts )
apply_filters( 'cfs_rate_limit',       $is_limited, $ip, $form_id )
apply_filters( 'cfs_spam_check',       $is_spam, $data, $form_id )
apply_filters( 'cfs_success_response', $response, $data )
apply_filters( 'cfs_form_html',        $html, $form_id, $atts )
apply_filters( 'cfs_icon_library',     $icons )
```

---

## Admin Panel

**Submissions → All Submissions** — filterable list (status, form_id), bulk actions (mark processed / spam / delete), sortable, paginated at 20 per page. Menu badge shows count of unread submissions.

**Submissions → Settings** — extra email recipients, email subject template, banned words, IP/UA saving, plugin styles toggle, agreement field text, debug mode.

---

## Changelog

### 2.1.0
- `radio` field type: card-style radio button group with `<fieldset>`/`<legend>` accessibility
- `text` field type: display-only content block with HTML support
- `button_icon_before` / `button_icon_after` for submit and modal trigger buttons
- Indexed variants support for `radio` and `text` field types

### 2.0.0
- Full rewrite with Singleton architecture and WordPress Coding Standards
- Dynamic indexed field tokens (`name_2`, `comment_3*`, etc.)
- Star (`*`) notation in the `fields` attribute
- Form config cached via transients for AJAX validation
- Dialog / modal mode using native `<dialog>` element
- Agreement field with HTML link support
- SVG icon library with `cfs_icon_library` filter
- Floating label animation
- Phone mask with cursor-position tracking (pure JS)
- Rate-limiting table (5/min, 20/hour per IP)
- CSV export with UTF-8 BOM
- Dashboard widget
- Full i18n support
