<?php

declare(strict_types=1);

namespace YakNet\Api\Service;

use YakNet\Api\Exception\YakNetException;
use YakNet\Api\Model;
use YakNet\Api\Service;

/**
 * Executes Discovery REST methods with URL template expansion, query string encoding,
 * request body serialization, and strongly-typed Model hydration.
 */
class Resource
{
    private Service $service;
    private string $serviceName;
    private string $resourceName;

    /**
     * @var array<string, array{
     *     path: string,
     *     httpMethod: string,
     *     parameters?: array<string, array{location: string, type?: string, required?: bool, default?: mixed}>
     * }>
     */
    private array $methods;

    /**
     * @param array<string, array{
     *     path: string,
     *     httpMethod: string,
     *     parameters?: array<string, array{location: string, type?: string, required?: bool, default?: mixed}>
     * }> $methods
     */
    public function __construct(Service $service, string $serviceName, string $resourceName, array $methods)
    {
        $this->service = $service;
        $this->serviceName = $serviceName;
        $this->resourceName = $resourceName;
        $this->methods = $methods;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    /**
     * Invoke a Discovery method and hydrate its response into `$expectedClass` (or return raw array).
     *
     * @template T of Model
     * @param array<string, mixed> $arguments
     * @param class-string<T>|null $expectedClass
     * @return ($expectedClass is null ? array<string, mixed> : T)
     */
    public function call(string $name, array $arguments = [], ?string $expectedClass = null): Model|array
    {
        if (! isset($this->methods[$name])) {
            throw new YakNetException("Bilinmeyen metot: {$this->serviceName}.{$this->resourceName}.{$name}");
        }

        $methodSpec = $this->methods[$name];
        $httpMethod = strtoupper($methodSpec['httpMethod']);
        $paramDefs = $methodSpec['parameters'] ?? [];

        $postBody = null;
        if (array_key_exists('postBody', $arguments)) {
            $rawBody = $arguments['postBody'];
            unset($arguments['postBody']);

            if ($rawBody instanceof Model) {
                $postBody = $rawBody->toSimpleObject();
            } elseif (is_array($rawBody)) {
                $postBody = $rawBody;
            }
        }

        $pathParams = [];
        $queryParams = [];

        foreach ($paramDefs as $paramName => $def) {
            $val = $arguments[$paramName] ?? ($def['default'] ?? null);
            unset($arguments[$paramName]);

            if (($def['required'] ?? false) && ($val === null || $val === '')) {
                throw new YakNetException("Zorunlu parametre eksik: '{$paramName}' ({$this->serviceName}.{$this->resourceName}.{$name})");
            }

            if ($val === null) {
                continue;
            }

            if ($def['location'] === 'path') {
                $pathParams[$paramName] = (string) $val;
            } else {
                $queryParams[$paramName] = $val;
            }
        }

        // Any extra optional parameters passed via $optParams go into query parameters
        foreach ($arguments as $extraKey => $extraVal) {
            if ($extraVal !== null) {
                $queryParams[(string) $extraKey] = $extraVal;
            }
        }

        $urlPath = $this->buildRequestPath($this->service->servicePath, $methodSpec['path'], $pathParams);

        if (! empty($queryParams)) {
            $qs = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            if ($qs !== '') {
                $urlPath .= (str_contains($urlPath, '?') ? '&' : '?').$qs;
            }
        }

        if ($postBody === null && in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true)) {
            $postBody = [];
        }

        $response = $this->service->getClient()->request($httpMethod, $urlPath, $postBody);
        $decoded = $response->json() ?? [];

        if ($expectedClass !== null && is_subclass_of($expectedClass, Model::class)) {
            return new $expectedClass($decoded);
        }

        return $decoded;
    }

    /**
     * Invoke a Discovery method and always return an instance of `$expectedClass`.
     *
     * @template T of Model
     * @param array<string, mixed> $arguments
     * @param class-string<T> $expectedClass
     * @return T
     */
    public function callTyped(string $name, array $arguments, string $expectedClass): Model
    {
        /** @var T $result */
        $result = $this->call($name, $arguments, $expectedClass);

        return $result;
    }

    /**
     * @param array<string, string> $pathParams
     */
    private function buildRequestPath(string $servicePath, string $methodPath, array $pathParams): string
    {
        $expanded = $methodPath;
        foreach ($pathParams as $key => $value) {
            // Reserved expansion `{+objectKey}` preserves slashes while encoding segments
            if (str_contains($expanded, '{+'.$key.'}')) {
                $segments = array_map('rawurlencode', explode('/', ltrim($value, '/')));
                $expanded = str_replace('{+'.$key.'}', implode('/', $segments), $expanded);
            } else {
                $expanded = str_replace('{'.$key.'}', rawurlencode($value), $expanded);
            }
        }

        $base = rtrim($servicePath, '/');
        $rel = ltrim($expanded, '/');

        if ($rel === '') {
            return '/'.ltrim($base, '/');
        }

        return '/'.ltrim($base.'/'.$rel, '/');
    }
}
