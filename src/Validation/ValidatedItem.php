<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields\Validation;

use Bakame\Http\StructuredFields\ByteSequence;
use Bakame\Http\StructuredFields\DisplayString;
use Bakame\Http\StructuredFields\Token;
use DateTimeImmutable;

final class ValidatedItem
{
    public function __construct(
        public readonly ByteSequence|Token|DisplayString|DateTimeImmutable|string|int|float|bool|null $value,
        public readonly ValidatedParameters $parameters = new ValidatedParameters(),
    ) {
    }
}
