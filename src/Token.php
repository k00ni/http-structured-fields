<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

final class Token implements StructuredField
{
    public function __construct(private string $value)
    {
        if (1 !== preg_match("/^([a-z*][a-z0-9:\/\!\#\$%&'\*\+\-\.\^_`\|~]*)$/i", $this->value)) {
            throw new SyntaxError('Invalid characters in token');
        }
    }

    public function canonical(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return $this->value;
    }
}
