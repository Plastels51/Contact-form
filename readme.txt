=== Contact Form Submissions ===
Contributors: Plastels51
Tags: contact form, form builder, submissions, shortcode, crm
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build forms from a text template, store every submission, and hand it on to mail or a CRM.

== Description ==

A form is a text template you write in the admin. Anything in square brackets becomes a
field; everything else is ordinary HTML, so the layout around the fields is yours.

    <p>Leave a request — we call back within 15 minutes.</p>

    <div class="cfs-row">
        [name* first_name label="Name" icon="user" width="1/2"]
        [phone* phone label="Phone" icon="phone" width="1/2"]
    </div>

    [select* topic label="Subject" options="Consultation:consult,Quote:calc"]
    [textarea comment label="Message" rows="4"]
    [agreement* consent label="I agree to the <a href='/privacy/'>privacy policy</a>"]
    [submit "Send"]

Place it with `[contact_form id="12"]`.

**Fields**

Text, name, surname, patronymic, email, phone, URL, number, date, textarea, select,
radio, checkbox group, checkbox, agreement, hidden, submit button and a step separator
for multi-step forms. Every field takes a label, placeholder, default value, icon, help
text, custom error message and column width.

**After a submission**

Three tabs decide what happens next:

* **After submit** — show a message, redirect, or both; clear the form; close the modal.
* **Mail** — a notification and an auto-reply, each with its own recipients, subject and
  body, with `{field}` substitutions and `{all_fields}`.
* **Integrations** — every installed add-on appears here with its own settings and a
  field mapping table. Enable it per form.

**Submissions**

Stored in a dedicated table with the form's field schema attached, so a submission stays
readable after the form is renamed or deleted. Filter by form and status, view a card
with contacts, form data and technical fields, export CSV with one column per field.

**Spam protection**

Nonce, two honeypots, a timestamp window, referer check, per-IP rate limiting, a banned
word list and a strict whitelist: a field that is not in the form does not exist.

**No dependencies**

Phone mask, modal, multi-step navigation and validation are plain JavaScript. No jQuery
plugins, no CDN, no external fonts.

== Installation ==

1. Upload the `contact-form` folder to `/wp-content/plugins/` and rename it to
   `contact-form-submissions`.
2. Activate the plugin.
3. Go to **Submissions → Forms**, create a form, copy its shortcode into a page.

Upgrading from 2.x: existing `[contact_form fields="…"]` shortcodes keep working. Open
**Submissions → Migration** to turn them into editable forms; the screen also explains
how to remove the compatibility module afterwards.

== Template syntax ==

    [type* name attribute="value"]

* `type` — the field type.
* `*` — makes the field required.
* `name` — latin letters, digits, hyphen and underscore. Omit it and one is generated.
* Attributes are named and may be given in any order.
* `[# comment]` produces nothing; `\[` is a literal bracket.

Common attributes: `label`, `placeholder`, `default`, `class`, `icon`, `help`, `error`,
`role`, `width`. Type-specific ones: `options`, `rows`, `min`, `max`, `step`, `value`,
`source`, `pattern`, `maxlength`, `minlength`, `autocomplete`.

Full reference: **Submissions → Help**.

== Developer hooks ==

    apply_filters( 'cfs_before_save',      $data, $form_id )
    do_action(    'cfs_after_save',        $submission_id, $data )
    apply_filters( 'cfs_validate_field',   $error, $name, $value, $form_id )
    apply_filters( 'cfs_spam_check',       $is_spam, $data, $form_id )
    apply_filters( 'cfs_field_types',      $types )
    apply_filters( 'cfs_icon_library',     $icons )
    apply_filters( 'cfs_template_allowed_html', $tags )
    apply_filters( 'cfs_render_field',     $html, $field, $renderer )
    apply_filters( 'cfs_mail_context',     $context, $data, $form )
    apply_filters( 'cfs_integrations',     $items )
    apply_filters( 'cfs_submission_panels', $panels, $submission )

An integration describes itself once and the plugin draws its settings card, stores the
values, runs it after the submission is saved, retries on failure and logs the result.

== Changelog ==

= 3.0.0 =

Forms

* Forms are now built from a text template in the admin instead of shortcode attributes.
* New editor: tag generator, live preview, template history, JSON import/export.
* Arbitrary field names; HTML of your own around the fields; `.cfs-row` for columns.
* The shortcode is `[contact_form id="12"]` or `[contact_form slug="callback"]`.

After a submission

* Per-form mail templates with `{field}` substitutions, `{all_fields}` and an auto-reply.
* Integration registry: add-ons get a settings card, field mapping, background delivery
  with retries and a per-submission action log.

Submissions

* Each submission stores its own field schema and stays readable after the form changes.
* CSV export has one column per field.

Under the hood

* Only fields declared by the form are accepted; unknown POST keys are dropped.
* The per-form config transient is gone, and with it the failures that followed its
  expiry under page caching.
* 2.x shortcodes keep working through a compatibility module that can be deleted after
  the built-in migration wizard has run.

Fixes

* Option values are no longer coerced to integers, so `options="Yes:1,No:2"` validates.
* Cyrillic checkbox-group values are preserved instead of being emptied.
* A malformed email is reported instead of being silently blanked.
* The banned word list is case-insensitive for non-latin alphabets.
* A bare `<` inside a tag attribute no longer destroys the field when saving.

Upgrade notice: 3.0 changes the database schema and the stored submission format.
Downgrading to 2.x afterwards requires a database backup taken before the update.

= 2.7.0 =

* Multi-step forms; date, number, url and multicheck field types; field style variants.
* Submission meta API and the `cfs_submission_panels` / `cfs_submission_notices` filters
  for add-ons.

= 2.5.0 =

* Radio fields, dashboard widget, CSV export.
