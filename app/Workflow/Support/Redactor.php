<?php

namespace App\Workflow\Support;

/**
 * Masks credentials and personal data before execution data is persisted.
 * Runtime values flowing between nodes are never altered — only what is stored.
 */
class Redactor
{
    private const SECRET_KEYS = '/(password|passwd|secret|token|api[_-]?key|authorization|credential|private[_-]?key|card[_-]?number|cvv|ssn)/i';

    private const PII_KEYS = '/^(email|to|recipient|phone|mobile)$/i';

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SECRET_KEYS, $key)) {
            return $value === null || $value === '' ? $value : '[REDACTED]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->redact($v, is_string($k) ? $k : $key);
            }

            return $out;
        }

        if (is_string($value)) {
            if ($key !== null && preg_match(self::PII_KEYS, $key)) {
                return $this->maskEmail($value);
            }

            // Bearer tokens embedded in free text.
            return preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/', 'Bearer [REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    public function maskEmail(string $value): string
    {
        if (! str_contains($value, '@')) {
            return strlen($value) > 4 ? substr($value, 0, 2).str_repeat('*', max(strlen($value) - 4, 1)).substr($value, -2) : '****';
        }

        [$local, $domain] = explode('@', $value, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * Redact then cap the JSON size of a payload.
     */
    /**
     * Replace every occurrence of known secret values (e.g. secret variables) in strings.
     *
     * @param  list<string>  $secrets
     */
    public function maskSecrets(mixed $value, array $secrets): mixed
    {
        $secrets = array_values(array_filter($secrets, fn ($s) => mb_strlen($s) >= 4));
        if ($secrets === []) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->maskSecrets($v, $secrets), $value);
        }

        return is_string($value) ? str_replace($secrets, '[REDACTED]', $value) : $value;
    }

    /**
     * @param  list<string>  $secrets  secret values to mask wherever they appear
     */
    public function forStorage(mixed $value, array $secrets = []): mixed
    {
        $value = $this->maskSecrets($this->redact($value), $secrets);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $limit = (int) config('workflows.max_payload_bytes', 65536);

        if ($json !== false && strlen($json) > $limit) {
            return [
                '_truncated' => true,
                '_original_bytes' => strlen($json),
                'preview' => mb_substr($json, 0, 2000),
            ];
        }

        return $value;
    }
}
