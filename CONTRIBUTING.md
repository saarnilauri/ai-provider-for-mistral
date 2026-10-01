# Contributing

Thanks for helping improve AI Provider for Mistral. Bug reports, fixes and new features
are all welcome. For a larger change, open an issue first so we can agree on the
approach before you spend time on it.

Installing the development dependencies and running the tests are covered in the
README's [Testing](README.md#testing) section.

## Version markers

Never guess the version a change will ship in. Use the `n.e.x.t` placeholder, and the
real version number is filled in when the release is cut.

- New classes, methods, constants, filters and options:

  ```php
  /**
   * Splits the input into batches the embeddings endpoint accepts.
   *
   * @since n.e.x.t
   */
  ```

- A changed existing API keeps its original `@since` and gains a line saying what
  changed:

  ```php
  * @since 1.0.0
  * @since n.e.x.t Declares audio input on Voxtral models.
  ```

- Filters get a `@since` in the docblock above the `apply_filters()` call.

- Changelog entries go under a `= n.e.x.t =` heading at the top of the
  `== Changelog ==` section in `readme.txt`. Add the heading if it isn't there yet.

Leave the plugin header `Version:` and the readme `Stable tag:` alone. They change
only as part of a release.

## Changelog

Every change a site owner or developer could notice gets a `readme.txt` changelog
entry: new features, behaviour changes, new filters, newly supported models and bug
fixes. Write it for the person reading it, not the person who wrote the code. Say what
changed and why it matters, and name any filter or model involved:

```
* Declare audio input modality on Voxtral models so the AI Client surfaces them as audio-capable chat models.
```

Internal refactors, test-only changes and tooling tweaks don't need an entry.

A new filter is also documented in the `== For Developers ==` section of `readme.txt`,
with what it receives, what it must return, and a short example.

## Compatibility

The plugin runs in two contexts, and every change has to work in both.

- **As a WordPress plugin.** It registers itself as a provider on `init`. The PHP AI
  Client SDK comes from WordPress core or from the PHP AI Client plugin.
- **As a Composer package outside WordPress** (`saarnilauri/ai-provider-for-mistral`),
  used directly with the PHP AI Client. See [As a Standalone Package](README.md#as-a-standalone-package).
  Here no WordPress function exists at all.

So:

- Guard every WordPress function call with `function_exists()`, and give the code a
  sensible behaviour without it, as the model sort filter does:

  ```php
  if (function_exists('apply_filters')) {
      $sortCallback = apply_filters('ai_provider_for_mistral_model_sort_callback', $sortCallback, $this, $models);
  }
  ```

- Code under `src/` must not depend on WordPress being loaded. WordPress-only wiring
  belongs in the plugin bootstrap, `ai-provider-for-mistral.php`.
- Settings should be configurable without WordPress, the way the API key is read from
  the `MISTRAL_API_KEY` environment variable.
- The minimum PHP version is **7.4**. `composer lint` runs PHPCompatibility against it,
  so features such as enums, `readonly`, `match` and named arguments fail the lint.
  `array_is_list()`, `str_contains()`, `str_starts_with()` and `str_ends_with()` are
  fine: the PHP AI Client SDK polyfills them, and it is always loaded alongside the
  provider.
- The plugin supports **PHP AI Client SDK 1.3.1 and later** (the range is pinned in
  `composer.json`), because 1.3.1 is the version WordPress 7.1 bundles. A feature
  that needs a newer SDK must check the SDK version first and stay unavailable, not
  fail, on older ones. Embedding generation, which needs 1.4.0, is the example.

## Public API stability

These names are a public API. Existing sites and integrations depend on them, so they
don't change:

- the `ai_provider_for_mistral_` prefix of filters, such as
  `ai_provider_for_mistral_model_sort_callback`, and the arguments each filter passes;
- the `MISTRAL_API_KEY` environment variable;
- the provider ID `mistral`;
- the `SaarniLauri\AiProviderForMistral\` namespace and public method signatures.

Adding to them is fine. Renaming or removing one is a breaking change: discuss it in an
issue first.

## Before opening a pull request

```bash
composer test   # unit tests
composer lint   # PHPCS (PSR-12 + PHPCompatibility) and PHPStan
```

Both must pass. They aren't run automatically on pull requests, so run them locally.

- **Add or update unit tests** for the behaviour you changed. Unit tests run without
  WordPress. A test that needs WordPress functions should define minimal stand-ins and
  run in a separate process (`@runTestsInSeparateProcesses`), so those global functions
  can't leak into other tests.
- **Integration tests** call the live Mistral API with the key from `MISTRAL_API_KEY`,
  read from your environment or from a `.env` file in the repository root (gitignored).
  **Every integration test makes billed requests** and uses your account's credits;
  image generation costs the most. Run just the ones you need:

  ```bash
  composer test:integration -- --filter TextGenerationIntegrationTest
  ```

- **Code style** follows the existing code: PSR-12, camelCase variables, typed
  parameters and return values, and a docblock with `@since` on every class, method and
  filter.

## WordPress.org requirements

The plugin is listed in the WordPress.org directory, so a few of its rules apply to
every change:

- **Document every external request.** A new Mistral endpoint, or new data sent to an
  existing one, goes into the "External services" section of `readme.txt` and the
  matching section of the README: what is sent, and when.
- **Nothing is sent unless site code asks for it.** No telemetry, analytics or
  phone-home requests.
- **Leave credentials to WordPress and the AI Client.** Don't read the Connectors
  options or the AI Client's credential storage directly.
- **Escape output and sanitize input** in any code that renders HTML or accepts
  requests.

## Commits and pull requests

- Write commit subjects in the imperative ("Add …", "Fix …"), with a body explaining why
  the change is needed when that isn't obvious from the subject.
- Keep each pull request to one change. Unrelated fixes go in their own PR.
- In the description, say what changed, why, and how you tested it, including which
  integration tests you ran, if any.

## Credit

Contributions are credited where they land. A substantial contribution names its author
in its changelog entry, for example "(contributed by Jane Doe)", and in the docblock of
the code it added. If you'd like to be credited under a different name or link, say so
in your pull request.

## Security issues

Please don't report security vulnerabilities in public issues or pull requests. See
[SECURITY.md](SECURITY.md) for how to report them privately.
