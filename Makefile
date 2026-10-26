.PHONY: dist clean-dist sdk-link sdk-unlink sdk-status

dist:
	./scripts/build-plugin-zip.sh

clean-dist:
	rm -rf dist

# Local php-ai-client development.
#
# WordPress 7.1 bundles php-ai-client 1.3.1, and composer.json pins the dev
# dependency to that line, so `composer install` reproduces what plugin users
# actually run. To work on this provider against a local SDK checkout instead,
# use `make sdk-link` (and `make sdk-unlink` to go back).
#
# This replaces the old approach of hand-symlinking vendor/wordpress/php-ai-client,
# which left composer.lock claiming 1.3.1 while a different tree was installed.
# A path repository is visible in `git status` instead of being invisible.
# Do not commit the composer.json / composer.lock changes it makes.
#
# A path repository carries no version of its own, so SDK_VERSION is the version
# the linked checkout is announced as. It has to satisfy the constraint in
# composer.json, and it decides which version_compare() branches in this provider
# are taken, so set it to the version the checkout really is:
#
#   make sdk-link SDK_PATH=../php-ai-client-1.4.0 SDK_VERSION=1.4.0
#
# This is also how to check what happens on the version WordPress bundles, which has
# no embedding support, since nothing in CI covers that:
#
#   git -C ../php-ai-client worktree add ../php-ai-client-1.3.1 1.3.1
#   make sdk-link SDK_PATH=../php-ai-client-1.3.1 SDK_VERSION=1.3.1
SDK_PATH ?= ../php-ai-client
SDK_VERSION ?= 1.3.1

sdk-link:
	composer config repositories.php-ai-client '{"type":"path","url":"$(SDK_PATH)","options":{"versions":{"wordpress/php-ai-client":"$(SDK_VERSION)"}}}'
	composer update wordpress/php-ai-client --no-interaction --quiet
	@$(MAKE) --no-print-directory sdk-status

sdk-unlink:
	composer config --unset repositories.php-ai-client
	@php -r '$$f = "composer.json"; \
		$$c = json_decode(file_get_contents($$f), true); \
		if (isset($$c["repositories"]) && !$$c["repositories"]) { unset($$c["repositories"]); } \
		file_put_contents($$f, json_encode($$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");'
	composer update wordpress/php-ai-client --no-interaction --quiet
	@$(MAKE) --no-print-directory sdk-status

sdk-status:
	@php -r 'require "vendor/autoload.php"; \
		$$d = "vendor/wordpress/php-ai-client"; \
		printf("SDK %s (%s)\n", WordPress\AiClient\AiClient::VERSION, is_link($$d) ? "linked to " . readlink($$d) : "installed from packagist");'
