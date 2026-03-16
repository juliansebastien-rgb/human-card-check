# Human Card Check

Human Card Check is a WordPress plugin that replaces a traditional captcha with a lightweight card challenge.

It currently supports:

- native WordPress registration
- Ultimate Member registration
- a demo shortcode: `[human_card_check_demo]`
- GitHub-based update checks for self-hosted distribution
- an admin setting for French, English, Italian and Spanish

## What it does

Users see three cards and answer a simple visual question such as:

- Where is the king?
- Which card is in the center?
- Do you see the ace?
- How many face cards do you see?
- Which card is the highest?

The plugin is designed to stay easy for humans while making scripted form abuse less predictable.

## Install

1. Upload the plugin to `/wp-content/plugins/human-card-check`
2. Activate it in WordPress
3. Test it on the registration form you want to protect

## Public release

This repository is intended to be the Git source for the plugin.

For WordPress.org distribution:

1. keep `readme.txt` updated
2. tag releases clearly
3. deploy approved releases to the WordPress.org SVN repository

See [WORDPRESS-ORG.md](WORDPRESS-ORG.md) for the short release checklist.

## License

GPL-2.0-or-later
