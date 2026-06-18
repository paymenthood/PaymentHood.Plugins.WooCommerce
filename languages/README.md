# Translations

This directory holds the gettext translation files for the PaymentHood gateway.

The plugin loads its text domain (`paymenthood`) from here via
`load_plugin_textdomain()` on the `init` hook.

## Generating / updating the translation template (.pot)

From the plugin root, with [WP-CLI](https://wp-cli.org/) installed:

```bash
wp i18n make-pot . languages/paymenthood.pot --slug=paymenthood
```

Re-run this whenever translatable strings change so the `.pot` stays current.
Translators then create `paymenthood-{locale}.po` / `.mo` files in this folder
(e.g. `paymenthood-de_DE.po`).

> Note: every user-facing string must be wrapped in a gettext call
> (`__()`, `esc_html__()`, `esc_attr__()`, `esc_html_e()`, etc.) with the
> `paymenthood` text domain for it to appear in the `.pot`.
