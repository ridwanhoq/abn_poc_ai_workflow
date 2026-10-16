<?php

namespace App\Workflow\Integrations\Mock;

use App\Workflow\Integrations\Contracts\Translator;

/**
 * Pseudo-translation: prefixes the text with the target language code.
 *
 * TODO: Integrate a production translation / dubbing provider (DeepL, Google Translation,
 * AWS Translate; ElevenLabs or similar for dubbing and lip-sync). Credentials: API key in
 * workflow_integrations. Request: source text or subtitle file, source/target languages,
 * glossary. Response: translated text / file. Handle character quotas and retry 429s.
 */
class MockTranslator implements Translator
{
    use Deterministic;

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        return $this->simulatedMeta('translation') + [
            'source_language' => $sourceLanguage ?? 'auto',
            'target_language' => $targetLanguage,
            'text' => '['.strtoupper($targetLanguage).'] '.$text,
            'characters' => mb_strlen($text),
        ];
    }
}
