<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

use ArrayAccess;
use Bakame\Http\StructuredFields\Validation\Violation;
use CallbackFilterIterator;
use Countable;
use DateTimeInterface;
use Iterator;
use IteratorAggregate;
use Stringable;

use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_array;
use function is_int;
use function is_iterable;
use function is_string;

/**
 * @see https://www.rfc-editor.org/rfc/rfc9651.html#section-3.2
 *
 * @phpstan-import-type SfMemberInput from StructuredField
 *
 * @implements ArrayAccess<string, InnerList|Item>
 * @implements IteratorAggregate<int, array{0:string, 1:InnerList|Item}>
 */
final class Dictionary implements ArrayAccess, Countable, IteratorAggregate, StructuredField
{
    /** @var array<string, InnerList|Item> */
    private readonly array $members;

    /**
     * @param iterable<string, InnerList|Item|SfMemberInput> $members
     */
    private function __construct(iterable $members = [])
    {
        $filteredMembers = [];
        foreach ($members as $key => $member) {
            $filteredMembers[MapKey::from($key)->value] = self::filterMember($member);
        }

        $this->members = $filteredMembers;
    }

    /**
     * @param InnerList|Item|SfMemberInput $member
     */
    private static function filterMember(mixed $member): InnerList|Item
    {
        if ($member instanceof StructuredFieldProvider) {
            $member = $member->toStructuredField();
        }

        return match (true) {
            $member instanceof InnerList || $member instanceof Item => $member,
            $member instanceof StructuredField => throw new InvalidArgument('An instance of "'.$member::class.'" can not be a member of "'.self::class.'".'),
            is_iterable($member) => InnerList::new(...$member),
            default => Item::new($member),
        };
    }

    /**
     * Returns a new instance.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Returns a new instance from an associative iterable construct.
     *
     * its keys represent the dictionary entry key
     * its values represent the dictionary entry value
     *
     * @param StructuredFieldProvider|iterable<string, InnerList|Item|SfMemberInput> $members
     */
    public static function fromAssociative(StructuredFieldProvider|iterable $members): self
    {
        if ($members instanceof StructuredFieldProvider) {
            $members = $members->toStructuredField();
        }

        if (!is_iterable($members)) {
            throw new InvalidArgument('The "'.$members::class.'" instance can not be used for creating a .'.self::class.' structured field.');
        }

        return new self($members);
    }

    /**
     * Returns a new instance from a pair iterable construct.
     *
     * Each member is composed of an array with two elements
     * the first member represents the instance entry key
     * the second member represents the instance entry value
     *
     * @param StructuredFieldProvider|Dictionary|Parameters|iterable<array{0:string, 1?:InnerList|Item|SfMemberInput}> $pairs
     */
    public static function fromPairs(StructuredFieldProvider|iterable $pairs): self
    {
        if ($pairs instanceof StructuredFieldProvider) {
            $pairs = $pairs->toStructuredField();
        }

        if (!is_iterable($pairs)) {
            throw new InvalidArgument('The "'.$pairs::class.'" instance can not be used for creating a .'.self::class.' structured field.');
        }

        $converter = function (mixed $pair): InnerList|Item {
            if ($pair instanceof StructuredFieldProvider) {
                $pair = $pair->toStructuredField();
            }

            if ($pair instanceof InnerList || $pair instanceof Item) {
                return $pair;
            }

            if (!is_array($pair)) {
                return Item::new($pair); /* @phpstan-ignore-line */
            }

            if (!array_is_list($pair)) {
                throw new SyntaxError('The pair must be represented by an array as a list.');
            }

            if ([] === $pair) {
                return InnerList::new();
            }

            [$member, $parameters] = match (count($pair)) {
                2 => $pair,
                1 => [$pair[0], []],
                default => throw new SyntaxError('The pair first member is the item value; its second member is the item parameters.'),
            };

            return is_iterable($member) ? InnerList::fromPair([$member, $parameters]) : Item::fromPair([$member, $parameters]);
        };

        return match (true) {
            $pairs instanceof Dictionary,
            $pairs instanceof Parameters => new self($pairs->toAssociative()),
            default => new self((function (iterable $pairs) use ($converter) {
                foreach ($pairs as [$key, $member]) {
                    yield $key => $converter($member);
                }
            })($pairs)),
        };
    }

    /**
     * Returns an instance from an HTTP textual representation compliant with RFC9651.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9651.html#section-3.2
     *
     * @throws SyntaxError If the string is not a valid
     */
    public static function fromRfc9651(Stringable|string $httpValue): self
    {
        return self::fromHttpValue($httpValue, Ietf::Rfc9651);
    }

    /**
     * Returns an instance from an HTTP textual representation compliant with RFC8941.
     *
     * @see https://www.rfc-editor.org/rfc/rfc8941.html#section-3.2
     *
     * @throws SyntaxError If the string is not a valid
     */
    public static function fromRfc8941(Stringable|string $httpValue): self
    {
        return self::fromHttpValue($httpValue, Ietf::Rfc8941);
    }

    /**
     * Returns an instance from an HTTP textual representation.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9651.html#section-3.2
     *
     * @throws SyntaxError If the string is not a valid
     */
    public static function fromHttpValue(Stringable|string $httpValue, ?Ietf $rfc = null): self
    {
        $converter = fn (array $member): InnerList|Item => match (true) {
            is_array($member[0]) => InnerList::fromAssociative(
                array_map(fn (array $item) => Item::fromAssociative(...$item), $member[0]),
                $member[1]
            ),
            default => Item::fromAssociative(...$member),
        };

        return new self(array_map($converter, Parser::new($rfc)->parseDictionary($httpValue)));
    }

    public function toRfc9651(): string
    {
        return $this->toHttpValue(Ietf::Rfc9651);
    }

    public function toRfc8941(): string
    {
        return $this->toHttpValue(Ietf::Rfc8941);
    }

    public function toHttpValue(?Ietf $rfc = null): string
    {
        $rfc ??= Ietf::Rfc9651;
        $members = [];
        foreach ($this->members as $key => $member) {
            $members[] = match (true) {
                $member instanceof Item && true === $member->value() => $key.$member->parameters()->toHttpValue($rfc),
                default => $key.'='.$member->toHttpValue($rfc),
            };
        }

        return implode(', ', $members);
    }

    public function __toString(): string
    {
        return $this->toHttpValue();
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->toHttpValue() === $this->toHttpValue();
    }

    public function count(): int
    {
        return count($this->members);
    }

    /**
     * Tells whether the instance contains no members.
     */
    public function isEmpty(): bool
    {
        return !$this->isNotEmpty();
    }

    /**
     * Tells whether the instance contains any members.
     */
    public function isNotEmpty(): bool
    {
        return [] !== $this->members;
    }

    /**
     * @return Iterator<string, InnerList|Item>
     */
    public function toAssociative(): Iterator
    {
        yield from $this->members;
    }

    /**
     * Returns an iterable construct of dictionary pairs.
     *
     * @return Iterator<int, array{0:string, 1:InnerList|Item}>
     */
    public function getIterator(): Iterator
    {
        foreach ($this->members as $index => $member) {
            yield [$index, $member];
        }
    }

    /**
     * Returns an ordered list of the instance keys.
     *
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->members);
    }

    /**
     * @return array<int>
     */
    public function indices(): array
    {
        return array_keys($this->keys());
    }

    /**
     * Tells whether the instance contain a members at the specified offsets.
     */
    public function hasKeys(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->members)) {
                return false;
            }
        }

        return [] !== $keys;
    }

    /**
     * Returns true only if the instance only contains the listed keys, false otherwise.
     *
     * @param array<string> $keys
     */
    public function allowedKeys(array $keys): bool
    {
        foreach ($this->members as $key => $member) {
            if (!in_array($key, $keys, true)) {
                return false;
            }
        }

        return [] !== $keys;
    }

    /**
     * @param ?callable(Item|InnerList): (bool|string) $validate
     *
     * @throws InvalidOffset|Violation|StructuredFieldError
     */
    public function getByKey(string $key, ?callable $validate = null): Item|InnerList
    {
        $value = $this->members[$key] ?? throw InvalidOffset::dueToKeyNotFound($key);
        if (null === $validate) {
            return $value;
        }

        if (true === ($exceptionMessage = $validate($value))) {
            return $value;
        }

        if (!is_string($exceptionMessage) || '' === trim($exceptionMessage)) {
            $exceptionMessage = "The parameter '{key}' whose value is '{value}' failed validation.";
        }

        throw new Violation(strtr($exceptionMessage, ['{key}' => $key, '{value}' => $value->toHttpValue()]));
    }

    /**
     * Tells whether a pair is attached to the given index position.
     */
    public function hasIndices(int ...$indexes): bool
    {
        $max = count($this->members);
        foreach ($indexes as $index) {
            if (null === $this->filterIndex($index, $max)) {
                return false;
            }
        }

        return [] !== $indexes;
    }

    /**
     * Filters and format instance index.
     */
    private function filterIndex(int $index, int|null $max = null): int|null
    {
        $max ??= count($this->members);

        return match (true) {
            [] === $this->members,
            0 > $max + $index,
            0 > $max - $index - 1 => null,
            0 > $index => $max + $index,
            default => $index,
        };
    }

    /**
     * Returns the item or the inner-list and its key as attached to the given
     * collection according to their index position otherwise throw.
     *
     * @param ?callable(Item|InnerList, string): (bool|string) $validate
     *
     * @throws InvalidOffset|Violation|StructuredFieldError
     *
     * @return array{0:string, 1:InnerList|Item}
     */
    public function getByIndex(int $index, ?callable $validate = null): array
    {
        $foundOffset = $this->filterIndex($index) ?? throw InvalidOffset::dueToIndexNotFound($index);

        $validator = function (Item|InnerList $value, string $key, int $index, callable $validate): array {
            if (true === ($exceptionMessage = $validate($value, $key))) {
                return [$key, $value];
            }

            if (!is_string($exceptionMessage) || '' === trim($exceptionMessage)) {
                $exceptionMessage = "The member at position '{index}' whose name is '{key}' with the value '{value}' failed validation.";
            }

            throw new Violation(strtr($exceptionMessage, ['{index}' => $index, '{key}' => $key, '{value}' => $value->toHttpValue()]));
        };

        foreach ($this as $offset => $pair) {
            if ($offset === $foundOffset) {
                return match ($validate) {
                    null => $pair,
                    default => $validator($pair[1], $pair[0], $index, $validate),
                };
            }
        }

        throw InvalidOffset::dueToIndexNotFound($index);
    }

    /**
     * Returns the key associated with the given index or null otherwise.
     */
    public function indexByKey(string $key): ?int
    {
        foreach ($this as $index => $member) {
            if ($key === $member[0]) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Returns the index associated with the given key or null otherwise.
     */
    public function keyByIndex(int $index): ?string
    {
        $index = $this->filterIndex($index);
        if (null === $index) {
            return null;
        }

        foreach ($this as $offset => $member) {
            if ($offset === $index) {
                return $member[0];
            }
        }

        return null;
    }

    /**
     * Returns the first member whether it is an item or an inner-list and its key as attached to the given
     * collection according to their index position otherwise returns an empty array.
     *
     * @return array{0:string, 1:InnerList|Item}|array{}
     */
    public function first(): array
    {
        try {
            return $this->getByIndex(0);
        } catch (InvalidOffset) {
            return [];
        }
    }

    /**
     * Returns the first member whether it is an item or an inner-list and its key as attached to the given
     * collection according to their index position otherwise returns an empty array.
     *
     * @return array{0:string, 1:InnerList|Item}|array{}
     */
    public function last(): array
    {
        try {
            return $this->getByIndex(-1);
        } catch (InvalidOffset) {
            return [];
        }
    }

    /**
     * Adds a member at the end of the instance otherwise updates the value associated with the key if already present.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param InnerList|Item|SfMemberInput|null $member
     *
     * @throws SyntaxError If the string key is not a valid
     */
    public function add(
        string $key,
        iterable|StructuredFieldProvider|StructuredField|Token|ByteSequence|DisplayString|DateTimeInterface|string|int|float|bool|null $member
    ): self {
        if (null === $member) {
            return $this;
        }
        $members = $this->members;
        $members[MapKey::from($key)->value] = self::filterMember($member);

        return $this->newInstance($members);
    }

    /**
     * @param array<string, InnerList|Item> $members
     */
    private function newInstance(array $members): self
    {
        return match (true) {
            $members == $this->members => $this,
            default => new self($members),
        };
    }

    /**
     * Deletes members associated with the list of submitted keys.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     */
    private function remove(string|int ...$keys): self
    {
        if ([] === $this->members || [] === $keys) {
            return $this;
        }

        $offsets = array_keys($this->members);
        $max = count($offsets);
        $reducer = fn (array $carry, string|int $key): array => match (true) {
            is_string($key) && (false !== ($position = array_search($key, $offsets, true))),
            is_int($key) && (null !== ($position = $this->filterIndex($key, $max))) => [$position => true] + $carry,
            default => $carry,
        };

        $indices = array_reduce($keys, $reducer, []);

        return match (true) {
            [] === $indices => $this,
            $max === count($indices) => self::new(),
            default => self::fromPairs((function (array $offsets) {
                foreach ($this->getIterator() as $offset => $pair) {
                    if (!array_key_exists($offset, $offsets)) {
                        yield $pair;
                    }
                }
            })($indices)),
        };
    }

    /**
     * Deletes members associated with the list using the member pair offset.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     */
    public function removeByIndices(int ...$indices): self
    {
        return $this->remove(...$indices);
    }

    /**
     * Deletes members associated with the list using the member key.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     */
    public function removeByKeys(string ...$keys): self
    {
        return $this->remove(...$keys);
    }

    /**
     * Adds a member at the end of the instance and deletes any previous reference to the key if present.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param InnerList|Item|SfMemberInput|null $member
     * @throws SyntaxError If the string key is not a valid
     */
    public function append(
        string $key,
        iterable|StructuredFieldProvider|StructuredField|Token|ByteSequence|DisplayString|DateTimeInterface|string|int|float|bool|null $member
    ): self {
        if (null === $member) {
            return $this;
        }
        $members = $this->members;
        unset($members[$key]);

        return $this->newInstance([...$members, MapKey::from($key)->value => self::filterMember($member)]);
    }

    /**
     * Adds a member at the beginning of the instance and deletes any previous reference to the key if present.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param InnerList|Item|SfMemberInput|null $member
     *
     * @throws SyntaxError If the string key is not a valid
     */
    public function prepend(
        string $key,
        iterable|StructuredFieldProvider|StructuredField|Token|ByteSequence|DisplayString|DateTimeInterface|string|int|float|bool|null $member
    ): self {
        if (null === $member) {
            return $this;
        }
        $members = $this->members;
        unset($members[$key]);

        return $this->newInstance([MapKey::from($key)->value => self::filterMember($member), ...$members]);
    }

    /**
     * Inserts pairs at the end of the container.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param array{0:string, 1:InnerList|Item|SfMemberInput} ...$pairs
     */
    public function push(array ...$pairs): self
    {
        return match (true) {
            [] === $pairs => $this,
            default => self::fromPairs((function (iterable $pairs) {
                yield from $this->getIterator();
                yield from $pairs;
            })($pairs)),
        };
    }

    /**
     * Inserts pairs at the beginning of the container.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param array{0:string, 1:InnerList|Item|SfMemberInput} ...$pairs
     */
    public function unshift(array ...$pairs): self
    {
        return match (true) {
            [] === $pairs => $this,
            default => self::fromPairs((function (iterable $pairs) {
                yield from $pairs;
                yield from $this->getIterator();
            })($pairs)),
        };
    }

    /**
     * Insert a member pair using its offset.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param array{0:string, 1:InnerList|Item|SfMemberInput} ...$members
     */
    public function insert(int $index, array ...$members): self
    {
        $offset = $this->filterIndex($index) ?? throw InvalidOffset::dueToIndexNotFound($index);

        return match (true) {
            [] === $members => $this,
            0 === $offset => $this->unshift(...$members),
            count($this->members) === $offset => $this->push(...$members),
            default => (function (Iterator $newMembers) use ($offset, $members) {
                $newMembers = iterator_to_array($newMembers);
                array_splice($newMembers, $offset, 0, $members);

                return self::fromPairs($newMembers);
            })($this->getIterator()),
        };
    }

    /**
     * Replace a member pair using its offset.
     *
     *  This method MUST retain the state of the current instance, and return
     *  an instance that contains the specified changes.
     *
     * @param array{0:string, 1:InnerList|Item|SfMemberInput} $pair
     */
    public function replace(int $index, array $pair): self
    {
        $offset = $this->filterIndex($index) ?? throw InvalidOffset::dueToIndexNotFound($index);
        $pair[1] = self::filterMember($pair[1]);
        $pairs = iterator_to_array($this->getIterator());

        return match (true) {
            $pairs[$offset] == $pair => $this,
            default => self::fromPairs(array_replace($pairs, [$offset => $pair])),
        };
    }

    /**
     * Merges multiple instances using iterable associative structures.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param StructuredFieldProvider|Dictionary|Parameters|iterable<string, InnerList|Item|SfMemberInput> ...$others
     */
    public function mergeAssociative(StructuredFieldProvider|iterable ...$others): self
    {
        $members = $this->members;
        foreach ($others as $other) {
            if ($other instanceof StructuredFieldProvider) {
                $other = $other->toStructuredField();
                if (!is_iterable($other)) {
                    throw new InvalidArgument('The "'.$other::class.'" instance can not be used for creating a .'.self::class.' structured field.');
                }
            }

            if ($other instanceof self || $other instanceof Parameters) {
                $other = $other->toAssociative();
            }

            foreach ($other as $key => $value) {
                $members[$key] = $value;
            }
        }

        return new self($members);
    }

    /**
     * Merges multiple instances using iterable pairs.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified changes.
     *
     * @param StructuredFieldProvider|Dictionary|Parameters|iterable<array{0:string, 1:InnerList|Item|SfMemberInput}> ...$others
     */
    public function mergePairs(StructuredFieldProvider|Dictionary|Parameters|iterable ...$others): self
    {
        $members = $this->members;
        foreach ($others as $other) {
            if (!$other instanceof self) {
                $other = self::fromPairs($other);
            }
            foreach ($other->toAssociative() as $key => $value) {
                $members[$key] = $value;
            }
        }

        return new self($members);
    }

    /**
     * @param string $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->hasKeys($offset);
    }

    /**
     * @param string $offset
     */
    public function offsetGet(mixed $offset): InnerList|Item
    {
        return $this->getByKey($offset);
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new ForbiddenOperation(self::class.' instance can not be updated using '.ArrayAccess::class.' methods.');
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new ForbiddenOperation(self::class.' instance can not be updated using '.ArrayAccess::class.' methods.');
    }

    /**
     * Run a map over each container members.
     *
     * @template TMap
     *
     * @param callable(array{0:string, 1:Item|InnerList}, int): TMap $callback
     *
     * @return Iterator<TMap>
     */
    public function map(callable $callback): Iterator
    {
        foreach ($this->getIterator() as $offset => $member) {
            yield ($callback)($member, $offset);
        }
    }

    /**
     * @param callable(TInitial|null, array{0:string, 1:Item|InnerList}, int): TInitial $callback
     * @param TInitial|null $initial
     *
     * @template TInitial
     *
     * @return TInitial|null
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        foreach ($this->getIterator() as $offset => $pair) {
            $initial = $callback($initial, $pair, $offset);
        }

        return $initial;
    }

    /**
     * Run a filter over each container members.
     *
     * @param callable(array{0:string, 1:InnerList|Item}, int): bool $callback
     */
    public function filter(callable $callback): self
    {
        return self::fromPairs(new CallbackFilterIterator($this->getIterator(), $callback));
    }

    /**
     * Sort a container by value using a callback.
     *
     * @param callable(array{0:string, 1:InnerList|Item}, array{0:string, 1:InnerList|Item}): int $callback
     */
    public function sort(callable $callback): self
    {
        $members = iterator_to_array($this->getIterator());
        usort($members, $callback);

        return self::fromPairs($members);
    }
}
