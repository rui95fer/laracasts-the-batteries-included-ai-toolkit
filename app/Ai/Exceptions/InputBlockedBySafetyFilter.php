<?php

namespace App\Ai\Exceptions;

use RuntimeException;

class InputBlockedBySafetyFilter extends RuntimeException
{
    public function __construct(string $term)
    {
        parent::__construct(
            __('Input blocked by safety filter.'),
        );
    }
}
