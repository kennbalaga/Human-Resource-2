<?php

namespace App\Services\Security;

use RuntimeException;

class UnsafeAttachmentException extends RuntimeException
{
    public function __construct(
        public readonly string $userMessage,
        string $internalMessage,
    ) {
        parent::__construct($internalMessage);
    }
}
