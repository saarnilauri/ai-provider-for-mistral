<?php

/**
 * Plugin Name: AI Provider for Mistral
 * Plugin URI: https://github.com/saarnilauri/ai-provider-for-mistral
 * Description: Independent WordPress AI Client provider for Mistral.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.1
 * Author: Lauri Saarni
 * Author URI: https://profiles.wordpress.org/laurisaarni/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-mistral
 *
 * @package AiProviderForMistral
 */

declare(strict_types=1);

namespace AiProviderForMistral;

use AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    return;
}

/**
 * Loads all plugin class files.
 *
 * Since this plugin may be installed without Composer, classes
 * are loaded manually instead of relying on an autoloader.
 *
 * @since 0.1.3
 *
 * @return void
 */
function load_classes(): void
{
    $plugin_dir = __DIR__ . '/src';

    require_once $plugin_dir . '/Metadata/ProviderForMistralModelMetadataDirectory.php';
    require_once $plugin_dir . '/Models/ProviderForMistralTextGenerationModel.php';
    require_once $plugin_dir . '/Models/ProviderForMistralImageGenerationModel.php';
    require_once $plugin_dir . '/Provider/ProviderForMistral.php';
}

/**
 * Registers the WordPress AI Client provider for Mistral.
 *
 * @since 0.1.1
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    load_classes();

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(ProviderForMistral::class)) {
        return;
    }

    $registry->registerProvider(ProviderForMistral::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
