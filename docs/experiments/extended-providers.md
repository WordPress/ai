# Extended Providers

## Summary
Toggles registration of a custom set of AI providers with the WP AI Client. When the experiment is enabled, any provider classes you supply via filters are registered with `AiClient::defaultRegistry()` so they can participate in model discovery alongside the core providers (OpenAI, Anthropic, Google). Disable the experiment to remove those providers without touching the default stack.

### Included Providers
- Grok (xAI) – exposes Grok’s `/v1/models` listing and chat completion models. Add your Grok API key under **Settings → AI Credentials** (`options-general.php?page=wp-ai-client`) and the registry will inject it automatically.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Extended_Providers\Extended_Providers::register()` attaches to `init` (priority 20) and calls `register_providers()` only when the experiment is enabled.
- `ai_extended_provider_default_classes` – Filter the default list of provider class names bundled with the experiment (defaults to `WordPress\AI\Providers\Grok\GrokProvider`).
- `ai_extended_provider_classes` – Final filter to adjust the provider class list before registration. Receives the experiment instance so you can inspect settings if needed.

```php
add_filter( 'ai_extended_provider_classes', function( $providers ) {
	$providers[] = \MyPlugin\Providers\OpenRouterProvider::class;
	$providers[] = \MyPlugin\Providers\TogetherAiProvider::class;
	return $providers;
} );
```

## Assets & Data Flow
No scripts or abilities are enqueued. The experiment simply calls `AiClient::defaultRegistry()->registerProvider()` for each class in the filtered list. Provider classes remain responsible for their own HTTP transport and credential handling (the WP AI Client will inject the WordPress HTTP transporter and default API-key authentication automatically).

## Testing
1. Enable Experiments globally and toggle **Extended Providers** under `Settings → AI Experiments`.
2. Add your provider classes via the `ai_extended_provider_classes` filter.
3. Visit any screen that uses the AI Client and confirm the new provider appears in `AiClient::defaultRegistry()->getRegisteredProviderIds()`.
4. Disable the experiment and confirm the provider list reverts to the core set (OpenAI, Anthropic, Google).

## Notes
- Only classes implementing `WordPress\AiClient\Providers\Contracts\ProviderInterface` are accepted. Missing or invalid classes trigger `_doing_it_wrong()` notices.
- The experiment does not ship provider implementations; it is simply a safe switch for loading your own provider packages or forks.
