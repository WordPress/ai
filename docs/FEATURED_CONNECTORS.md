# Featured Connector Plugins

The WordPress AI plugin may feature Connector plugins that add support for additional AI providers beyond the three that come referenced in WordPress Core (i.e. [Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/), [Google](https://wordpress.org/plugins/ai-provider-for-google/), [OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/)).  Featured plugins are reviewed by the WordPress AI plugin maintainers.  Featuring a plugin does not represent an official endorsement or guarantee continued inclusion.

## Eligibility

A Connector plugin may be considered when it:
* Is available in the WordPress.org Plugin Directory
* Uses the `ai` and `connector` tags
* Integrates through the WordPress AI Client SDK
* Supports the provider capabilities available through the SDK
* Provides its main Connector functionality without requiring a paid plugin
* Stores credentials securely
* Clearly explains any required accounts or usage costs
* Follows WordPress.org plugin guidelines
* Is actively maintained and compatible with supported versions of WordPress and the AI plugin

Paid AI services are allowed.  The Connector plugin itself must not require a paid upgrade for its core integration.

## Requesting Review

Plugin authors can request consideration by opening an issue in the WordPress AI GitHub repository.  The request should include:
* Plugin name and WordPress.org URL
* Source repository
* Provider and supported capabilities
* Setup and testing instructions
* Credential storage details
* Required accounts or costs
* Current compatibility and known limitations

Submitting a request does not guarantee inclusion.

## Review

Maintainers may test the plugin, review its setup and security, and request changes before making a decision.  The review and decision should be documented in the GitHub issue.

## Display

Featured Connectors appear on `Settings -> Connectors` alongside the connectors WordPress core lists by default, using the same card.  The AI plugin sorts all connectors on that screen by name.  Before the Connector plugin is installed, the card offers to install it, then to activate it, then to set up its API key, just like the core defaults.

Each listing shows:
* Connector name and logo, matching what the Connector plugin shows once it is installed
* A short description naming its capabilities and any required account or usage costs
* An install, activate or set up action
* A link to the WordPress.org listing, when the current user cannot install plugins

Once the Connector plugin is active, WordPress core lists it from the plugin's own details and the AI plugin no longer adds anything for it.  Removing a connector from the featured list does not affect sites that already installed it.

## Currently Featured

| Connector | WordPress.org | Provider ID | Reviewed |
| --- | --- | --- | --- |
| Mistral | [ai-provider-for-mistral](https://wordpress.org/plugins/ai-provider-for-mistral/) | `mistral` | [#27](https://github.com/WordPress/ai/issues/27) |
| Vercel AI Gateway | [vercel-ai-gateway-provider](https://wordpress.org/plugins/vercel-ai-gateway-provider/) | `ai_gateway` | [#27](https://github.com/WordPress/ai/issues/27) |

Featured Connectors must support image generation, so the plugin's Image Generation feature works with them.

## Changing the Featured List

Hosts and agencies can change which connectors are featured with the `wpai_featured_connectors` filter.  Each key is the provider ID the Connector plugin registers with the WordPress AI Client, which must match exactly.

```php
// Feature only a host's own Connector plugin.
add_filter(
	'wpai_featured_connectors',
	static function ( array $connectors ): array {
		return array(
			'my_host_ai' => array(
				'name'            => 'My Host AI',
				'description'     => 'Text and image generation included with your hosting plan.',
				'plugin_file'     => 'my-host-ai-provider/plugin.php',
				'credentials_url' => 'https://example.com/account/api-keys',
				'logo_url'        => 'https://example.com/logo.svg',
			),
		);
	}
);

// Feature no extra connectors.
add_filter( 'wpai_featured_connectors', '__return_empty_array' );
```

Only `name` and `plugin_file` are required.  The folder in `plugin_file` must be the plugin's WordPress.org slug, because the screen uses it to install the plugin and to link to its WordPress.org listing.  A plugin that is not on WordPress.org must already be installed on the site, or its Install action and listing link will not work.  Entries with an invalid ID, name or plugin file are skipped and trigger a `_doing_it_wrong()` notice.  A filter like this would ideally live in WordPress core alongside its own default connectors; until then it only affects the connectors the AI plugin adds.

## Ongoing Review

Featured Connectors may be removed if they are no longer maintained, compatible, secure, or compliant with these requirements.  Authors may request another review after resolving any issues.
