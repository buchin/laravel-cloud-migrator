<?php

namespace App\Data;

readonly class ConfigAuditItem
{
    public function __construct(
        public string $appName,
        public string $envName,
        public string $variableKey,
        public ?string $expectedValue,
        public ?string $actualValue,
        public string $status = 'matched', // matched, drift, missing, warning
        public ?string $message = null,
    ) {}

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }

    public function isWarning(): bool
    {
        return $this->status === 'warning';
    }

    public function isDrift(): bool
    {
        return in_array($this->status, ['drift', 'missing', 'error'], true);
    }

    public function toArray(): array
    {
        return [
            'app_name' => $this->appName,
            'env_name' => $this->envName,
            'variable_key' => $this->variableKey,
            'expected_value' => $this->expectedValue,
            'actual_value' => $this->actualValue,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
