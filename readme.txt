=== AI Experiments ===
Contributors:      wordpressorg
Tags:              ai, artificial intelligence, experiments, abilities, mcp
Tested up to:      6.9
Stable tag:        0.1.1
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

AI experiments and capabilities for WordPress.

== Description ==

AI Experiments is a plugin for testing and developing AI-powered experiments for WordPress. This plugin provides a framework for building, testing, and deploying experimental AI capabilities.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ai` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to `Settings -> AI Credentials` and add at least one valid AI credential.
4. Go to `Settings -> AI Experiments` and globally enable experiments and then enable the individual experiments you want to test.
5. Start experimenting with AI features! For the Title Generation experiment, edit a post and click into the title field. You should see a `Generate/Re-generate` button above the field. Click that button and after the request is complete, title suggestions will be displayed in a modal. Choose the title you like and click the `Select` button to insert it into the title field.

== Frequently Asked Questions ==

= What is this plugin for? =

This plugin is for experimenting with AI features in WordPress. It provides a framework for building and testing AI-powered capabilities.

= Is this production-ready? =

No, this is an experimental plugin for testing and development purposes.

== Screenshots ==

1. Post editor showing `Generate` button above the post title field.
2. Post editor showing generated title recommendations in a modal.
3. Post editor showing generated title applied to the post and updated `Re-generate` button.
4. AI Experiments settings screen showing toggles to enable specific experiments.
5. AI Credentials settings screen showing API key fields for available AI service providers.

== Changelog ==

= 0.1.1 - 2025-12-01 =

* **Added:** Link to the plugin settings screen from the plugin list table ([#98](https://github.com/WordPress/ai/pull/98)).
* **Added:** WordPress Playground live preview integration ([#85](https://github.com/WordPress/ai/pull/85)).
* **Added:** RTL language support and inlining for performance ([#113](https://github.com/WordPress/ai/pull/113)).
* **Changed:** Updated namespace to `ai_experiments` ([#111](https://github.com/WordPress/ai/pull/111)).
* **Changed:** Bumped WP AI Client from `dev-trunk` to 0.2.0 ([#118](https://github.com/WordPress/ai/pull/118), [#122](https://github.com/WordPress/ai/pull/122), [#125](https://github.com/WordPress/ai/pull/125)).
* **Removed:** Valid AI credentials check from the Experiment `is_enabled` check ([#120](https://github.com/WordPress/ai/pull/120)).
* **Removed:** Example Experiment registration ([#121](https://github.com/WordPress/ai/pull/121)).
* **Fixed:** Bug in asset loader causing missing dependencies ([#113](https://github.com/WordPress/ai/pull/113)).
* **Security:** Bumped `js-yaml` from 3.14.1 to 3.14.2 ([#105](https://github.com/WordPress/ai/pull/105)).

= 0.1.0 - 2025-11-26 =

First public release of the AI Experiments plugin, introducing a framework for exploring experimental AI-powered features in WordPress. 🎉

* **Added:** Experiment registry and loader system for managing AI features
* **Added:** Abstract experiment base class for consistent feature development
* **Added:** Experiment: Title Generation
* **Added:** Basic admin settings screen with toggle support
* **Added:** Initial integration with WP AI Client SDK and Abilities API
* **Added:** Utilities Ability for common AI tasks and testing
