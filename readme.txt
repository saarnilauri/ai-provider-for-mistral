=== AI Provider for Mistral ===
Contributors: laurisaarni
Tags: ai, mistral, artificial-intelligence, connector
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WordPress AI Client provider for Mistral.

== Description ==

This plugin provides a third-party Mistral integration for the PHP AI Client SDK. It enables WordPress sites to use Mistral models for text generation and related AI capabilities.
It is not affiliated with, endorsed by, or sponsored by Mistral AI.

**Features:**

* Text generation with Mistral models
* Image generation with Mistral mistral-medium-2505 model
* Function calling support (for compatible models)
* Vision input support (for compatible models)
* Automatic provider registration

Available models are dynamically discovered from the Mistral API.

**Requirements:**

* PHP 7.4 or higher
* PHP AI Client plugin must be installed and activated
* Mistral API key

== Installation ==

1. Ensure the PHP AI Client plugin is installed and activated
2. Upload the plugin files to `/wp-content/plugins/ai-provider-for-mistral/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure your Mistral API key via the `MISTRAL_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get a Mistral API key? =

Visit the [Mistral Console](https://console.mistral.ai/) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It provides the Mistral-specific implementation that the PHP AI Client uses.

== For Developers ==

= Filters =

**`ai_provider_for_mistral_model_sort_callback`**

Filters the comparator used to order Mistral models in the metadata directory. Return any callable with the `usort()` signature `function (ModelMetadata $a, ModelMetadata $b): int`. The default comparator, the directory instance, and the unsorted model list are passed as context, so your filter can delegate to the default for everything except the cases you want to override.

Example — pin `codestral-latest` to the top and fall back to the default order for everything else:

`
add_filter(
    'ai_provider_for_mistral_model_sort_callback',
    function ( callable $default ): callable {
        return function ( $a, $b ) use ( $default ) {
            if ( $a->getId() === 'codestral-latest' ) return -1;
            if ( $b->getId() === 'codestral-latest' ) return 1;
            return $default( $a, $b );
        };
    }
);
`

== Changelog ==

= 1.2.0 =
* Default sort now surfaces flagship Mistral models (Large, Medium, Magistral, Codestral, …) first and sinks legacy open-weights and non-chat models to the bottom.
* New `ai_provider_for_mistral_model_sort_callback` filter lets integrators replace the sort comparator.
* Declare document input modality on all chat models and serialize `{type: "document_url"}` content chunks — document QnA now works through any chat model via a remote URL (upload via the Files API for private documents).
* Declare audio input modality on Voxtral models so the AI Client surfaces them as audio-capable chat models.

= 1.1.1 =
* Fix an issue with the image generation model

= 1.1.0 =
* Refactor to follow wp.org plugin guidelines

= 1.0.2 =
* Add provider description and logo for connector screen

= 1.0.1 =
* Clean up plugin distribution

= 1.0.0 =
* Adjust the tool calling to work with Mistral API.
* Redesign banners and icons

= 0.3.3 =
* Fix image generation model loading when using as a plugin.

= 0.3.2 =
* Add support for image generation
* Fix issue in output schema

= 0.3.1 =
* Add support for output schema

= 0.3.0 =
* Rename plugin to "AI Provider for Mistral" (new slug: ai-provider-for-mistral, new namespace: AiProviderForMistral)
* Throw TokenLimitReachedException when Mistral returns finish_reason "length"

= 0.2.0 =
* Rewrite the plugin to use better naming that reflects that this is independent product and it is not official Mistral product.
* Add unit and integration tests

= 0.1.1 - 0.1.3 =
Improve package / plugin distribution

= 0.1.0 =
* Initial release
* Support for Mistral text generation models
* Function calling support for compatible models
* Vision input support for compatible models

== External services ==

This plugin connects to the Mistral AI API (https://api.mistral.ai/v1) to provide AI text generation and image generation capabilities.

Data is sent to the Mistral API when your application code makes AI generation requests through the PHP AI Client. The data sent includes your prompts, model configuration, and API key. No data is sent automatically — requests only occur when explicitly triggered by code using the PHP AI Client SDK.

This service is provided by Mistral AI (https://mistral.ai/):
- Terms of Service: https://mistral.ai/terms/
- Privacy Policy: https://mistral.ai/terms/#privacy-policy

== Upgrade Notice ==

= 0.1.0 =
Initial release.
