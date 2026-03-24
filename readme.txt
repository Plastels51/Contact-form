=== Contact Form Submissions ===
Contributors: Plastels51
Tags: contact form, form, submissions, shortcode, modal
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Flexible contact forms via shortcode. Submissions are saved to a dedicated database table with a full admin panel.

== Description ==

Contact Form Submissions lets you add contact forms to any page or post using the [contact_form] shortcode. Every submission is stored in a dedicated database table and managed through a built-in admin panel.

Features:

* Shortcode-based forms with fully configurable fields
* Field types: name, surname, patronymic, phone, email, comment, select, checkbox, agreement, date, number, hidden
* Multiple instances of the same field type via indexed suffixes (name_2, comment_3*, etc.)
* Star (*) notation to mark required fields directly in the fields attribute
* Dialog / modal mode: renders a trigger button + native &lt;dialog&gt; element
* SVG icon library for field inputs and buttons — no external dependencies
* Phone mask (+7 format) in pure JavaScript — no third-party libraries
* Floating labels — animated labels replace static placeholders
* AJAX submission with both client-side and server-side validation
* Spam protection via honeypot fields and form timestamp
* Rate limiting per IP (5/min, 20/hour)
* Admin panel: list view, filters, sorting, bulk actions, detail view
* HTML email notifications with Reply-To and configurable recipients
* CSV export with UTF-8 BOM for correct Excel display
* Dashboard widget showing the 5 latest new submissions
* Full internationalisation support (i18n-ready)
* Agreement field with HTML links support (privacy policy, terms, etc.)
* Date and number fields with min/max/step constraints
* HTML pattern validation for name fields (letters, hyphens, spaces only)

== Installation ==

1. Upload the `contact-form-submissions` folder to `/wp-content/plugins/`
2. Activate the plugin via the Plugins menu in WordPress
3. Add `[contact_form]` to any page or post
4. Configure the agreement field text under Submissions → Settings

== Shortcode Reference ==

= Basic usage =

  [contact_form]

  [contact_form fields="name,phone,email" title="Contact us"]

= Required fields with star notation =

Fields marked with `*` become required; all others become optional:

  [contact_form fields="name*,phone*,email,comment"]

= Multiple instances of the same field type =

Use numeric suffixes to repeat a field type. Per-instance attributes follow the same `{field}_{attr}` pattern:

  [contact_form fields="name*,comment,comment_2" comment_2_label="Additional notes"]

= Select field =

  [contact_form fields="name,phone,select"
    select_label="Topic"
    select_options="Support:support,Sales:sales,Other:other"]

The label text appears as the disabled placeholder option inside the dropdown (no visible label element above it).

= Radio button group =

Use `radio` for a single-choice group rendered as bordered card buttons. Options follow the same `Label:value` format as select. Supports indexed variants (`radio_2`, etc.) with per-instance options via `{field}_options`:

  [contact_form fields="name*,phone*,radio"
    radio_label="Have you volunteered before?"
    radio_options="Yes:yes,No:no"]

  [contact_form fields="name*,radio,radio_2"
    radio_label="Topic"
    radio_options="Support:support,Sales:sales"
    radio_2_label="Preferred contact"
    radio_2_options="Phone:phone,Email:email"]

= Static text / heading =

Use `text` to insert a display-only block of content inside the form — for section headings, instructions, or legal notices. Supports HTML links, bold and italic via `{field}_label`. Indexed variants (`text_2`, `text_3`) are supported:

  [contact_form fields="name*,phone*,text,radio"
    text_label="<strong>What would you like to do as a volunteer?</strong>"
    radio_label="Have you volunteered before?"
    radio_options="Yes:yes,No:no"]

= Agreement field =

The label text is taken from Submissions → Settings → Agreement text and may contain HTML links. Override it per form with `agreement_label`:

  [contact_form fields="name*,phone*,agreement*"
    agreement_label="I agree to the <a href='/privacy'>Privacy Policy</a>"]

= Dialog / modal mode =

Set `container="dialog"` to render the form inside a native &lt;dialog&gt; element. A trigger button is placed at the shortcode location; clicking it opens the modal.

  [contact_form container="dialog" modal_button_text="Request a call" fields="name*,phone*"]

= Field icons =

Each field accepts an `{field}_icon` attribute whose value is a key from the built-in icon library. The icon is absolutely positioned inside the input with the correct vertical alignment:

  [contact_form fields="name*,phone*,email"
    name_icon="user"
    phone_icon="phone"
    email_icon="email"]

Indexed field variants work the same way:

  [contact_form fields="name*,name_2,phone*"
    name_icon="user"
    name_2_icon="user"]

= Button icons =

Add an icon before or after the text of the submit button or the modal trigger button:

  [contact_form button_icon_after="arrow" fields="name*,phone*,email"]

  [contact_form
    container="dialog"
    modal_button_text="Request a call"
    modal_button_icon_after="phone"
    fields="name*,phone*"]

Both buttons use `display: inline-flex` so icons are vertically centred with the label text automatically.

= Date field =

Use `date` for a date picker. Supports `{field}_min` and `{field}_max` in `YYYY-MM-DD` format:

  [contact_form fields="name*,phone*,date*"
    date_label="Date of birth"
    date_min="1940-01-01"
    date_max="2006-12-31"]

= Number field =

Use `number` for a numeric input. Supports `{field}_min`, `{field}_max`, and `{field}_step`:

  [contact_form fields="name*,phone*,number*"
    number_label="Age"
    number_min="18"
    number_max="99"
    number_step="1"]

= Pattern validation =

Name, surname, and patronymic fields automatically include an HTML5 `pattern` attribute that allows only letters (Latin + Cyrillic), spaces, hyphens, and apostrophes. Phone and email fields also include default patterns — phone matches the masked format `+7 (XXX) XXX-XX-XX`, email validates standard `local@domain.tld` format. Override the pattern per field:

  [contact_form fields="name*" name_pattern="[A-Za-z\s]+"]

  [contact_form fields="phone*" phone_pattern="\+7 \(\d{3}\) \d{3}-\d{2}-\d{2}"]

  [contact_form fields="email*" email_pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}"]

= Hidden fields / UTM parameters =

  [contact_form fields="name,phone,hidden" hidden_name="utm_source" hidden_value="google"]

= Redirect after submission =

  [contact_form success_message="Thank you!" redirect_url="/thank-you/" redirect_delay="3"]

= Two forms on one page =

  [contact_form form_id="form_main" title="Main form"]
  [contact_form form_id="form_quick" fields="name*,phone*" button_text="Call me back"]

== Shortcode Parameters ==

= General =

Parameter             | Default                      | Description
--------------------- | ---------------------------- | -------------------------------------------
form_id               | auto                         | Unique form identifier
title                 | —                            | Heading displayed above the form
fields                | name,phone,email             | Comma-separated list of field tokens. Append * to mark a field required.
button_text           | Send                         | Submit button label
class                 | —                            | Extra CSS class added to the form wrapper
success_message       | Thank you! We will be in touch. | Message shown after successful submission
redirect_url          | —                            | URL to redirect to after submission
redirect_delay        | 2                            | Redirect delay in seconds
container             | div                          | `div` for inline form, `dialog` for modal mode
modal_button_text     | Open form                    | Label of the modal trigger button (dialog mode only)
modal_button_icon_before | —                         | Icon key shown before the modal trigger button label
modal_button_icon_after  | —                         | Icon key shown after the modal trigger button label
button_icon_before    | —                            | Icon key shown before the submit button label
button_icon_after     | —                            | Icon key shown after the submit button label

= Per-field attributes =

The pattern is `{field_token}_{attr}`, e.g. `comment_2_label`, `name_required`, `phone_icon`.

Attribute              | Default      | Description
---------------------- | ------------ | -------------------------------------------
{field}_label          | auto         | Field label text
{field}_required       | see below    | `yes` or `no`
{field}_placeholder    | —            | Placeholder text (shown inside the input)
{field}_icon           | —            | Icon key from the built-in library

= Field-specific extras =

* `select_options` — comma-separated `Label:value` pairs for the select field, e.g. `"Support:support,Sales:sales"`.
* `radio_options` — comma-separated `Label:value` pairs for the radio field (global default); override per instance with `{field}_options`, e.g. `radio_2_options="Yes:yes,No:no"`.
* `comment_rows` — number of rows for the textarea (default: `4`).
* `hidden_name`, `hidden_value` — name and value for a hidden input field.
* `agreement_label` — overrides the global agreement text for this form instance (HTML links allowed).
* `date_min`, `date_max` — minimum and maximum date in `YYYY-MM-DD` format.
* `number_min`, `number_max` — minimum and maximum numeric value.
* `number_step` — step increment for the number input (e.g. `1`, `0.01`).
* `{field}_pattern` — HTML5 pattern regex for text fields (overrides the built-in default for name/surname/patronymic).

= Default required values =

Field        | Required by default
------------ | -------------------
name         | yes
surname      | yes
patronymic   | no
phone        | yes
email        | no
comment      | no
select       | no
checkbox     | no
agreement    | no
radio        | no
date         | no
number       | no
text         | n/a (display only)

== Field Types ==

`name` — single-line text input, required by default.

`surname` — single-line text input, required by default.

`patronymic` — single-line text input, optional by default.

`phone` — telephone input with +7 mask, required by default. The mask is applied in pure JavaScript; the raw digits are sent on submission. Includes HTML5 `pattern` matching the masked format by default.

`email` — email input, optional by default. Includes HTML5 `pattern` for format validation in addition to the native `type="email"` browser check.

`comment` — textarea, optional by default. Use `comment_rows` to control height.

`select` — dropdown list. Options are defined via `select_options` as `Label:value` pairs. The field label appears as a disabled placeholder option; no visible label element is rendered above the input.

`checkbox` — a single checkbox with a customisable label.

`agreement` — a checkbox whose label is taken from the admin setting and supports HTML links (e.g. a privacy policy link). Rendered with `wp_kses()` so only `<a>` tags are allowed.

`radio` — a radio button group rendered as bordered card buttons. Options defined via `{field}_options` (or the global `radio_options`) in `Label:value` format. Wrapped in `<fieldset>`/`<legend>` for full accessibility. Supports indexed variants (`radio_2`, etc.).

`text` — a display-only block (no input element). Content comes from `{field}_label` and may contain `<a>`, `<strong>`, `<em>`, `<br>` tags. Use it for section headings, instructions, or any informational text between form fields. Supports indexed variants (`text_2`, `text_3`, etc.).

`date` — a date picker (`type="date"`). Supports `date_min` and `date_max` attributes in `YYYY-MM-DD` format. The label floats above the input permanently since the browser always renders date picker UI. Validated both client-side (HTML5 constraint validation) and server-side (Y-m-d format + min/max).

`number` — a numeric input (`type="number"`). Supports `number_min`, `number_max`, and `number_step`. The label floats above the input permanently. Validated both client-side and server-side.

`hidden` — a hidden input. Set `hidden_name` and `hidden_value`.

== Icon Library ==

Icons are SVG strings stored in the `get_icon_library()` private method in `includes/class-cfs-form-builder.php`. The method is clearly commented to show where to add your own icons; no cache needs to be cleared after editing.

Built-in icon keys: `user`, `phone`, `email`, `comment`, `select`, `company`, `location`, `calendar`, `lock`, `link`, `search`, `star`.

SVG requirements for custom icons:
* `width="20" height="20"` — the CSS scales it via `1.1rem / 1.1em`.
* `fill="none" stroke="currentColor"` — inherits the CSS color so the icon changes colour on focus and on error automatically.
* `aria-hidden="true" focusable="false"` — keeps the icon out of the accessibility tree.

Developers can add icons without editing the plugin file:

  add_filter( 'cfs_icon_library', function( $icons ) {
      $icons['arrow'] = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" ...>...</svg>';
      return $icons;
  } );

Field icons (`{field}_icon`) are positioned absolutely inside the input wrapper. When an icon is present the wrapper receives the class `cfs-field--has-icon` and the input gets extra left padding so text never overlaps the icon.

Button icons (`button_icon_before`, `button_icon_after`, `modal_button_icon_before`, `modal_button_icon_after`) are rendered inline. Both buttons use `display: inline-flex` with a `gap`, so icons and label text align automatically at any font size.

== Admin Panel ==

Go to **Submissions** in the WordPress admin sidebar:

* **All Submissions** — filterable list with bulk actions: mark as processed, mark as spam, delete. Filters by status (`new` / `processed` / `spam`) and by `form_id`. Sortable by date and status. Paginated at 20 per page.
* **Settings** — extra email recipients, email subject template, banned words list, IP / User-Agent saving toggle, plugin styles toggle, agreement field text.
* **Help** — shortcode quick-reference.

A badge on the menu item shows the count of unread (`new`) submissions, cached for 5 minutes.

== Developer Hooks ==

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

== Changelog ==

= 2.1.0 =

* New field type: `date` with min/max constraint validation.
* New field type: `number` with min/max/step constraint validation.
* HTML5 `pattern` attribute on name/surname/patronymic, phone, and email fields with sensible defaults.
* Pattern override via `{field}_pattern` shortcode attribute for all pattern-enabled fields.
* Client-side validation uses `field.validity` API for date, number, pattern, and type mismatch errors.
* Server-side validation for date format (Y-m-d) and number (is_numeric + min/max).
* Honeypot fields renamed to non-obvious names to prevent browser autofill interference.
* Debug mode: checkbox in settings enables `[CFS]` console logging for form lifecycle debugging.
* Fixed: `wp_localize_script` boolean casting — debug flag now checks both `true` and `"1"`.
* Fixed: CFS_VERSION bumped to bust browser cache on JS/CSS updates.
* Admin: detail page restructured into postbox layout (Applicant / Form Data / Submission Info sections).
* Admin: agreement and checkbox fields hidden from detail view.
* Dashboard widget: 3-counter summary (New / Processed / Spam) + 10 latest submissions with status dots.
* Submit button and modal button accept `button_class` / `modal_button_class` for custom CSS classes.

= 2.0.0 =

* Full rewrite with Singleton architecture and WordPress Coding Standards.
* Dynamic indexed field tokens: `name_2`, `comment_3*`, etc.
* Star (*) notation in the `fields` attribute for inline required/optional control.
* Form configuration cached via transients for AJAX validation.
* Dialog / modal mode using the native `<dialog>` element.
* Agreement field with HTML link support in the label.
* SVG icon library for field inputs and buttons; `cfs_icon_library` filter for custom icons.
* Floating label animation replaces static placeholders.
* Phone mask with cursor-position tracking (pure JS, no libraries).
* Rate-limiting table (5/min, 20/hour per IP).
* CSV export with UTF-8 BOM for Excel compatibility.
* Dashboard widget.
* Full i18n support.
