<?php

declare(strict_types=1);

namespace Bfg\LaravelBoostReflector\Tools;

use Bfg\Attributes\Attributes;
use Bfg\LaravelBoostReflector\Tools\Traits\HelpersTrait;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ClassList extends Tool
{
    use HelpersTrait;

    /**
     * The tool's description.
     */
    protected string $description = 'Fast discovery of PHP classes in any directory with powerful filtering by traits and interfaces. Perfect for finding all models, controllers, or classes implementing specific contracts WITHOUT reading files. Returns complete metadata: parent classes, interfaces, traits hierarchy, docblocks, and file locations.';

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema
                ->string()
                ->description('Directory path to scan for PHP classes (relative to project root). Examples: "app/Models" (all models), "app/Http/Controllers" (all controllers), "app" (entire app directory). Scans recursively through subdirectories.')
                ->required(),
            'has_trait' => $schema
                ->string()
                ->description('OPTIONAL: Filter to show ONLY classes using specific trait. Provide full namespaced trait name. Perfect for finding all models with HasFactory, all classes with SoftDeletes, etc. Example: "Illuminate\\Database\\Eloquent\\Factories\\HasFactory" finds all factory-enabled models. Leave empty to show all classes.')
                ->default(''),
            'has_interface' => $schema
                ->string()
                ->description('OPTIONAL: Filter to show ONLY classes implementing specific interface. Provide full namespaced interface name. Perfect for finding all Arrayable classes, all queue jobs (ShouldQueue), all event listeners, etc. Example: "Illuminate\\Contracts\\Queue\\ShouldQueue" finds all queued jobs. Leave empty to show all classes.')
                ->default(''),
            'has_method' => $schema
                ->string()
                ->description('OPTIONAL: Filter to show ONLY classes with specific method. Provide method name (without parentheses). Perfect for finding classes with custom methods, magic methods, or specific implementations. Example: "boot" finds all classes with boot() method. Leave empty to show all classes.')
                ->default(''),
            'recursive' => $schema
                ->boolean()
                ->description('ADVANCED: Control directory scanning depth. When true (default): scans recursively through all subdirectories. When false: scans only direct children of the specified path (no subdirectories). Perfect for limiting results to immediate files when you have deeply nested directory structures.')
                ->default(true),
            'limit' => $schema
                ->integer()
                ->description('PAGINATION: Maximum number of classes to return. Set to positive number to limit output for directories with many classes. Example: limit=20 returns first 20 classes. Set 0 for unlimited (default). Combine with offset for pagination.')
                ->default(0),
            'offset' => $schema
                ->integer()
                ->description('PAGINATION: Skip first N classes. Useful for paginating through large result sets. Example: offset=0, limit=20 shows first 20 classes; offset=20, limit=20 shows next 20. Default: 0 (no skip).')
                ->default(0),
            'raw_docblock' => $schema
                ->boolean()
                ->description('EXPERT: Show raw docblock text as-is from source code for all elements. Useful for advanced analysis or custom parsing. When true: displays unprocessed docblock strings. When false (default): shows parsed/cleaned docblocks. Default: false.')
                ->default(false),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $path = $request->get('path');

        $collectionOfClasses = Attributes::new()
            ->wherePath(base_path($path))
            ->classes();

        // Filter by trait if specified
        $hasTrait = $request->get('has_trait');
        if ($hasTrait) {
            $collectionOfClasses = $collectionOfClasses->filter(function (\ReflectionClass $ref) use ($hasTrait) {
                $traits = $ref->getTraitNames();

                return ! empty($traits) && in_array($hasTrait, $traits);
            });
        }

        // Filter by interface if specified
        $hasInterface = $request->get('has_interface');
        if ($hasInterface) {
            $collectionOfClasses = $collectionOfClasses->filter(function (\ReflectionClass $ref) use ($hasInterface) {
                $interfaces = $ref->getInterfaceNames();

                return ! empty($interfaces) && in_array($hasInterface, $interfaces);
            });
        }

        // Filter by method if specified
        $hasMethod = $request->get('has_method');
        if ($hasMethod) {
            $collectionOfClasses = $collectionOfClasses->filter(function (\ReflectionClass $ref) use ($hasMethod) {
                return $ref->hasMethod($hasMethod);
            });
        }

        // Filter by recursive flag if set to false
        $recursive = $request->get('recursive', true);
        if (!$recursive) {
            $basePath = realpath(base_path($path));
            $collectionOfClasses = $collectionOfClasses->filter(function (\ReflectionClass $ref) use ($basePath) {
                $fileName = $ref->getFileName();
                if (!$fileName) {
                    return false;
                }
                // Check if file is in the direct path (not in subdirectories)
                return dirname(realpath($fileName)) === $basePath;
            });
        }

        $this->rawDockBlock = (bool) $request->get('raw_docblock', true);
        $this->comments = true;

        // Get pagination parameters
        $limit = $request->get('limit', 0);
        $offset = $request->get('offset', 0);

        // Apply pagination to collection
        $totalCount = $collectionOfClasses->count();
        if ($offset > 0 || $limit > 0) {
            $collectionOfClasses = $collectionOfClasses->slice($offset, $limit > 0 ? $limit : null);
        }

        // Map to class information array
        $classes = $collectionOfClasses->map(function (\ReflectionClass $ref) {
            return $this->classInformation($ref, true);
        })->values()->all();

        if (empty($classes)) {
            return Response::error('No classes found in the specified path.');
        }

        // Return JSON array format
        return Response::text(json_encode($classes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
