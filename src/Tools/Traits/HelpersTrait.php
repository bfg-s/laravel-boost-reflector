<?php

declare(strict_types=1);

namespace Bfg\LaravelBoostReflector\Tools\Traits;

use Illuminate\Support\Collection;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlockFactory;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionClassConstant;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use Roave\BetterReflection\Reflection\ReflectionProperty;
use Roave\BetterReflection\BetterReflection;
use ReflectionClass as StandardReflectionClass;
use ReflectionMethod as StandardReflectionMethod;
use ReflectionProperty as StandardReflectionProperty;

trait HelpersTrait
{
    protected bool $constants = false;
    protected bool $properties = false;
    protected bool $methods = false;
    protected bool $comments = false;
    protected bool $include_inherited = false;
    protected bool $summary = false;
    protected string $visibility = 'public';
    protected int $max_depth = 1;
    protected int $methods_offset = 0;
    protected int $methods_limit = 0;
    protected int $properties_offset = 0;
    protected int $properties_limit = 0;
    protected int $constants_offset = 0;
    protected int $constants_limit = 0;
    protected bool $static_only = false;

    /**
     * Cache for vendor class introspection results
     * @var array<string, array>
     */
    protected static array $classCache = [];

    protected function classesInformation(Collection $collectionOfClasses)
    {

    }

    /**
     * Recursive render class information
     *
     * @param  array  $classInfo
     * @param  bool  $pretty
     * @return string|null
     */
    protected function renderClassInformation(array $classInfo, bool $pretty = false): string|null
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($classInfo, $flags);
    }

    /**
     * @throws \ReflectionException
     */
    protected function classInformation(ReflectionClass|StandardReflectionClass|string $ref, bool $details = false, bool $_parent = false): array
    {
        // Extract class name from ReflectionClass (both standard and BetterReflection)
        $className = is_string($ref) ? $ref : $ref->getName();

        // Check if this is a vendor class (Illuminate, Laravel, Symfony, etc.)
        $isVendorClass = str_starts_with($className, 'Illuminate\\')
            || str_starts_with($className, 'Laravel\\')
            || str_starts_with($className, 'Symfony\\')
            || str_starts_with($className, 'Roave\\')
            || str_starts_with($className, 'phpDocumentor\\');

        // Generate cache key including settings that affect output
        $cacheKey = $className . '|' . ($details ? '1' : '0') . '|' . ($this->include_inherited ? '1' : '0');

        // Return cached result for vendor classes if available
        if ($isVendorClass && isset(static::$classCache[$cacheKey])) {
            return static::$classCache[$cacheKey];
        }

        // Always convert to BetterReflection
        $ref = (new BetterReflection())
            ->reflector()
            ->reflectClass($className);
        $parent = $ref->getParentClass();

        // Parse visibility filter
        $visibilityLevels = array_map('trim', explode(',', strtolower($this->visibility)));

        $result =  [
            'file' => $ref->getFileName() ? str_replace(base_path() . DIRECTORY_SEPARATOR, '', $ref->getFileName()) : null,
            'name' => $ref->getName(),
        ];

        if ($details) {
            $result['startLine'] = $ref->getStartLine();
            $result['endLine'] = $ref->getEndLine();
        }

        if ($dockblock = $this->generateDocBlock($ref, $details)) {
            $result['docblock'] = $dockblock;
        }

        if ($parent) {
            $result['parent'] = [
                'file' => $parent->getFileName() ? str_replace(base_path() . DIRECTORY_SEPARATOR, '', $parent->getFileName()) : null,
                'name' => $parent->getName(),
                'namespace' => $parent->getNamespaceName(),
            ];

            if ($details) {
                $result['parent']['startLine'] = $parent->getStartLine();
                $result['parent']['endLine'] = $parent->getEndLine();
            }

            if ($dockblock = $this->generateDocBlock($parent, $details)) {
                $result['parent']['docblock'] = $dockblock;
            }
        }

        if ($ref->isAbstract()) {
            $result['isAbstract'] = true;
        }
        if ($ref->isFinal()) {
            $result['isFinal'] = true;
        }
        if ($ref->isInterface()) {
            $result['isInterface'] = true;
        }
        if ($ref->isTrait()) {
            $result['isTrait'] = true;
        }

        if ($details) {
            if ($this->constants) {
                $constants = [];
                foreach ($ref->getConstants() as $key => $constant) {
                    // Filter inherited constants if needed
                    if (!$this->include_inherited) {
                        if ($constant->getDeclaringClass()->getName() !== $ref->getName()) {
                            continue;
                        }
                    }

                    $constants[$key] = [
                        'constantName' => $key,
                        'value' => $constant->getValue(),
                        'startLine' => $constant->getStartLine(),
                        'endLine' => $constant->getEndLine(),
                    ];

                    if ($docblock = $this->generateDocBlock($constant)) {
                        $constants[$key]['docblock'] = $docblock;
                    }
                }

                // Apply pagination to constants
                $constants = $this->applyPagination($constants, $this->constants_offset, $this->constants_limit);

                $result['constants'] = $constants;
            }

            if (!$ref->isInterface() && ! $_parent) {
                if ($this->properties) {
                    $properties = [];
                    foreach ($ref->getProperties() as $key => $property) {
                        // Filter inherited properties if needed
                        if (!$this->include_inherited && $property->getDeclaringClass()->getName() !== $ref->getName()) {
                            continue;
                        }

                        // Filter by visibility
                        if (!$this->matchesVisibility($property, $visibilityLevels)) {
                            continue;
                        }

                        $properties[$key] = [
                            'propertyName' => $property->getName(),
                            'type' => $property->getType()?->__toString(),
                            'defaultValue' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
                            'startLine' => $property->getStartLine(),
                            'endLine' => $property->getEndLine(),
                        ];

                        if ($property->isStatic()) {
                            $properties[$key]['isStatic'] = true;
                        }
                        if ($property->isPublic()) {
                            $properties[$key]['isPublic'] = true;
                        }
                        if ($property->isProtected()) {
                            $properties[$key]['isProtected'] = true;
                        }
                        if ($property->isPrivate()) {
                            $properties[$key]['isPrivate'] = true;
                        }

                        if ($docblock = $this->generateDocBlock($property)) {
                            $properties[$key]['docblock'] = $docblock;
                        }
                    }

                    // Apply pagination to properties
                    $properties = $this->applyPagination($properties, $this->properties_offset, $this->properties_limit);

                    $result['properties'] = $properties;
                }

                if ($this->methods) {
                    $methods = [];
                    foreach ($ref->getMethods() as $key => $method) {
                        // Filter inherited methods if needed
                        if (!$this->include_inherited && $method->getDeclaringClass()->getName() !== $ref->getName()) {
                            continue;
                        }

                        // Filter by visibility
                        if (!$this->matchesVisibility($method, $visibilityLevels)) {
                            continue;
                        }

                        $methods[$key] = [
                            'methodName' => $method->getName(),
                            'parameters' => array_map(fn($param) => $param->getName(), $method->getParameters()),
                            'returnType' => $method->getReturnType()?->__toString(),
                            'startLine' => $method->getStartLine(),
                            'endLine' => $method->getEndLine(),
                        ];

                        if ($method->isStatic()) {
                            $methods[$key]['isStatic'] = true;
                        }
                        if ($method->isPublic()) {
                            $methods[$key]['isPublic'] = true;
                        }
                        if ($method->isProtected()) {
                            $methods[$key]['isProtected'] = true;
                        }
                        if ($method->isPrivate()) {
                            $methods[$key]['isPrivate'] = true;
                        }

                        if ($docblock = $this->generateDocBlock($method)) {
                            $methods[$key]['docblock'] = $docblock;
                        }
                    }

                    // Apply static filtering
                    if ($this->static_only) {
                        $methods = $this->filterByStatic($methods, $this->static_only);
                    }

                    // Apply pagination to methods
                    $methods = $this->applyPagination($methods, $this->methods_offset, $this->methods_limit);

                    $result['methods'] = $methods;
                }
            }
        }

        if (! $ref->isTrait()) {
            if ($interfaces = array_map(fn (ReflectionClass $interface) => $this->classInformation($interface, false, true), $ref->getInterfaces())) {
                $result['interfaces'] = array_values($interfaces);
            }
        }

        if ($traits = array_map(fn (ReflectionClass $trait) => $this->classInformation($trait, false, true), $ref->getTraits())) {
            $result['traits'] = array_values($traits);
        }

        // Cache the result for vendor classes
        if ($isVendorClass) {
            static::$classCache[$cacheKey] = $result;
        }

        return $result;
    }

    protected function generateDocBlock(ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionClassConstant|false $ref, bool $details = false): array|null
    {
        if ($this->comments && $ref && ($doc = $ref->getDocComment())) {
            $factory  = DocBlockFactory::createInstance();
            $docblock = $factory->create($doc);
            $doc = [];

            if (
                ($summary = $docblock->getSummary())
                ?? ($summary = trim($summary))
            ) {
                $doc['summary'] = $summary;
            }

            if (!$this->summary) {
                if (
                    ($description = $docblock->getDescription()->render())
                    ?? ($description = trim($description))
                ) {
                    $doc['description'] = $description;
                }

                if ($details && count($tags = $docblock->getTags())) {
                    $tags = array_filter(array_map(function (Tag $tag) {
                        $name = $tag->getName();
                        if (in_array($name, ['author', 'package', 'subpackage', 'license'])) {
                            return null;
                        }
                        return $tag->render();
                    }, $tags));
                    if (count($tags)) {
                        $doc['tags'] = $tags;
                    }
                }
            }

            return count($doc) ? $doc : null;
        }
        return null;
    }

    /**
     * Check if property/method matches visibility filter
     */
    protected function matchesVisibility(
        StandardReflectionProperty|ReflectionProperty|StandardReflectionMethod|ReflectionMethod $member,
        array $visibilityLevels
    ): bool {
        if (in_array('all', $visibilityLevels) || in_array('private', $visibilityLevels)) {
            return true;
        }

        if ($member->isPublic() && in_array('public', $visibilityLevels)) {
            return true;
        }

        if ($member->isProtected() && in_array('protected', $visibilityLevels)) {
            return true;
        }

        return false;
    }

    /**
     * Apply pagination to an array
     */
    protected function applyPagination(array $items, int $offset, int $limit): array
    {
        if ($offset > 0 || $limit > 0) {
            $items = array_slice($items, $offset, $limit > 0 ? $limit : null, true);
        }
        return $items;
    }

    /**
     * Filter methods by static flag
     */
    protected function filterByStatic(array $methods, bool $staticOnly): array
    {
        if (!$staticOnly) {
            return $methods;
        }

        return array_filter($methods, function($method) {
            return isset($method['isStatic']) && $method['isStatic'] === true;
        });
    }
}
