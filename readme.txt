=== AI Provider for Mistral ===
Contributors: laurisaarni
Tags: ai, mistral, artificial-intelligence, connector
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.2.1
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
* Text-to-speech with Mistral Voxtral TTS models, including voice cloning from a reference recording
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

**`ai_provider_for_mistral_default_voice_id`**

Filters the voice used for text-to-speech when no voice is set with `asOutputSpeechVoice()`. Defaults to the English preset voice `voice_478` for Voxtral TTS 3 models and `gb_jane_neutral` for older models. The model ID is passed as the second argument.

**`ai_provider_for_mistral_tts_max_text_length`**

Filters the maximum number of characters narrated in a single text-to-speech request (default 2000). Longer text is split at paragraph and sentence boundaries and the audio joined into one file, which requires the MP3 or PCM format. The model ID is passed as the second argument.

== Changelog ==

= n.e.x.t =
* Add text-to-speech with the Mistral Voxtral TTS models, such as Voxtral TTS 3 (`voxtral-mini-tts-3-0`). Use `convertTextToSpeech()` or `generateSpeech()`, choose a voice with `asOutputSpeechVoice()` and a format with `asOutputMimeType()` (MP3, WAV, FLAC, Opus, or PCM).
* Clone a voice for a single request by adding a short audio recording to the prompt.
* Narrate long text in several requests and join the audio into one file.
* New `ai_provider_for_mistral_default_voice_id` and `ai_provider_for_mistral_tts_max_text_length` filters.
* Sort version-numbered model ids (such as `mistral-large-4`) above dated ids (such as `mistral-large-2512`), so the newest models in a family are listed first.
* Only force a tool call on the opening turn of a conversation, so agent loops that replay their transcript as text can get a final answer. A `tool_choice` set through custom options is no longer overwritten. Props @TimVanOnckelen.
* Handle Mistral's `model_length` and `error` finish reasons instead of reporting them as an invalid response. Props @TimVanOnckelen.

= 1.2.1 =
* Fix image generation failing with "Missing the "file_id" key". Mistral now returns generated images as a URL instead of a file ID; both formats are supported.
* Detect the image type from the downloaded data, as Mistral now returns JPEG images.
* Instruct the image generation agent to always generate an image instead of sometimes replying with a follow-up question.
* Include the agent's reply in the error message when no image is returned.

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

This plugin connects to the Mistral AI API (https://api.mistral.ai/v1) to provide AI text generation, image generation, and text-to-speech capabilities.

Data is sent to the Mistral API when your application code makes AI generation requests through the PHP AI Client. The data sent includes your prompts, model configuration, and API key. No data is sent automatically — requests only occur when explicitly triggered by code using the PHP AI Client SDK.

This service is provided by Mistral AI (https://mistral.ai/):
- Terms of Service: https://mistral.ai/terms/
- Privacy Policy: https://mistral.ai/terms/#privacy-policy

== Upgrade Notice ==

= 0.1.0 =
Initial release.
