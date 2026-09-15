=== Contact Form Submissions ===
Contributors: Plastels51
Tags: contact form, form builder, submissions, shortcode, crm
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 4.5.0
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

A form with `container="dialog"` always renders as `<dialog ...
aria-labelledby="dialog-label">`. Write the heading it points at as the first line of the
template and the window gets both a visible title and a name for the screen reader:

    <h2 id="dialog-label">Tell us what happened</h2>

Without the ID nothing breaks — a reference that resolves to no element is skipped when
the accessible name is computed — but the window stays unnamed and the reader starts
straight at the first field. The ID is fixed, so it names one window per page.

== Colours ==

Colours are CSS variables read from the form wrapper — set them in the theme and leave the
plugin stylesheet alone:

    .cfs-form-wrap {
        --cfs-label: #6e6459;
        --cfs-text:  #4a423a;
    }

`--cfs-text` is the text the visitor types; `--cfs-label` is the label, which at rest sits
in the very place that text will appear, so the two are separate colours.
`--cfs-label-active` takes over once the label is raised. Then `--cfs-placeholder`,
`--cfs-hint`, `--cfs-legend`, `--cfs-text-muted`, `--cfs-stepper-text`, `--cfs-error-dark`
for the rest of the text, and `--cfs-accent`, `--cfs-bg`, `--cfs-border`, `--cfs-radius`,
`--cfs-btn-*` for everything that is not text. Set none of them and the look is unchanged.

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

= 4.5.0 =

**Your icon directory no longer costs you the icons it does not contain.** A directory
configured in Settings used to replace the built-in set outright. The icons that suffered
were the ones no template mentions: the modal's `close` and the wizard's two chevrons are
picked by the plugin, not by the author, so a folder holding four hand-drawn field icons
silently emptied the close button and both step buttons. The directory is now layered over
the built-in set name by name — `user.svg` replaces the built-in `user`, and every name the
folder does not carry keeps the icon it had. The settings screen reports how many icons the
directory gave, how many of them replaced a built-in, and how large the resulting set is.

A site that does want its folder to be the only source still says so, with the filter that
runs last either way:

    add_filter( 'cfs_icon_library', function ( array $icons ): array {
        $own = CFS_Icons::directory_icons();
        return empty( $own ) ? $icons : $own;
    } );

**The help screen was rewritten.** It now covers the whole plugin rather than the template
syntax alone: the shortcode attributes, the common attributes with what each one does,
field widths and roles, `[unstep]`, icons and the icon directory, the CSS classes of every
button the plugin draws, the After-submit tab, mail placeholders, integrations, the export
and the checks a submission passes on the way in. A contents list links the sections.

**CSS classes for the buttons, written down at last.** `[submit]` takes `class`; the next,
back, modal-trigger and modal-close buttons take theirs on the Дополнительно tab. The help
screen now lists each button, where its classes are set and the base classes it always
carries — including that the back button is `cfs-btn--back`, not `cfs-btn--prev`.

Reference sections are generated from the registries they describe — field types, common
attributes, accepted widths, icon names, reserved field names — so they cannot drift from
the code the way a hand-written list does.

= 4.4.0 =

**The stylesheet stopped out-shouting themes.** Every `:not()` chain now sits inside
`:where()`, which contributes no specificity of its own. The selectors match exactly the
same elements; they simply stop counting their own exclusions as weight.

* The heaviest selector went from ten classes to four. `.cfs-style--left-label .cfs-field`
  with its seven `:not()`s used to score (0,10,1), so a theme needed eleven classes to say
  anything about that label. It now scores (0,2,1).
* Nothing scores above four classes any more, and the (0,9)/(0,10) band is empty.
* Verified rather than assumed: computed styles were captured for all six field skins —
  105 elements, 45 properties each, resting and floated label — before and after. Every
  skin hashed identically, so the change is specificity-only.

Themes that were written to out-specify the old selectors keep working: they are still
more specific than what replaced them.

**Chevrons for the wizard.** `angle-small-left` and `angle-small-right` join the icon
library and become the defaults for Back and Next, replacing the full arrows. A chevron
points at the next part of the same form; an arrow reads as leaving it, which is what the
submit button means. Forms that already stored an icon keep the one they stored.

= 4.3.0 =

**`[unstep]` — content that stays put while the steps change.** `[step]` divides a template
into panels and the wizard shows one at a time, so anything between two `[step]` tags
disappears with its panel. A heading for the form was the obvious casualty: written into the
first step, it was gone by the second. Everything after `[unstep]` belongs to no step and
renders outside the panels.

    [unstep]
    <h2 id="dialog-label">Tell us what happened</h2>

    [step label="Details"]
    [name* first_name label="Name"]

    [step label="Your question"]
    [textarea* message label="Question"]
    [submit "Send"]

* A region opened by `[unstep]` is closed by the next `[step]`, so the two alternate and a
  step's contents are never split across two panels.
* Content before the first `[step]` is placed above the progress bar. The bar is never
  written in the template — the plugin injects it — so the template has no way to position
  itself against it, and the leading run is lifted out.
* A field inside an `[unstep]` region is on screen throughout and is checked when the form
  is submitted rather than when a step is left. It counts towards no step.
* Two `[step]` tags with nothing between them no longer leave a blank panel: the second
  renames the step instead of closing it. That also fixed a mismatch where the progress bar
  counted a step the wizard had no panel for.

**Schema version 5.** Compiled schemas are rebuilt on first read; no action needed.

= 4.2.0 =

**Your own icons.** *Submissions → Настройки* takes a directory, relative to `wp-content`,
and every `*.svg` in it becomes an icon offered wherever icons are chosen. The file name is
the icon name. A directory replaces the built-in set outright — the point of the setting is
that a site keeps one icon source rather than two.

* The path is refused unless it resolves inside `wp-content`, and the check is repeated on
  read: an option written straight into the database by WP-CLI or a migration is not
  trusted either.
* Every file is sanitised on the way in, against an allowlist of drawing elements. Up to
  now the plugin only ever inlined its own hand-written strings and had nothing to defend;
  reading files off disk changes that, because an SVG can carry `<script>`, event handlers,
  `<foreignObject>` and `javascript:` references. `<use>`, `<image>`, `<a>`, `<style>`, the
  animation elements and every form of `href` are dropped — an icon needs none of them.
* Text nodes are dropped too. `wp_kses()` removes a forbidden tag but keeps what was inside
  it, which is right for prose and wrong for an icon: without that step the body of a
  `<script>` survives its own tag as inert but attacker-controlled text.
* The directory is cached against the names and modification times of its files, so an
  untouched folder costs one `glob()` per request and editing an icon is picked up by
  itself.
* A directory that yields nothing keeps the built-in set and says so on the settings
  screen, rather than leaving every form on the site without icons.

= 4.1.0 =

**Fixed: the wizard's buttons ignored being hidden.** Every button in the stylesheet
declares `display`, and an author declaration outranks the `display: none` a browser
attaches to `[hidden]`. The wizard set the attribute correctly and nothing happened on
screen, so "Назад" stayed on the first step and "Отправить" sat next to "Далее" on every
step but the last. The buttons now agree to disappear.

* Back, next and the modal's close button take icons and CSS classes of their own, set on
  **Дополнительно** the way the modal trigger already was. Back and next default to
  `arrow-left` and `arrow-right`.
* The close button is an icon instead of a `✕` character, and `close` joins the icon
  library. A character is whatever shape the theme's font gives it and carries the button's
  text metrics rather than a size of its own.
* Field icons align to the first line of text instead of being centred on the control. One
  offset now serves inputs, selects and textareas, and a textarea that grows keeps its icon
  beside the first line instead of drifting towards the middle.
* Floating labels no longer clip themselves with an ellipsis, and the declarations that
  existed only to undo that clipping in four field styles are gone with it.

= 4.0.0 =

**Breaking.** The config the front-end script reads has moved and lost a key. Custom
JavaScript that read `data-cfs-config` off the form, or the `label` of a field inside it,
has to be updated. Nothing else changes: the shortcode, the template syntax, the hooks and
the submitted data are the same.

* The config now travels in `<script type="application/json">`; the form points at it with
  `data-cfs-config-id`. An attribute value must escape every quote as `&quot;`, and the
  config is mostly quotes. The plugin's own script still reads the old attribute as a
  fallback, so a page cached before the upgrade keeps working.
* Field labels are gone from the config. Nothing read them — a field is named by the
  `<label>` standing beside it — and for an agreement the label is a whole paragraph that
  was shipped twice.
* The JSON keeps Unicode and slashes unescaped. A Russian label written out as `\uXXXX`
  costs three times its own length.

On one real page the three together take the config from 6641 to 1992 bytes, and the whole
document from 92.1 to 87.5 KB — 643 bytes of it after gzip.

= 3.2.0 =

* Label ink is its own colour. `--cfs-label` sets the label, `--cfs-text` the text the visitor types — until now both came from `--cfs-text` and could not be told apart. `--cfs-label-active` colours the raised label.
* Two variables freed along the way: native placeholders now read `--cfs-placeholder` and the inactive steps of the wizard `--cfs-stepper-text`. Both used to borrow `--cfs-label`. Set either of them if you had coloured placeholders or the stepper through it.
* Defaults are unchanged: with none of the new variables set, every field style keeps the colour it had.

= 3.1.0 =

* Modal forms carry `aria-labelledby="dialog-label"`. Give the heading in the template that ID and the window is announced by it — see Template syntax.
* Floating label moves up again: the floated-state rule was dropped from the base CSS in 3.0.0, so the label stayed sitting on the input in every field style.
* Underline style: the textarea now grows with its content instead of standing as a tall empty box above the line (Chromium; other browsers keep the fixed height).

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
