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
use ReflectionClass;

#[IsReadOnly]
class ClassDetail extends Tool
{
    use HelpersTrait;

    /**
     * The tool's description.
     */
    protected string $description = 'Deep introspection of ANY PHP class with complete API details: all methods, properties, constants, parameters, return types, visibility, docblocks, and inheritance chain. Accepts BOTH namespace (App\\Models\\User) OR file path (app/Models/User.php). Perfect for understanding class APIs, analyzing inheritance, generating documentation, or exploring vendor packages WITHOUT opening files. Supports pagination for large classes and filtering by visibility levels.';

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'class' => $schema
                ->string()
                ->description('Class identifier: EITHER full namespace (e.g., "App\\Models\\User", "Illuminate\\Support\\Collection") OR file path (e.g., "app/Models/User.php", "vendor/laravel/framework/src/Illuminate/Support/Collection.php"). File path mode is perfect when you know the location but not the namespace.')
                ->required(),
            'constants' => $schema
                ->boolean()
                ->description('Include class constants with their values and docblocks. Shows all const definitions (CREATED_AT, STATUS_*, etc). Set false to exclude. Default: true.')
                ->default(true),
            'properties' => $schema
                ->boolean()
                ->description('Include class properties ($fillable, $casts, public/protected/private vars) with types, default values, visibility flags, and docblocks. Essential for understanding model structure. Set false to exclude. Default: true.')
                ->default(true),
            'methods' => $schema
                ->boolean()
                ->description('Include class methods with full signatures: parameters, return types, visibility, static flag, and docblocks. Core of API introspection. Set false to exclude. Default: true.')
                ->default(true),
            'include_inherited' => $schema
                ->boolean()
                ->description('POWERFUL: Include ALL inherited members from parent classes and traits. When true, shows complete API including Eloquent base methods, framework parent methods, etc. Perfect for understanding full available API of models/controllers. When false (default), shows only members declared directly in this class. Example: User model with true shows save(), find(), all() from Eloquent parent.')
                ->default(false),
            'summary' => $schema
                ->boolean()
                ->description('Control docblock verbosity. When true (default): show only summary line. When false: show full docblocks with descriptions, @param, @return, @throws tags. Set false when you need complete documentation details.')
                ->default(true),
            'full_docblocks' => $schema
                ->boolean()
                ->description('CONVENIENCE: Show full docblocks for ALL elements (constants, properties, methods). Equivalent to setting summary=false. When true: displays complete docblocks with descriptions and tags everywhere. When false (default): respects summary parameter. Overrides summary parameter if set to true.')
                ->default(false),
            'visibility' => $schema
                ->string()
                ->description('Filter members by visibility level. Options: "public" (default, API methods only), "protected" (internal methods), "public,protected" (both), "all" (including private). Use "all" for complete internal analysis, "public" for API documentation.')
                ->default('public'),
            'methods_offset' => $schema
                ->integer()
                ->description('PAGINATION: Skip first N methods. Useful for large classes (Eloquent Model has 100+ methods). Example: offset=0, limit=20 shows first 20 methods; offset=20, limit=20 shows next 20. Default: 0 (no skip).')
                ->default(0),
            'methods_limit' => $schema
                ->integer()
                ->description('PAGINATION: Maximum methods to return. Set to positive number for large classes to avoid huge responses. Example: limit=20 returns max 20 methods. Set 0 for unlimited (default). Combine with offset for pagination.')
                ->default(0),
            'properties_offset' => $schema
                ->integer()
                ->description('PAGINATION: Skip first N properties. Rarely needed unless class has many properties. Works same as methods_offset. Default: 0.')
                ->default(0),
            'properties_limit' => $schema
                ->integer()
                ->description('PAGINATION: Maximum properties to return. Set positive number to limit output. Set 0 for unlimited (default). Works same as methods_limit.')
                ->default(0),
            'constants_offset' => $schema
                ->integer()
                ->description('PAGINATION: Skip first N constants. Useful for classes with many constants (status codes, config constants). Default: 0.')
                ->default(0),
            'constants_limit' => $schema
                ->integer()
                ->description('PAGINATION: Maximum constants to return. Set positive number to limit output. Set 0 for unlimited (default).')
                ->default(0),
            'static_only' => $schema
                ->boolean()
                ->description('ADVANCED FILTER: Show ONLY static methods (::method). Perfect for finding factory methods, helper methods, static APIs. Example: Collection::make(), User::find(). Set true to exclude instance methods. Default: false (show all).')
                ->default(false),
            'summary_mode' => $schema
                ->boolean()
                ->description('CONVENIENCE: Enable concise summary mode for quick class overview. When true: automatically sets constants=false, properties=false, methods_limit=5, include_inherited=false for compact output showing only essential class structure. Perfect for quick API exploration. Individual parameters can still override these defaults. Default: false.')
                ->default(false),
            'raw_docblock' => $schema
                ->boolean()
                ->description('EXPERT: Show raw docblock text as-is from source code for all elements. Useful for advanced analysis or custom parsing. When true: displays unprocessed docblock strings. When false (default): shows parsed/cleaned docblocks. Default: false.')
                ->default(false),
        ];
    }

    /**
     * Handle the tool request.
     *
     * @throws \ReflectionException
     */
    public function handle(Request $request): Response
    {
        $class = $request->get('class');

        // Check if it's a file path (relative or absolute)
        $filePath = null;
        if (is_file($class)) {
            $filePath = realpath($class);
        } elseif (is_file($basePath = base_path($class))) {
            $filePath = realpath($basePath);
        }

        if ($filePath) {
            $collectionOfClasses = Attributes::new()
                ->wherePath(dirname($filePath))
                ->classes();

            if ($collectionOfClasses->isEmpty()) {
                return Response::error("No classes found in file: {$class}");
            }

            /** @var ReflectionClass $reflector */
            $reflector = $collectionOfClasses->first(function (ReflectionClass $ref) use ($filePath) {
                $refFileName = $ref->getFileName();

                return $refFileName && realpath($refFileName) === $filePath;
            });

            if (! $reflector) {
                return Response::error("Class not found in file: {$class}");
            }
        } elseif (
            ! class_exists($class)
            && ! trait_exists($class)
            && ! interface_exists($class)
            && ! enum_exists($class)
        ) {
            return Response::error("Class not found: {$class}");
        } else {
            $reflector = new ReflectionClass($class);
        }
        $this->comments = true;
        $this->rawDockBlock = (bool) $request->get('raw_docblock', true);
        $this->constants = (bool) $request->get('constants', true);
        $this->properties = (bool) $request->get('properties', true);
        $this->methods = (bool) $request->get('methods', true);
        $this->include_inherited = (bool) $request->get('include_inherited', false);
        $this->summary = (bool) $request->get('summary', true);
        $this->visibility = (string) $request->get('visibility', 'public');

        // full_docblocks overrides summary parameter (inverse logic)
        $fullDocblocks = (bool) $request->get('full_docblocks', false);
        if ($fullDocblocks) {
            $this->summary = false;
        }

        // Set pagination properties for methods
        $this->methods_offset = (int) $request->get('methods_offset', 0);
        $this->methods_limit = (int) $request->get('methods_limit', 0);

        // Set pagination properties for properties
        $this->properties_offset = (int) $request->get('properties_offset', 0);
        $this->properties_limit = (int) $request->get('properties_limit', 0);

        // Set pagination properties for constants
        $this->constants_offset = (int) $request->get('constants_offset', 0);
        $this->constants_limit = (int) $request->get('constants_limit', 0);

        // Set static filtering
        $this->static_only = (bool) $request->get('static_only', false);

        // Apply summary_mode overrides (compact output for quick class overview)
        $summaryMode = (bool) $request->get('summary_mode', false);
        if ($summaryMode) {
            $this->constants = false;
            $this->properties = false;
            $this->methods_limit = 5;
            $this->include_inherited = false;
        }

        $classInfo = $this->classInformation($reflector, true);
        $entry = $this->renderClassInformation($classInfo);

        if (! empty($entry)) {
            return Response::text($entry);
        }

        return Response::error('No class information available.');
    }
}
