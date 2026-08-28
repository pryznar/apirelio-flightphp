<?php

declare(strict_types=1);

namespace Apirelio\FlightPHP;

final class RequestContext
{
    private ?string $errorCode = null;

    /** @var array<string, bool|float|int|string|null> */
    private array $metadata = [];

    public function setErrorCode(string $errorCode): self
    {
        $this->errorCode = mb_substr($errorCode, 0, 255);

        return $this;
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    public function addMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string, bool|float|int|string|null> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
