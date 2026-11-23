<?php

declare(strict_types=1);

namespace App\Speech;

use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Bridge\Generic\TextToSpeechModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Platform;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the "app.platform.voicestudio" platform: one voice model on an OpenAI-compatible speech server,
 * through the generic bridge's text to speech (symfony/ai#3058, required from the tacman/ai-generic-platform
 * fork until it is released).
 */
final class VoiceStudioPlatform
{
    /**
     * @param non-empty-string $model
     */
    public static function create(HttpClientInterface $httpClient, string $baseUrl, string $model, ?string $apiKey = null): Platform
    {
        return Factory::createPlatform(
            $baseUrl,
            '' !== $apiKey ? $apiKey : null,
            $httpClient,
            new ModelCatalog([
                $model => [
                    'class' => TextToSpeechModel::class,
                    'capabilities' => [Capability::INPUT_TEXT, Capability::OUTPUT_AUDIO, Capability::TEXT_TO_SPEECH],
                ],
            ]),
            supportsCompletions: false,
            supportsEmbeddings: false,
            name: 'voicestudio',
            supportsTextToSpeech: true,
        );
    }
}
