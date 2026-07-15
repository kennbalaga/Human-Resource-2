<?php

namespace App\Support;

final readonly class IntegrationResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public bool $success,
        public string $provider,
        public string $message,
        public array $data = [],
        public ?int $httpStatus = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function success(string $provider, string $message, array $data = [], ?int $httpStatus = null): self
    {
        return new self(true, $provider, $message, $data, $httpStatus);
    }

    public static function failure(string $provider, string $message, ?int $httpStatus = null): self
    {
        return new self(false, $provider, $message, [], $httpStatus);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'provider' => $this->provider,
            'message' => $this->message,
            'data' => $this->data,
            'http_status' => $this->httpStatus,
        ];
    }
}
