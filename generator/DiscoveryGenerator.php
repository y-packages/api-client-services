<?php

declare(strict_types=1);

namespace YakNet\Api\Generator;

use YakNet\Api\Exception\YakNetException;
use YakNet\Api\Http\CurlTransport;
use YakNet\Api\Http\TransportInterface;

/**
 * Discovery Code Generator for `yaknet/api-client-services`.
 *
 * Reads Discovery REST JSON specifications (either from local `discovery/*.json` files
 * or live from `/api/discovery/v1/apis`) and generates strongly-typed Service,
 * Resource, Model, and Collection classes under `src/`.
 */
final class DiscoveryGenerator
{
    private const RESERVED_WORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else',
        'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch',
        'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace',
        'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'resource', 'return', 'self', 'static',
        'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var',
        'void', 'while', 'xor', 'yield', 'int', 'float', 'bool', 'mixed', 'never', 'false',
    ];

    private string $rootNamespace;
    private TransportInterface $transport;

    public function __construct(string $rootNamespace = 'YakNet\\Api', ?TransportInterface $transport = null)
    {
        $this->rootNamespace = trim($rootNamespace, '\\');
        $this->transport = $transport ?? new CurlTransport(false);
    }

    /**
     * Fetch Discovery Directory + all REST specifications from a live YakNet Console URL,
     * persist them into `$discoveryDir`, and generate all PHP classes into `$srcDir`.
     *
     * @return array{services: int, files: int, generated_files: list<string>}
     */
    public function generateFromUrl(string $discoveryDirectoryUrl, string $discoveryDir, string $srcDir): array
    {
        $res = $this->transport->send('GET', $discoveryDirectoryUrl, ['Accept' => 'application/json']);
        if ($res->getStatusCode() !== 200) {
            throw new YakNetException("Discovery dizini alınamadı ({$discoveryDirectoryUrl}) — HTTP {$res->getStatusCode()}");
        }

        $directory = $res->json();
        if (! is_array($directory) || ! isset($directory['items']) || ! is_array($directory['items'])) {
            throw new YakNetException('Geçersiz Discovery dizin yanıtı.');
        }

        $this->ensureDirectory($discoveryDir);
        file_put_contents(
            rtrim($discoveryDir, '/\\').DIRECTORY_SEPARATOR.'directory.json',
            json_encode($directory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        foreach ($directory['items'] as $item) {
            if (! is_array($item) || empty($item['discoveryRestUrl']) || empty($item['name']) || empty($item['version'])) {
                continue;
            }
            $restRes = $this->transport->send('GET', (string) $item['discoveryRestUrl'], ['Accept' => 'application/json']);
            if ($restRes->getStatusCode() !== 200) {
                throw new YakNetException("Servis şeması alınamadı: {$item['discoveryRestUrl']}");
            }
            $doc = $restRes->json();
            if (! is_array($doc)) {
                continue;
            }
            $targetFile = rtrim($discoveryDir, '/\\').DIRECTORY_SEPARATOR."{$item['name']}.{$item['version']}.json";
            file_put_contents(
                $targetFile,
                json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );
        }

        return $this->generateFromDirectory($discoveryDir, $srcDir);
    }

    /**
     * Generate all Service, Resource, and Model PHP files from local `*.json` Discovery documents.
     *
     * @return array{services: int, files: int, generated_files: list<string>}
     */
    public function generateFromDirectory(string $discoveryDir, string $srcDir): array
    {
        if (! is_dir($discoveryDir)) {
            throw new YakNetException("Discovery dizini bulunamadı: {$discoveryDir}");
        }

        $files = glob(rtrim($discoveryDir, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($files);

        $servicesCount = 0;
        $generatedFiles = [];

        foreach ($files as $file) {
            if (basename($file) === 'directory.json') {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $doc = json_decode($raw, true);
            if (! is_array($doc) || ($doc['kind'] ?? '') !== 'discovery#restDescription') {
                continue;
            }

            $written = $this->generateService($doc, $srcDir);
            foreach ($written as $path) {
                $generatedFiles[] = $path;
            }
            $servicesCount++;
        }

        return [
            'services' => $servicesCount,
            'files' => count($generatedFiles),
            'generated_files' => $generatedFiles,
        ];
    }

    /**
     * Generate a single service's root class, resource classes, and schema model classes.
     *
     * @param array<string, mixed> $doc
     * @return list<string>
     */
    public function generateService(array $doc, string $srcDir): array
    {
        $serviceId = (string) ($doc['name'] ?? 'service');
        $version = (string) ($doc['version'] ?? 'v1');
        $rawClassName = (string) ($doc['canonicalName'] ?? ucfirst($serviceId));
        $serviceClass = $this->sanitizeClassName($rawClassName);
        $title = (string) ($doc['title'] ?? "{$serviceClass} API");
        $description = (string) ($doc['description'] ?? '');
        $servicePath = (string) ($doc['servicePath'] ?? '');

        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = is_array($doc['schemas'] ?? null) ? $doc['schemas'] : [];
        /** @var array<string, array<string, mixed>> $resources */
        $resources = is_array($doc['resources'] ?? null) ? $doc['resources'] : [];

        $written = [];
        $serviceDir = rtrim($srcDir, '/\\').DIRECTORY_SEPARATOR.$serviceClass;
        $resourceDir = $serviceDir.DIRECTORY_SEPARATOR.'Resource';
        $this->ensureDirectory($serviceDir);
        $this->ensureDirectory($resourceDir);

        // 1. Generate all Schema Model / Collection classes
        foreach ($schemas as $schemaName => $schemaSpec) {
            $modelClass = $this->sanitizeClassName((string) $schemaName);
            $code = $this->renderModelClass($serviceClass, $modelClass, $schemaSpec, $schemas);
            $modelPath = $serviceDir.DIRECTORY_SEPARATOR.$modelClass.'.php';
            file_put_contents($modelPath, $code);
            $written[] = $modelPath;
        }

        // 2. Generate all Resource classes
        foreach ($resources as $resourceKey => $resourceSpec) {
            $resClass = $this->sanitizeClassName($this->studly((string) $resourceKey));
            $code = $this->renderResourceClass($serviceClass, (string) $resourceKey, $resClass, $resourceSpec);
            $resPath = $resourceDir.DIRECTORY_SEPARATOR.$resClass.'.php';
            file_put_contents($resPath, $code);
            $written[] = $resPath;
        }

        // 3. Generate Root Service class (`src/<ServiceClass>.php`)
        $rootCode = $this->renderServiceRootClass(
            $serviceId,
            $version,
            $serviceClass,
            $title,
            $description,
            $servicePath,
            $resources
        );
        $rootPath = rtrim($srcDir, '/\\').DIRECTORY_SEPARATOR.$serviceClass.'.php';
        file_put_contents($rootPath, $rootCode);
        $written[] = $rootPath;

        return $written;
    }

    /**
     * @param array<string, array<string, mixed>> $resources
     */
    private function renderServiceRootClass(
        string $serviceId,
        string $version,
        string $serviceClass,
        string $title,
        string $description,
        string $servicePath,
        array $resources
    ): string {
        $ns = $this->rootNamespace;
        $useLines = [
            "use {$ns}\\Client;",
            "use {$ns}\\Service;",
        ];

        $propLines = [];
        $initLines = [];

        foreach ($resources as $resourceKey => $resourceSpec) {
            $resClass = $this->sanitizeClassName($this->studly((string) $resourceKey));
            $useLines[] = "use {$ns}\\{$serviceClass}\\Resource\\{$resClass};";
            $propLines[] = "    public {$resClass} \${$resourceKey};";

            $methodsMapCode = $this->exportMethodsSpecification($resourceSpec['methods'] ?? []);
            $initLines[] = <<<PHP
        \$this->{$resourceKey} = new {$resClass}(
            \$this,
            \$this->serviceName,
            '{$resourceKey}',
{$methodsMapCode}
        );
PHP;
        }

        sort($useLines);
        $usesBlock = implode("\n", array_unique($useLines));
        $propsBlock = implode("\n", $propLines);
        $initsBlock = implode("\n\n", $initLines);
        $escapedTitle = str_replace('*/', '', $title);
        $escapedDesc = str_replace('*/', '', $description);
        $escapedPath = addslashes($servicePath);

        return <<<PHP
<?php

declare(strict_types=1);

/*
 * This file was automatically generated by yaknet/api-client-services DiscoveryGenerator.
 * Do not edit this file manually — run `composer generate` to regenerate from Discovery specs.
 */

namespace {$ns};

{$usesBlock}

/**
 * {$escapedTitle} ({$serviceId}:{$version}).
 *
 * {$escapedDesc}
 */
class {$serviceClass} extends Service
{
{$propsBlock}

    /**
     * @param Client|array<string, mixed> \$clientOrConfig
     */
    public function __construct(Client|array \$clientOrConfig = [])
    {
        parent::__construct(\$clientOrConfig);
        \$this->serviceName = '{$serviceId}';
        \$this->version = '{$version}';
        \$this->servicePath = '{$escapedPath}';

{$initsBlock}
    }
}

PHP;
    }

    /**
     * @param array<string, mixed> $resourceSpec
     */
    private function renderResourceClass(
        string $serviceClass,
        string $resourceKey,
        string $resourceClass,
        array $resourceSpec
    ): string {
        $ns = "{$this->rootNamespace}\\{$serviceClass}\\Resource";
        $modelNs = "{$this->rootNamespace}\\{$serviceClass}";

        /** @var array<string, array<string, mixed>> $methods */
        $methods = is_array($resourceSpec['methods'] ?? null) ? $resourceSpec['methods'] : [];

        $uses = [
            "use {$this->rootNamespace}\\Service\\Resource;",
        ];
        $methodBlocks = [];

        foreach ($methods as $methodName => $methodSpec) {
            $safeMethodName = $this->sanitizeMethodName((string) $methodName);
            $desc = str_replace('*/', '', (string) ($methodSpec['description'] ?? "{$resourceKey}.{$methodName}"));

            /** @var array<string, array<string, mixed>> $params */
            $params = is_array($methodSpec['parameters'] ?? null) ? $methodSpec['parameters'] : [];
            $requestRef = $methodSpec['request']['$ref'] ?? null;
            $responseRef = $methodSpec['response']['$ref'] ?? null;

            $signatureArgs = [];
            $docLines = ["    /**", "     * {$desc} ({$resourceKey}.{$methodName})", '     *'];
            $bodyParamAssigns = [];

            // 1. Required path parameters
            foreach ($params as $pName => $pSpec) {
                $isPath = ($pSpec['location'] ?? 'query') === 'path';
                $isReq = (bool) ($pSpec['required'] ?? false);
                if ($isPath && $isReq) {
                    $phpType = $this->mapJsonTypeToPhp((string) ($pSpec['type'] ?? 'string'));
                    $signatureArgs[] = "{$phpType} \${$pName}";
                    $docLines[] = "     * @param {$phpType} \${$pName}";
                    $bodyParamAssigns[] = "'{$pName}' => \${$pName}";
                }
            }

            // 2. Request model parameter if defined
            if (is_string($requestRef) && $requestRef !== '') {
                $reqClass = $this->sanitizeClassName($requestRef);
                $uses[] = "use {$modelNs}\\{$reqClass};";
                $signatureArgs[] = "{$reqClass}|array \$postBody = []";
                $docLines[] = "     * @param {$reqClass}|array<string, mixed> \$postBody";
                $bodyParamAssigns[] = "'postBody' => \$postBody";
            }

            // 3. Optional parameters ($optParams)
            $optParamDocs = [];
            foreach ($params as $pName => $pSpec) {
                $isPath = ($pSpec['location'] ?? 'query') === 'path';
                $isReq = (bool) ($pSpec['required'] ?? false);
                if (! ($isPath && $isReq)) {
                    $phpType = $this->mapJsonTypeToPhp((string) ($pSpec['type'] ?? 'string'));
                    $optParamDocs[] = "     * @opt_param {$phpType} {$pName}";
                }
            }

            $signatureArgs[] = 'array $optParams = []';
            foreach ($optParamDocs as $optDoc) {
                $docLines[] = $optDoc;
            }
            $docLines[] = '     * @param array<string, mixed> $optParams';

            $returnType = 'array';
            $callReturnLine = "return \$this->call('{$methodName}', \$params);";

            if (is_string($responseRef) && $responseRef !== '') {
                $respClass = $this->sanitizeClassName($responseRef);
                $uses[] = "use {$modelNs}\\{$respClass};";
                $returnType = $respClass;
                $docLines[] = "     * @return {$respClass}";
                $callReturnLine = "return \$this->callTyped('{$methodName}', \$params, {$respClass}::class);";
            } else {
                $docLines[] = '     * @return array<string, mixed>';
            }

            $docLines[] = '     */';
            $docComment = implode("\n", $docLines);
            $sigString = implode(', ', $signatureArgs);
            $paramsInit = empty($bodyParamAssigns)
                ? '$params = [];'
                : '$params = ['.implode(', ', $bodyParamAssigns).'];';

            $methodBlocks[] = <<<PHP
{$docComment}
    public function {$safeMethodName}({$sigString}): {$returnType}
    {
        {$paramsInit}
        \$params = array_merge(\$params, \$optParams);

        {$callReturnLine}
    }
PHP;
        }

        sort($uses);
        $usesBlock = implode("\n", array_unique($uses));
        $methodsCode = implode("\n\n", $methodBlocks);

        return <<<PHP
<?php

declare(strict_types=1);

/*
 * This file was automatically generated by yaknet/api-client-services DiscoveryGenerator.
 * Do not edit this file manually — run `composer generate` to regenerate from Discovery specs.
 */

namespace {$ns};

{$usesBlock}

/**
 * The "{$resourceKey}" collection of methods for {$serviceClass}.
 */
class {$resourceClass} extends Resource
{
{$methodsCode}
}

PHP;
    }

    /**
     * @param array<string, mixed> $schemaSpec
     * @param array<string, array<string, mixed>> $allSchemas
     */
    private function renderModelClass(
        string $serviceClass,
        string $modelClass,
        array $schemaSpec,
        array $allSchemas
    ): string {
        $ns = "{$this->rootNamespace}\\{$serviceClass}";
        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($schemaSpec['properties'] ?? null) ? $schemaSpec['properties'] : [];
        $description = str_replace('*/', '', (string) ($schemaSpec['description'] ?? "{$serviceClass} {$modelClass} model."));

        // Check if this schema wraps a primary array of model items (Collection candidate!)
        $collectionKey = null;
        foreach ($properties as $propName => $propSpec) {
            if (($propSpec['type'] ?? '') === 'array' && isset($propSpec['items']['$ref'])) {
                $collectionKey = (string) $propName;
                break;
            }
        }

        $baseClass = $collectionKey !== null ? 'Collection' : 'Model';
        $uses = ["use {$this->rootNamespace}\\{$baseClass};"];

        $propDeclarations = [];
        $modelMapEntries = [];
        $dataTypeEntries = [];
        $accessorBlocks = [];

        if ($collectionKey !== null) {
            $propDeclarations[] = "    protected string \$collectionKey = '{$collectionKey}';\n";
        }

        foreach ($properties as $propName => $propSpec) {
            $propNameStr = (string) $propName;
            $studlyProp = $this->studly($propNameStr);
            $camelVar = lcfirst($studlyProp);

            $ref = $propSpec['$ref'] ?? null;
            $type = (string) ($propSpec['type'] ?? 'mixed');

            if (is_string($ref) && $ref !== '') {
                $refClass = $this->sanitizeClassName($ref);
                $propDeclarations[] = "    public ?{$refClass} \${$propNameStr} = null;";
                $modelMapEntries[] = "        '{$propNameStr}' => {$refClass}::class,";
                $dataTypeEntries[] = "        '{$propNameStr}' => 'object',";

                $accessorBlocks[] = <<<PHP
    public function set{$studlyProp}(?{$refClass} \${$camelVar}): self
    {
        \$this->{$propNameStr} = \${$camelVar};

        return \$this;
    }

    public function get{$studlyProp}(): ?{$refClass}
    {
        return \$this->{$propNameStr};
    }
PHP;
            } elseif ($type === 'array') {
                $itemRef = $propSpec['items']['$ref'] ?? null;
                $itemType = (string) ($propSpec['items']['type'] ?? 'mixed');

                if (is_string($itemRef) && $itemRef !== '') {
                    $refClass = $this->sanitizeClassName($itemRef);
                    $propDeclarations[] = "    /** @var list<{$refClass}>|null */\n    public ?array \${$propNameStr} = null;";
                    $modelMapEntries[] = "        '{$propNameStr}' => {$refClass}::class,";
                    $dataTypeEntries[] = "        '{$propNameStr}' => 'array',";

                    $accessorBlocks[] = <<<PHP
    /**
     * @param list<{$refClass}>|null \${$camelVar}
     */
    public function set{$studlyProp}(?array \${$camelVar}): self
    {
        \$this->{$propNameStr} = \${$camelVar};

        return \$this;
    }

    /**
     * @return list<{$refClass}>|null
     */
    public function get{$studlyProp}(): ?array
    {
        return \$this->{$propNameStr};
    }
PHP;
                } else {
                    $phpItem = $this->mapJsonTypeToPhp($itemType);
                    $docItem = $phpItem === 'mixed' ? 'mixed' : $phpItem;
                    $propDeclarations[] = "    /** @var list<{$docItem}>|null */\n    public ?array \${$propNameStr} = null;";

                    $accessorBlocks[] = <<<PHP
    /**
     * @param list<{$docItem}>|null \${$camelVar}
     */
    public function set{$studlyProp}(?array \${$camelVar}): self
    {
        \$this->{$propNameStr} = \${$camelVar};

        return \$this;
    }

    /**
     * @return list<{$docItem}>|null
     */
    public function get{$studlyProp}(): ?array
    {
        return \$this->{$propNameStr};
    }
PHP;
                }
            } elseif ($type === 'object') {
                $propDeclarations[] = "    /** @var array<string, mixed>|null */\n    public ?array \${$propNameStr} = null;";

                $accessorBlocks[] = <<<PHP
    /**
     * @param array<string, mixed>|null \${$camelVar}
     */
    public function set{$studlyProp}(?array \${$camelVar}): self
    {
        \$this->{$propNameStr} = \${$camelVar};

        return \$this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get{$studlyProp}(): ?array
    {
        return \$this->{$propNameStr};
    }
PHP;
            } elseif ($type === 'any' || $type === 'mixed') {
                $propDeclarations[] = "    public mixed \${$propNameStr} = null;";

                $accessorBlocks[] = <<<PHP
    public function set{$studlyProp}(mixed \${$camelVar}): self
    {
        \$this->{$propNameStr} = \${$camelVar};

        return \$this;
    }

    public function get{$studlyProp}(): mixed
    {
        return \$this->{$propNameStr};
    }
PHP;
            } else {
                $phpType = $this->mapJsonTypeToPhp($type);
                $propDeclarations[] = "    public ?{$phpType} \${$propNameStr} = null;";

                $accessorBlocks[] = <<<PHP
    public function set{$studlyProp}(?{$phpType} \${$camelVar}): self
    {
        \$this->{$propNameStr} = \${$camelVar};

        return \$this;
    }

    public function get{$studlyProp}(): ?{$phpType}
    {
        return \$this->{$propNameStr};
    }
PHP;
            }
        }

        if (! empty($modelMapEntries)) {
            $mapBody = implode("\n", $modelMapEntries);
            $typesBody = implode("\n", $dataTypeEntries);
            $propDeclarations[] = <<<PHP

    /** @var array<string, class-string<\\{$this->rootNamespace}\\Model>> */
    protected array \$propertyModelMap = [
{$mapBody}
    ];

    /** @var array<string, 'array'|'object'> */
    protected array \$propertyDataTypes = [
{$typesBody}
    ];
PHP;
        }

        sort($uses);
        $usesBlock = implode("\n", array_unique($uses));
        $propsBlock = implode("\n", $propDeclarations);
        $accessorsCode = implode("\n\n", $accessorBlocks);
        $spacer = ($propsBlock !== '' && $accessorsCode !== '') ? "\n\n" : '';

        return <<<PHP
<?php

declare(strict_types=1);

/*
 * This file was automatically generated by yaknet/api-client-services DiscoveryGenerator.
 * Do not edit this file manually — run `composer generate` to regenerate from Discovery specs.
 */

namespace {$ns};

{$usesBlock}

/**
 * {$description}
 */
class {$modelClass} extends {$baseClass}
{
{$propsBlock}{$spacer}{$accessorsCode}
}

PHP;
    }

    /**
     * @param array<string, mixed> $methods
     */
    private function exportMethodsSpecification(array $methods): string
    {
        $lines = ['            ['];
        foreach ($methods as $mName => $mSpec) {
            if (! is_array($mSpec)) {
                continue;
            }
            $path = addslashes((string) ($mSpec['path'] ?? ''));
            $httpMethod = strtoupper((string) ($mSpec['httpMethod'] ?? 'GET'));
            $params = is_array($mSpec['parameters'] ?? null) ? $mSpec['parameters'] : [];

            $lines[] = "                '{$mName}' => [";
            $lines[] = "                    'path' => '{$path}',";
            $lines[] = "                    'httpMethod' => '{$httpMethod}',";

            if (! empty($params)) {
                $lines[] = "                    'parameters' => [";
                foreach ($params as $pName => $pSpec) {
                    if (! is_array($pSpec)) {
                        continue;
                    }
                    $loc = addslashes((string) ($pSpec['location'] ?? 'query'));
                    $type = addslashes((string) ($pSpec['type'] ?? 'string'));
                    $req = ! empty($pSpec['required']) ? 'true' : 'false';
                    $defaultPart = '';
                    if (array_key_exists('default', $pSpec) && is_scalar($pSpec['default'])) {
                        $exportedDef = var_export($pSpec['default'], true);
                        $defaultPart = ", 'default' => {$exportedDef}";
                    }
                    $lines[] = "                        '{$pName}' => ['location' => '{$loc}', 'type' => '{$type}', 'required' => {$req}{$defaultPart}],";
                }
                $lines[] = '                    ],';
            }

            $lines[] = '                ],';
        }
        $lines[] = '            ]';

        return implode("\n", $lines);
    }

    private function mapJsonTypeToPhp(string $jsonType): string
    {
        return match ($jsonType) {
            'string' => 'string',
            'integer', 'int' => 'int',
            'number', 'float', 'double' => 'float',
            'boolean', 'bool' => 'bool',
            'array', 'object' => 'array',
            default => 'mixed',
        };
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $value)));
    }

    private function sanitizeClassName(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'GeneratedModel';
        $clean = ucfirst($clean);

        if (in_array(strtolower($clean), self::RESERVED_WORDS, true)) {
            return 'YakNet'.$clean;
        }

        return $clean;
    }

    private function sanitizeMethodName(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'invoke';
        if (in_array(strtolower($clean), self::RESERVED_WORDS, true)) {
            return $clean.'Resource';
        }

        return $clean;
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new YakNetException("Klasör oluşturulamadı: {$dir}");
        }
    }
}
