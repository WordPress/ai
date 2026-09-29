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

Featured Connectors may appear prominently in the AI plugin’s Connectors screen.

Each listing should show:
* Connector and provider name
* Supported capabilities
* Required accounts or costs
* Install or configure action
* Link to the WordPress.org listing

## Ongoing Review

Featured Connectors may be removed if they are no longer maintained, compatible, secure, or compliant with these requirements.  Authors may request another review after resolving any issues.
