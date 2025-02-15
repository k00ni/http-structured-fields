<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

use Bakame\Http\StructuredFields\Validation\Violation;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Common manipulation methods used when interacting with an object
 * with a Parameters instance attached to it.
 *
 * @phpstan-import-type SfType from StructuredField
 * @phpstan-import-type SfItemInput from StructuredField
 */
trait ParameterAccess
{
    /**
     * Returns a copy of the associated parameter instance.
     */
    public function parameters(): Parameters
    {
        return $this->parameters;
    }

    /**
     * Returns the member value or null if no members value exists.
     *
     * @param ?callable(SfType): (bool|string) $validate
     *
     * @throws Violation if the validation fails
     *
     * @return SfType|null
     */
    public function parameterByKey(
        string $key,
        ?callable $validate = null,
        bool|string $required = false,
        ByteSequence|Token|DisplayString|DateTimeImmutable|string|int|float|bool|null $default = null
    ): ByteSequence|Token|DisplayString|DateTimeImmutable|string|int|float|bool|null {
        return $this->parameters->valueByKey($key, $validate, $required, $default);
    }

    /**
     * Returns the member value and name as pair or an empty array if no members value exists.
     *
     * @param ?callable(SfType, string): (bool|string) $validate
     * @param array{0:string, 1:SfType}|array{} $default
     *
     * @throws Violation if the validation fails
     *
     * @return array{0:string, 1:SfType}|array{}
     */
    public function parameterByIndex(
        int $index,
        ?callable $validate = null,
        bool|string $required = false,
        array $default = []
    ): array {
        return $this->parameters->valueByIndex($index, $validate, $required, $default);
    }

    /**
     * Returns a new instance with the newly associated parameter instance.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     */
    abstract public function withParameters(Parameters $parameters): static;

    /**
     * Adds a member if its key is not present at the of the associated parameter instance or update the instance at the given key.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param StructuredFieldProvider|StructuredField|SfType|null $member
     *
     * @throws SyntaxError If the string key is not a valid
     */
    public function addParameter(
        string $key,
        StructuredFieldProvider|StructuredField|Token|ByteSequence|DisplayString|DateTimeInterface|string|int|float|bool|null $member
    ): static {
        return $this->withParameters($this->parameters()->add($key, $member));
    }

    /**
     * Adds a member at the start of the associated parameter instance and deletes any previous reference to the key if present.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param StructuredFieldProvider|StructuredField|SfType|null $member
     *
     * @throws SyntaxError If the string key is not a valid
     */
    public function prependParameter(
        string $key,
        StructuredFieldProvider|StructuredField|Token|ByteSequence|DisplayString|DateTimeInterface|string|int|float|bool|null  $member
    ): static {
        return $this->withParameters($this->parameters()->prepend($key, $member));
    }

    /**
     * Adds a member at the end of the associated parameter instance and deletes any previous reference to the key if present.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param StructuredFieldProvider|StructuredField|SfType|null $member
     *
     * @throws SyntaxError If the string key is not a valid
     */
    public function appendParameter(
        string $key,
        StructuredFieldProvider|StructuredField|Token|ByteSequence|DisplayString|DateTimeInterface|string|int|float|bool|null $member
    ): static {
        return $this->withParameters($this->parameters()->append($key, $member));
    }

    /**
     * Removes all parameters members associated with the list of submitted keys in the associated parameter instance.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     */
    public function withoutAnyParameter(): static
    {
        return $this->withParameters(Parameters::new());
    }

    /**
     * Inserts pair at the end of the member list.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param array{0:string, 1:SfItemInput} ...$pairs
     */
    public function pushParameters(array ...$pairs): static
    {
        return $this->withParameters($this->parameters()->push(...$pairs));
    }

    /**
     * Inserts pair at the beginning of the member list.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param array{0:string, 1:SfItemInput} ...$pairs
     */
    public function unshiftParameters(array ...$pairs): static
    {
        return $this->withParameters($this->parameters()->unshift(...$pairs));
    }

    /**
     * Delete member based on their name.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     */
    public function withoutParameterByKeys(string ...$keys): static
    {
        return $this->withParameters($this->parameters()->removeByKeys(...$keys));
    }

    /**
     * Delete member based on their offsets.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     */
    public function withoutParameterByIndices(int ...$indices): static
    {
        return $this->withParameters($this->parameters()->removeByIndices(...$indices));
    }

    /**
     * Inserts members at the specified index.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param array{0:string, 1:SfType} ...$pairs
     */
    public function insertParameters(int $index, array ...$pairs): static
    {
        return $this->withParameters($this->parameters()->insert($index, ...$pairs));
    }

    /**
     * Replace the member at the specified index.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param array{0:string, 1:SfType} $pair
     */
    public function replaceParameter(int $index, array $pair): static
    {
        return $this->withParameters($this->parameters()->replace($index, $pair));
    }

    /**
     * Sort the object parameters by value using a callback.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param callable(array{0:string, 1:Item}, array{0:string, 1:Item}): int $callback
     */
    public function sortParameters(callable $callback): static
    {
        return $this->withParameters($this->parameters()->sort($callback));
    }

    /**
     * Filter the object parameters using a callback.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified parameter change.
     *
     * @param callable(array{0:string, 1:Item}, int): bool $callback
     */
    public function filterParameters(callable $callback): static
    {
        return $this->withParameters($this->parameters()->filter($callback));
    }
}
