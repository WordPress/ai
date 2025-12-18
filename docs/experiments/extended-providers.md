# Extended Providers

## Summary
Toggles registration of a custom set of AI providers with the WP AI Client. When the experiment is enabled, any provider classes you supply via filters are registered with `AiClient::defaultRegistry()` so they can participate in model discovery alongside the core providers (OpenAI, Anthropic, Google). Disable the experiment to remove those providers without touching the default stack.

### Included Providers
- Grok (xAI) – exposes Grok’s `/v1/models` listing and chat completion models. Add your Grok API key under **Settings → AI Credentials** (`options-general.php?page=wp-ai-client`) and the registry will inject it automatically.
- Groq – exposes Groq’s `https://api.groq.com/openai/v1` chat-completions interface. Store a **Groq API key** on the credentials screen and toggle the provider inside the Extended Providers experiment.
- Fal.ai – adds curated FLUX/SDXL image generators via `https://fal.run/{model}`. Provide your Fal.ai API token on the AI Credentials page and enable the provider to unlock Fal’s image-only models.
- Cohere – connects directly to Cohere’s `/chat` and `/models` APIs at `https://api.cohere.ai/v1`. Paste your Cohere API key on the credentials screen and use the experiment settings to toggle Cohere’s chat models.
- Hugging Face – targets the OpenAI-compatible router at `https://router.huggingface.co/v1`. Add a Hugging Face access token (with `inference:all` scope) on the credentials page and enable the provider to discover router-backed chat models.
- OpenRouter – connects to `https://openrouter.ai/api/v1`, honoring their `/models` and `/chat/completions` API. Supply your OpenRouter API key under AI Credentials and optionally set Referer/Title via the registry’s custom options filter if needed.
- Ollama – calls your local `http://localhost:11434/api` daemon for chat generation. No cloud credentials required; just install/serve models via Ollama and enable the provider to expose them in the registry. Use the `ai_ollama_base_url` filter if you need a custom host.
- DeepSeek – uses the `https://api.deepseek.com/v1` OpenAI-compatible surface. Create a DeepSeek API key, paste it on the AI Credentials page, and the models listed under `/v1/models` will automatically flow into discovery.
- Cloudflare Workers AI – calls `https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/*` for model listing and inferencing. Generate a Workers AI API token plus note your Account ID (expose it via the `CLOUDFLARE_ACCOUNT_ID` environment variable or the `ai_cloudflare_account_id` filter) and provide the token through the AI Credentials screen.

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
