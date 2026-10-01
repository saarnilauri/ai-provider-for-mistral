# AI Provider for Mistral

A third-party provider for [Mistral](https://mistral.ai/) in the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

This project is independent and is not affiliated with, endorsed by, or sponsored by Mistral AI.

## Requirements

- PHP 7.4 or higher
- [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) must be installed

## Installation

### As a Composer Package

```bash
composer require saarnilauri/ai-provider-for-mistral
```

The Composer distribution is intended for library usage and excludes `ai-provider-for-mistral.php`.

### As a WordPress Plugin

1. Download `ai-provider-for-mistral.zip` from [GitHub Releases](https://github.com/saarnilauri/ai-provider-for-mistral/releases) (do not use GitHub "Source code" archives)
2. Upload the ZIP in WordPress admin via Plugins > Add New Plugin > Upload Plugin
3. Ensure the PHP AI Client plugin is installed and activated
4. Activate the plugin through the WordPress admin

#### Installing with WP-CLI

Use the release ZIP URL from GitHub Releases — **not** the auto-generated `main.zip` source archive, which is missing the bundled dependencies:

```bash
wp plugin install https://github.com/saarnilauri/ai-provider-for-mistral/releases/download/v1.0.1/ai-provider-for-mistral.zip --activate
```

Replace `v1.0.1` with the desired release tag.

## Building the Plugin ZIP

Build a distributable plugin archive locally:

```bash
make dist
# or:
./scripts/build-plugin-zip.sh
```

The ZIP is created at `dist/ai-provider-for-mistral.zip` and includes `ai-provider-for-mistral.php`.

## Testing

Install development dependencies:

```bash
composer install
```

Run unit tests:

```bash
composer test
# or:
composer test:unit
```

Run integration tests (requires `MISTRAL_API_KEY`):

```bash
composer test:integration
```

## Release Workflow

This repository includes a GitHub Actions workflow at `.github/workflows/release-plugin-zip.yml`:

- On tag pushes matching `v*`, it builds `dist/ai-provider-for-mistral.zip`
- For tagged releases, it derives the version from the tag (for example `v0.1.0` -> `0.1.0`) and validates committed metadata:
  - `readme.txt` `Stable tag` must match the tag version
  - `ai-provider-for-mistral.php` `Version` must match the tag version
- If versions do not match, the workflow fails
- It uploads the ZIP as a workflow artifact
- It attaches the ZIP to the GitHub release for that tag

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply ensure both plugins are active and configure your API key:

```php
// Set your Mistral API key (or use the MISTRAL_API_KEY environment variable)
putenv('MISTRAL_API_KEY=your-api-key');

// Use the provider
$result = AiClient::prompt('Hello, world!')
    ->usingProvider('mistral')
    ->generateTextResult();
```

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(ProviderForMistral::class);

// Set your API key
putenv('MISTRAL_API_KEY=your-api-key');

// Generate text
$result = AiClient::prompt('Explain quantum computing')
    ->usingProvider('mistral')
    ->generateTextResult();

echo $result->toText();
```

### Image Generation

Mistral supports image generation through its [Agents API](https://docs.mistral.ai/agents/tools/built-in/image_generation). The provider handles the multi-step flow (agent creation, conversation, file download) automatically:

```php
$result = AiClient::prompt('A red apple on a white background')
    ->usingProvider('mistral')
    ->generateImageResult();

// Get the generated image as base64-encoded PNG
$file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
$binaryData = base64_decode($file->getBase64Data(), true);
file_put_contents('apple.png', $binaryData);
```

### Embeddings

Mistral generates [text embeddings](https://docs.mistral.ai/studio/knowledge-rag/embeddings/text_embeddings) through its `/v1/embeddings` endpoint. One vector comes back per input, in the order the inputs were given.

Always name the embedding model. Vectors are only comparable with other vectors from the same model, so a stored corpus is tied to the model that produced it, and letting the library pick could quietly make new vectors incompatible with the ones already saved.

```php
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;

$embeddings = AiClient::input([
    'The cat sat on the mat.',
    'Kubernetes schedules containers.',
])
    ->usingModel(ProviderForMistral::model('mistral-embed'))
    ->generateEmbeddings();

// 1024 floats per input, from mistral-embed.
count($embeddings[0]->getValues());
```

`mistral-embed` returns a fixed 1024 dimensions. `codestral-embed` is tuned for source code, returns 1536 by default, and is the only Mistral model that accepts a shortened vector:

```php
$embedding = AiClient::input('function add($a, $b) { return $a + $b; }')
    ->usingModel(ProviderForMistral::model('codestral-embed'))
    ->usingDimensions(256)
    ->generateEmbedding();
```

Asking `mistral-embed` for a shortened vector is an error, since it has no such feature.

A shortened vector is not normalised to unit length. A 256-dimension `codestral-embed` vector comes back with a length of about 0.9, so compare shortened vectors with cosine similarity, or renormalise them before using a plain dot product.

`codestral-embed` can also return quantised vectors through its `output_dtype` parameter, which accepts `float` (the default), `int8`, `uint8`, `binary` and `ubinary`. The builder has no method for it, so pass it as a custom option on the model:

```php
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

$model = ProviderForMistral::model(
    'codestral-embed',
    ModelConfig::fromArray(['customOptions' => ['output_dtype' => 'int8']])
);

$embedding = AiClient::input('function add($a, $b) { return $a + $b; }')
    ->usingModel($model)
    ->usingDimensions(256)
    ->generateEmbedding();
```

`binary` and `ubinary` pack eight dimensions into each integer, so asking for 256 dimensions returns 32 values, and `getDimensions()` reports 32: the number of values returned, not the number of dimensions requested.

The endpoint takes at most 256 inputs per request. Larger inputs are split across several requests automatically and come back as one result, in input order, with the token usage summed. Two limits are left to the API to enforce, because checking them needs Mistral's tokenizer: each input may be at most 8192 tokens, and each request of up to 256 inputs at most about 64k tokens in total. Going over either fails with an error from Mistral asking you to split the input.

Embeddings need PHP AI Client 1.4.0 or newer. WordPress 7.1 bundles 1.3.1, which has no embedding support at all, so on that release the embedding models are listed without the capability and text and image generation carry on unaffected.

## Supported Models

Available models are dynamically discovered from the Mistral API. This includes text models and, for compatible models, vision and function-calling capabilities. Image generation is supported through models like `mistral-medium-2505`, and embeddings through `mistral-embed` and `codestral-embed`. See the [Mistral documentation](https://docs.mistral.ai/) for the full list of available models.

## Configuration

The provider uses the `MISTRAL_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('MISTRAL_API_KEY=your-api-key');
```

## External Services

This plugin connects to the [Mistral AI API](https://api.mistral.ai/v1) to provide AI text generation, image generation and embedding capabilities.

Data is sent to the Mistral API when your application code makes AI generation requests through the PHP AI Client. The data sent includes your prompts, model configuration, and API key. No data is sent automatically — requests only occur when explicitly triggered by code using the PHP AI Client SDK.

This service is provided by [Mistral AI](https://mistral.ai/):
- [Terms of Service](https://mistral.ai/terms/)
- [Privacy Policy](https://mistral.ai/terms/#privacy-policy)

## License

GPL-2.0-or-later
