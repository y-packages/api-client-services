<?php

declare(strict_types=1);

namespace YakNet\Api;

use ArrayAccess;
use JsonSerializable;
use ReflectionObject;
use ReflectionProperty;

/**
 * Base DTO model class supporting property hydration,
 * nested model mapping, ArrayAccess, and JSON serialization.
 *
 * @implements ArrayAccess<string, mixed>
 */
class Model implements ArrayAccess, JsonSerializable
{
    /** @var array<string, mixed> */
    protected array $modelData = [];

    /**
     * Maps property names (e.g. 'buckets' => Bucket::class) to nested Model classes.
     *
     * @var array<string, class-string<Model>>
     */
    protected array $propertyModelMap = [];

    /**
     * Indicates which mapped properties represent arrays of models vs single model objects.
     *
     * @var array<string, 'array'|'object'>
     */
    protected array $propertyDataTypes = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        if (! empty($attributes)) {
            $this->hydrate($attributes);
        }
    }

    /**
     * Hydrate model properties from raw associative array payload.
     *
     * @param array<string, mixed> $attributes
     */
    public function hydrate(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $propName = $this->resolvePropertyName((string) $key);

            if ($value !== null && isset($this->propertyModelMap[$propName])) {
                $targetClass = $this->propertyModelMap[$propName];
                $dataType = $this->propertyDataTypes[$propName] ?? 'object';

                if ($dataType === 'array' && is_array($value)) {
                    $hydratedList = [];
                    foreach ($value as $item) {
                        if ($item instanceof $targetClass) {
                            $hydratedList[] = $item;
                        } elseif (is_array($item)) {
                            $hydratedList[] = new $targetClass($item);
                        }
                    }
                    $value = $hydratedList;
                } elseif ($dataType === 'object' && is_array($value)) {
                    $value = new $targetClass($value);
                }
            }

            if (property_exists($this, $propName)) {
                $this->{$propName} = $value;
            } else {
                $this->modelData[(string) $key] = $value;
            }
        }

        return $this;
    }

    protected function resolvePropertyName(string $key): string
    {
        if (property_exists($this, $key)) {
            return $key;
        }

        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $key))));
        if (property_exists($this, $camel)) {
            return $camel;
        }

        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
        if (property_exists($this, $snake)) {
            return $snake;
        }

        return $key;
    }

    /**
     * Convert model and any nested models to a plain associative array (excluding nulls).
     *
     * @return array<string, mixed>
     */
    public function toSimpleObject(): array
    {
        $result = [];
        $ref = new ReflectionObject($this);

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || ! $prop->isInitialized($this)) {
                continue;
            }
            $name = $prop->getName();
            $val = $prop->getValue($this);
            if ($val === null) {
                continue;
            }
            $result[$name] = $this->normalizeValue($val);
        }

        foreach ($this->modelData as $k => $v) {
            if ($v !== null) {
                $result[$k] = $this->normalizeValue($v);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toSimpleObject();
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toSimpleObject();
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeValue($v);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toSimpleObject();
    }

    public function offsetExists(mixed $offset): bool
    {
        $prop = $this->resolvePropertyName((string) $offset);
        if (property_exists($this, $prop)) {
            return isset($this->{$prop});
        }

        return isset($this->modelData[(string) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $prop = $this->resolvePropertyName((string) $offset);
        if (property_exists($this, $prop)) {
            return $this->{$prop};
        }

        return $this->modelData[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            return;
        }
        $prop = $this->resolvePropertyName((string) $offset);
        if (property_exists($this, $prop)) {
            $this->{$prop} = $value;

            return;
        }
        $this->modelData[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        $prop = $this->resolvePropertyName((string) $offset);
        if (property_exists($this, $prop)) {
            $this->{$prop} = null;

            return;
        }
        unset($this->modelData[(string) $offset]);
    }
}
