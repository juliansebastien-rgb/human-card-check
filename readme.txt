=== Human Card Check ===
Contributors: mapage
Tags: captcha, anti-spam, anti-bot, registration, ultimate-member
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Human-friendly card challenge for WordPress registration forms and Ultimate Member.

== Description ==

Human Card Check replaces a traditional captcha with a lightweight card challenge.

Users see three cards and answer a simple visual question such as:

* Where is the king?
* Which card is in the center?
* Do you see the ace?
* How many face cards do you see?
* Which card is the highest?

The plugin is designed to stay simple for humans while making scripted form abuse less predictable.

Features:

* Human-friendly card challenge
* No external captcha provider
* No third-party API calls
* Works with native WordPress registration
* Includes an Ultimate Member registration integration
* Rotating questions and shuffled answers

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/human-card-check` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Test it on the native WordPress registration form or your Ultimate Member registration form.
4. Optional: create a test page with the shortcode `[human_card_check_demo]`.

== Frequently Asked Questions ==

= Does it use Google reCAPTCHA or another external service? =

No. The plugin does not use an external captcha provider.

= Which forms are supported? =

Version 0.2.0 supports the native WordPress registration form and includes an Ultimate Member registration integration.

= Is this a full anti-spam suite? =

No. This plugin focuses on a human verification challenge for registration flows.

== Screenshots ==

1. Card challenge displayed on a registration form.
2. Example question with shuffled answers.

== Changelog ==

= 0.2.0 =
* Added multiple question types.
* Shuffled answer order.
* Improved packaging for public release.
* Added WordPress.org style readme.

= 0.1.0 =
* Initial private MVP release.
