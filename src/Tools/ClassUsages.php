<?php

declare(strict_types=1);

namespace Bfg\LaravelBoostReflector\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ClassUsages extends Tool
{
    /**
     * Tracked cache keys for selective flushing
     * @var array<string>
     */
    protected static array $trackedCacheKeys = [];

    /**
     * The tool's description.
     */
    protected string $description = 'Find all usages of a specific class throughout the codebase. Scans PHP files to locate imports, instantiations (new), static calls, inheritance (extends), interface implementations (implements), trait usage, and type hints (parameters + return types). Perfect for impact analysis, refactoring planning, dead code detection, and understanding class dependencies. Supports filtering by usage type, excluding vendor code, grouping, sorting, and pagination for large result sets.';

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema
                ->string()
                ->description('Class FQN to search (e.g., App\\Models\\User). The fully qualified class name to find usages of throughout the codebase.')
                ->required(),
            'path' => $schema
                ->string()
                ->description('Directory to scan for usages (relative to project root). Examples: "app" (default, entire application), "app/Http" (controllers and middleware), "app/Services" (service layer). Scans recursively through subdirectories.')
                ->default('app'),
            'usage_types' => $schema
                ->array()
                ->description('OPTIONAL: Filter by specific usage types. Available types: "import" (use statements), "new" (object instantiation), "static_call" (Class::method), "extends" (inheritance), "implements" (interface implementation), "trait" (trait usage), "type_hint" (parameter/return types). Leave empty to find all usage types.')
                ->items($schema->string())
                ->default([]),
            'exclude_vendor' => $schema
                ->boolean()
                ->description('Exclude vendor directory from scan. When true (default): only scan application code. When false: include vendor packages (useful for finding third-party dependencies on your classes).')
                ->default(true),
            'flush_cache' => $schema
                ->boolean()
                ->description('Clear usage analysis cache before scanning. When true: forces fresh scan of all files (slower but always current). When false (default): uses cached results if available (faster but may be stale after recent changes).')
                ->default(false),
            'limit' => $schema
                ->integer()
                ->description('PAGINATION: Maximum number of usage results to return. Set to positive number to limit output for classes with many usages. Example: limit=50 returns first 50 usages. Set 0 for unlimited (default). Combine with offset for pagination.')
                ->default(100),
            'offset' => $schema
                ->integer()
                ->description('PAGINATION: Skip first N usage results. Useful for paginating through large result sets. Example: offset=0, limit=50 shows first 50 usages; offset=50, limit=50 shows next 50. Default: 0 (no skip).')
                ->default(0),
            'group_by_type' => $schema
                ->boolean()
                ->description('Group results by usage type. When true: returns structured object with usages grouped by type (import, extends, etc.) and enhanced statistics. When false (default): returns flat array of all usages.')
                ->default(false),
            'sort_by' => $schema
                ->string()
                ->description('Sort results by: "line" (default, by line number), "file" (by file path), "type" (by usage type). Sorting applied before pagination.')
                ->default('line'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $startTime = microtime(true);

        // Extract parameters
        $target = $request->get('target', '');
        $path = $request->get('path', 'app');
        $usageTypes = $request->get('usage_types', []);
        $excludeVendor = $request->get('exclude_vendor', true);
        $flushCache = $request->get('flush_cache', false);
        $limit = $request->get('limit', 100);
        $offset = $request->get('offset', 0);
        $groupByType = $request->get('group_by_type', false);
        $sortBy = $request->get('sort_by', 'line');

        // Validate target class
        if ($target === '') {
            return Response::error('Target class is required');
        }

        // Flush cache if requested
        if ($flushCache) {
            $this->flushVendorCache();
        }

        // Get short class name for quick filtering
        $shortClassName = basename(str_replace('\\', '/', $target));

        // Scan files
        $files = $this->scanFiles($path, $excludeVendor);
        $filesScanned = count($files);
        $filesMatched = 0;
        $allUsages = [];

        // Analyze each file
        foreach ($files as $file) {
            // Quick filter: skip files that don't contain the short class name
            $content = file_get_contents($file);
            if (! str_contains($content, $shortClassName)) {
                continue;
            }

            // Check if vendor file for caching
            $isVendorFile = str_contains($file, '/vendor/');
            $cacheKey = 'class_usages_vendor_'.md5($file);

            // Get file usages (cached or fresh)
            if ($isVendorFile) {
                $fileUsages = Cache::get($cacheKey);
                if ($fileUsages === null) {
                    $fileUsages = $this->analyzeFile($file, $target, $usageTypes);
                    Cache::put($cacheKey, $fileUsages, now()->addDay());

                    // Track cache key for selective flushing
                    if (!in_array($cacheKey, static::$trackedCacheKeys)) {
                        static::$trackedCacheKeys[] = $cacheKey;
                    }
                }
            } else {
                $fileUsages = $this->analyzeFile($file, $target, $usageTypes);
            }

            // Add file path to each usage and collect
            if (! empty($fileUsages)) {
                $filesMatched++;
                foreach ($fileUsages as $usage) {
                    $allUsages[] = array_merge(['file' => str_replace(base_path().'/', '', $file)], $usage);
                }
            }
        }

        // Sort usages
        $allUsages = $this->sortUsages($allUsages, $sortBy);

        // Calculate scan time
        $scanTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Build statistics
        $statistics = $this->buildStatistics($allUsages);

        // Apply pagination
        $totalUsages = count($allUsages);
        $paginatedUsages = array_slice($allUsages, $offset, $limit > 0 ? $limit : null);

        // Build response based on group_by_type
        if ($groupByType) {
            $grouped = $this->groupByType($paginatedUsages);
            $response = [
                'target' => $target,
                'type' => 'class',
                'total_usages' => $totalUsages,
                'scan_stats' => [
                    'files_scanned' => $filesScanned,
                    'files_matched' => $filesMatched,
                    'scan_time_ms' => $scanTimeMs,
                ],
                'statistics' => $statistics,
                'usages_by_type' => $grouped,
            ];
        } else {
            $response = [
                'target' => $target,
                'type' => 'class',
                'total_usages' => $totalUsages,
                'scan_stats' => [
                    'files_scanned' => $filesScanned,
                    'files_matched' => $filesMatched,
                    'scan_time_ms' => $scanTimeMs,
                ],
                'statistics' => $statistics,
                'usages' => $paginatedUsages,
            ];
        }

        return Response::text(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Sort usages by specified criterion.
     *
     * @param  array<int, array<string, mixed>>  $usages
     * @return array<int, array<string, mixed>>
     */
    private function sortUsages(array $usages, string $sortBy): array
    {
        usort($usages, function ($a, $b) use ($sortBy) {
            return match ($sortBy) {
                'file' => strcmp($a['file'] ?? '', $b['file'] ?? ''),
                'type' => strcmp($a['usage_type'] ?? '', $b['usage_type'] ?? ''),
                default => ($a['line'] ?? 0) <=> ($b['line'] ?? 0),
            };
        });

        return $usages;
    }

    /**
     * Build usage statistics.
     *
     * @param  array<int, array<string, mixed>>  $usages
     * @return array<string, mixed>
     */
    private function buildStatistics(array $usages): array
    {
        $byType = [];
        $byFile = [];

        foreach ($usages as $usage) {
            $type = $usage['usage_type'] ?? 'unknown';
            $file = $usage['file'] ?? 'unknown';

            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byFile[$file] = ($byFile[$file] ?? 0) + 1;
        }

        // Find most used file
        $mostUsedIn = null;
        $maxCount = 0;
        foreach ($byFile as $file => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $mostUsedIn = $file;
            }
        }

        return [
            'by_type' => $byType,
            'by_file' => $byFile,
            'most_used_in' => $mostUsedIn,
        ];
    }

    /**
     * Group usages by type.
     *
     * @param  array<int, array<string, mixed>>  $usages
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByType(array $usages): array
    {
        $grouped = [];

        foreach ($usages as $usage) {
            $type = $usage['usage_type'] ?? 'unknown';
            if (! isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $usage;
        }

        return $grouped;
    }

    /**
     * Flush all vendor cache entries.
     */
    private function flushVendorCache(): void
    {
        // Clear only tracked vendor cache keys (selective flushing)
        foreach (static::$trackedCacheKeys as $key) {
            Cache::forget($key);
        }

        // Clear tracked keys array after flushing
        static::$trackedCacheKeys = [];

        // Note: For Redis driver, you could use Cache::tags() or pattern deletion
        // For example: Redis::del(Redis::keys('class_usages_vendor_*'))
    }

    /**
     * Analyze a single file for class usages.
     *
     * @return array<int, array{line: int, usage_type: string, code: string}>
     */
    private function analyzeFile(string $file, string $targetClass, array $usageTypes): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        // Tokenize
        $tokens = token_get_all($content);

        // Build namespace context
        $namespaceMap = $this->buildNamespaceMap($tokens);
        $currentNamespace = $this->getCurrentNamespace($tokens);

        // Detect usages based on requested types
        $usages = [];

        if (empty($usageTypes)) {
            // All types
            $usages = array_merge(
                $this->detectImports($tokens, $targetClass, $namespaceMap),
                $this->detectNew($tokens, $targetClass, $namespaceMap, $currentNamespace),
                $this->detectStaticCalls($tokens, $targetClass, $namespaceMap, $currentNamespace),
                $this->detectExtends($tokens, $targetClass, $namespaceMap, $currentNamespace),
                $this->detectImplements($tokens, $targetClass, $namespaceMap, $currentNamespace),
                $this->detectTraitUsage($tokens, $targetClass, $namespaceMap, $currentNamespace),
                $this->detectTypeHints($tokens, $targetClass, $namespaceMap, $currentNamespace),
                $this->detectReturnTypeHints($tokens, $targetClass, $namespaceMap, $currentNamespace)
            );
        } else {
            // Specific types
            if (in_array('import', $usageTypes)) {
                $usages = array_merge($usages, $this->detectImports($tokens, $targetClass, $namespaceMap));
            }
            if (in_array('new', $usageTypes)) {
                $usages = array_merge($usages, $this->detectNew($tokens, $targetClass, $namespaceMap, $currentNamespace));
            }
            if (in_array('static_call', $usageTypes)) {
                $usages = array_merge($usages, $this->detectStaticCalls($tokens, $targetClass, $namespaceMap, $currentNamespace));
            }
            if (in_array('extends', $usageTypes)) {
                $usages = array_merge($usages, $this->detectExtends($tokens, $targetClass, $namespaceMap, $currentNamespace));
            }
            if (in_array('implements', $usageTypes)) {
                $usages = array_merge($usages, $this->detectImplements($tokens, $targetClass, $namespaceMap, $currentNamespace));
            }
            if (in_array('trait', $usageTypes)) {
                $usages = array_merge($usages, $this->detectTraitUsage($tokens, $targetClass, $namespaceMap, $currentNamespace));
            }
            if (in_array('type_hint', $usageTypes)) {
                $usages = array_merge(
                    $usages,
                    $this->detectTypeHints($tokens, $targetClass, $namespaceMap, $currentNamespace),
                    $this->detectReturnTypeHints($tokens, $targetClass, $namespaceMap, $currentNamespace)
                );
            }
        }

        return $usages;
    }

    /**
     * Build namespace map from use statements.
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @return array<string, string>
     */
    private function buildNamespaceMap(array $tokens): array
    {
        $map = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            // Find T_USE token (but skip class-level trait usage)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_USE) {
                // Check if this is a class-level trait usage by looking backwards for T_CLASS
                $isTraitUsage = false;
                for ($j = $i - 1; $j >= max(0, $i - 20); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CLASS) {
                        $isTraitUsage = true;
                        break;
                    }
                    if ($tokens[$j] === ';' || (is_array($tokens[$j]) && $tokens[$j][0] === T_NAMESPACE)) {
                        break;
                    }
                }

                if ($isTraitUsage) {
                    $i++;

                    continue;
                }

                $i++;
                $namespace = '';
                $alias = '';

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Collect namespace parts
                while ($i < $count) {
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $namespace .= $tokens[$i][1];
                    } elseif ($tokens[$i] === '\\') {
                        $namespace .= '\\';
                    } elseif (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        // Skip whitespace
                    } elseif (is_array($tokens[$i]) && $tokens[$i][0] === T_AS) {
                        // Handle alias
                        $i++;
                        while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                            $i++;
                        }
                        if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                            $alias = $tokens[$i][1];
                        }
                        break;
                    } elseif ($tokens[$i] === ';' || $tokens[$i] === ',') {
                        break;
                    } else {
                        break;
                    }
                    $i++;
                }

                // Add to map
                if ($namespace !== '') {
                    $key = $alias !== '' ? $alias : basename(str_replace('\\', '/', $namespace));
                    $map[$key] = $namespace;
                }
            }

            $i++;
        }

        return $map;
    }

    /**
     * Resolve short class name to fully qualified name.
     */
    private function resolveClassName(string $shortName, array $namespaceMap, string $currentNamespace): string
    {
        // Already FQN (starts with \)
        if (str_starts_with($shortName, '\\')) {
            return ltrim($shortName, '\\');
        }

        // Check if exists in namespace map (alias or imported class)
        if (isset($namespaceMap[$shortName])) {
            return $namespaceMap[$shortName];
        }

        // Prepend current namespace
        if ($currentNamespace !== '') {
            return $currentNamespace.'\\'.$shortName;
        }

        return $shortName;
    }

    /**
     * Scan directory for PHP files.
     *
     * @return array<int, string>
     */
    private function scanFiles(string $path, bool $excludeVendor): array
    {
        $files = [];
        $absolutePath = base_path($path);

        if (! is_dir($absolutePath)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();

                // Exclude vendor directory if requested
                if ($excludeVendor && str_contains($filePath, '/vendor/')) {
                    continue;
                }

                $files[] = $filePath;
            }
        }

        return $files;
    }

    /**
     * Extract current namespace from tokens.
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     */
    private function getCurrentNamespace(array $tokens): string
    {
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            // Find T_NAMESPACE token
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
                $i++;
                $namespace = '';

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Collect namespace parts
                while ($i < $count) {
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
                        $namespace .= $tokens[$i][1];
                    } elseif ($tokens[$i] === '\\') {
                        $namespace .= '\\';
                    } elseif ($tokens[$i] === ';' || $tokens[$i] === '{') {
                        break;
                    } else {
                        $i++;

                        continue;
                    }
                    $i++;
                }

                return $namespace;
            }

            $i++;
        }

        return '';
    }

    /**
     * Detect import statements (use declarations).
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @param  array<string, string>  $namespaceMap
     * @return array<int, array{line: int, code: string, usage_type: string}>
     */
    private function detectImports(array $tokens, string $targetClass, array $namespaceMap): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_USE) {
                // Check if this is a class-level trait usage
                $isTraitUsage = false;
                for ($j = $i - 1; $j >= max(0, $i - 20); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CLASS) {
                        $isTraitUsage = true;
                        break;
                    }
                    if ($tokens[$j] === ';' || (is_array($tokens[$j]) && $tokens[$j][0] === T_NAMESPACE)) {
                        break;
                    }
                }

                if ($isTraitUsage) {
                    $i++;

                    continue;
                }

                $line = $tokens[$i][2];
                $startIndex = $i;
                $i++;
                $namespace = '';
                $alias = '';

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Collect namespace parts
                while ($i < $count) {
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $namespace .= $tokens[$i][1];
                    } elseif ($tokens[$i] === '\\') {
                        $namespace .= '\\';
                    } elseif (is_array($tokens[$i]) && $tokens[$i][0] === T_AS) {
                        // Handle alias
                        $i++;
                        while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                            $i++;
                        }
                        if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                            $alias = $tokens[$i][1];
                        }
                        break;
                    } elseif ($tokens[$i] === ';' || $tokens[$i] === ',') {
                        break;
                    }
                    $i++;
                }

                // Check if matches target class
                if ($namespace !== '' && ltrim($namespace, '\\') === ltrim($targetClass, '\\')) {
                    // Reconstruct code from tokens
                    $code = '';
                    for ($j = $startIndex; $j <= $i; $j++) {
                        if (is_array($tokens[$j])) {
                            $code .= $tokens[$j][1];
                        } else {
                            $code .= $tokens[$j];
                        }
                    }

                    $usages[] = [
                        'line' => $line,
                        'code' => trim($code),
                        'usage_type' => 'import',
                    ];
                }
            }

            $i++;
        }

        return $usages;
    }

    /**
     * Detect object instantiation (new keyword).
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @param  array<string, string>  $namespaceMap
     * @return array<int, array{line: int, code: string, usage_type: string}>
     */
    private function detectNew(array $tokens, string $targetClass, array $namespaceMap, string $currentNamespace): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NEW) {
                $line = $tokens[$i][2];
                $startIndex = $i;
                $i++;

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Get class name after 'new'
                $className = '';
                if ($i < $count && is_array($tokens[$i])) {
                    if (in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $className = $tokens[$i][1];

                        // Continue collecting if there are namespace separators
                        $tempIndex = $i + 1;
                        while ($tempIndex < $count) {
                            if ($tokens[$tempIndex] === '\\' || (is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_NS_SEPARATOR)) {
                                $className .= '\\';
                                $tempIndex++;
                                if ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_STRING) {
                                    $className .= $tokens[$tempIndex][1];
                                    $tempIndex++;
                                } else {
                                    break;
                                }
                            } else {
                                break;
                            }
                        }
                        $i = $tempIndex;
                    }
                }

                // Resolve class name to FQN
                if ($className !== '') {
                    $fqn = $this->resolveClassName($className, $namespaceMap, $currentNamespace);

                    // Check if matches target class
                    if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                        // Reconstruct code from tokens
                        $code = '';
                        $endIndex = $i;
                        for ($j = $startIndex; $j < $endIndex; $j++) {
                            if (is_array($tokens[$j])) {
                                $code .= $tokens[$j][1];
                            } else {
                                $code .= $tokens[$j];
                            }
                        }

                        $usages[] = [
                            'line' => $line,
                            'code' => trim($code),
                            'usage_type' => 'new',
                        ];
                    }
                }
            }

            $i++;
        }

        return $usages;
    }

    /**
     * Detect static method calls (Class::method).
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @param  array<string, string>  $namespaceMap
     * @return array<int, array{line: int, code: string, usage_type: string, method?: string}>
     */
    private function detectStaticCalls(array $tokens, string $targetClass, array $namespaceMap, string $currentNamespace): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOUBLE_COLON) {
                $line = $tokens[$i][2];

                // Go back to find class name before ::
                $className = '';
                $tempIndex = $i - 1;

                // Skip whitespace backwards
                while ($tempIndex >= 0 && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_WHITESPACE) {
                    $tempIndex--;
                }

                // Collect class name backwards
                $classNameParts = [];
                while ($tempIndex >= 0) {
                    if (is_array($tokens[$tempIndex]) && in_array($tokens[$tempIndex][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $classNameParts[] = $tokens[$tempIndex][1];
                        $tempIndex--;
                    } elseif ($tempIndex >= 0 && ($tokens[$tempIndex] === '\\' || (is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_NS_SEPARATOR))) {
                        $classNameParts[] = '\\';
                        $tempIndex--;
                    } else {
                        break;
                    }
                }

                // Reverse to get correct order
                $className = implode('', array_reverse($classNameParts));
                $startIndex = $tempIndex + 1;

                // Resolve class name to FQN
                if ($className !== '') {
                    $fqn = $this->resolveClassName($className, $namespaceMap, $currentNamespace);

                    // Check if matches target class
                    if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                        // Find end of static call (method name or constant)
                        $endIndex = $i + 1;
                        while ($endIndex < $count && is_array($tokens[$endIndex]) && $tokens[$endIndex][0] === T_WHITESPACE) {
                            $endIndex++;
                        }

                        // Extract method name
                        $methodName = '';
                        if ($endIndex < $count && is_array($tokens[$endIndex]) && $tokens[$endIndex][0] === T_STRING) {
                            $methodName = $tokens[$endIndex][1];
                            $endIndex++;
                        }

                        // Reconstruct code from tokens
                        $code = '';
                        for ($j = $startIndex; $j < $endIndex; $j++) {
                            if (is_array($tokens[$j])) {
                                $code .= $tokens[$j][1];
                            } else {
                                $code .= $tokens[$j];
                            }
                        }

                        $usage = [
                            'line' => $line,
                            'code' => trim($code),
                            'usage_type' => 'static_call',
                        ];

                        if ($methodName !== '') {
                            $usage['method'] = $methodName;
                        }

                        $usages[] = $usage;
                    }
                }
            }

            $i++;
        }

        return $usages;
    }

    /**
     * Detect class inheritance (extends keyword).
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @param  array<string, string>  $namespaceMap
     * @return array<int, array{line: int, code: string, usage_type: string}>
     */
    private function detectExtends(array $tokens, string $targetClass, array $namespaceMap, string $currentNamespace): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_EXTENDS) {
                $line = $tokens[$i][2];
                $startIndex = $i;
                $i++;

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Get class name after 'extends'
                $className = '';
                if ($i < $count && is_array($tokens[$i])) {
                    if (in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $className = $tokens[$i][1];

                        // Continue collecting if there are namespace separators
                        $tempIndex = $i + 1;
                        while ($tempIndex < $count) {
                            if ($tokens[$tempIndex] === '\\' || (is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_NS_SEPARATOR)) {
                                $className .= '\\';
                                $tempIndex++;
                                if ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_STRING) {
                                    $className .= $tokens[$tempIndex][1];
                                    $tempIndex++;
                                } else {
                                    break;
                                }
                            } else {
                                break;
                            }
                        }
                        $i = $tempIndex;
                    }
                }

                // Resolve class name to FQN
                if ($className !== '') {
                    $fqn = $this->resolveClassName($className, $namespaceMap, $currentNamespace);

                    // Check if matches target class
                    if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                        // Reconstruct code from tokens
                        $code = '';
                        for ($j = $startIndex; $j < $i; $j++) {
                            if (is_array($tokens[$j])) {
                                $code .= $tokens[$j][1];
                            } else {
                                $code .= $tokens[$j];
                            }
                        }

                        $usages[] = [
                            'line' => $line,
                            'code' => trim($code),
                            'usage_type' => 'extends',
                        ];
                    }
                }
            }

            $i++;
        }

        return $usages;
    }

    /**
     * Detect interface implementation (implements keyword).
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @param  array<string, string>  $namespaceMap
     * @return array<int, array{line: int, code: string, usage_type: string}>
     */
    private function detectImplements(array $tokens, string $targetClass, array $namespaceMap, string $currentNamespace): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_IMPLEMENTS) {
                $line = $tokens[$i][2];
                $startIndex = $i;
                $i++;

                // Collect all interface names (can be comma-separated)
                $interfaces = [];
                $currentInterface = '';

                while ($i < $count) {
                    // Skip whitespace
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;

                        continue;
                    }

                    // Collect interface name
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $currentInterface .= $tokens[$i][1];
                        $i++;

                        continue;
                    }

                    // Handle namespace separator
                    if ($tokens[$i] === '\\' || (is_array($tokens[$i]) && $tokens[$i][0] === T_NS_SEPARATOR)) {
                        $currentInterface .= '\\';
                        $i++;

                        continue;
                    }

                    // Comma means multiple interfaces
                    if ($tokens[$i] === ',') {
                        if ($currentInterface !== '') {
                            $interfaces[] = $currentInterface;
                            $currentInterface = '';
                        }
                        $i++;

                        continue;
                    }

                    // End of implements clause
                    if ($tokens[$i] === '{' || (is_array($tokens[$i]) && $tokens[$i][0] === T_EXTENDS)) {
                        if ($currentInterface !== '') {
                            $interfaces[] = $currentInterface;
                        }
                        break;
                    }

                    $i++;
                }

                // Add last interface if exists
                if ($currentInterface !== '') {
                    $interfaces[] = $currentInterface;
                }

                // Check each interface against target class
                foreach ($interfaces as $interface) {
                    $fqn = $this->resolveClassName($interface, $namespaceMap, $currentNamespace);

                    if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                        // Reconstruct code from tokens
                        $code = '';
                        for ($j = $startIndex; $j < $i; $j++) {
                            if (is_array($tokens[$j])) {
                                $code .= $tokens[$j][1];
                            } else {
                                $code .= $tokens[$j];
                            }
                        }

                        $usages[] = [
                            'line' => $line,
                            'code' => trim($code),
                            'usage_type' => 'implements',
                        ];
                        break; // Only add once per implements clause
                    }
                }
            }

            $i++;
        }

        return $usages;
    }

    /**
     * Detect trait usage (use trait within class body).
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @param  array<string, string>  $namespaceMap
     * @return array<int, array{line: int, code: string, usage_type: string}>
     */
    private function detectTraitUsage(array $tokens, string $targetClass, array $namespaceMap, string $currentNamespace): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_USE) {
                // Check if this is within a class (trait usage)
                $isWithinClass = false;
                for ($j = $i - 1; $j >= max(0, $i - 20); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CLASS) {
                        $isWithinClass = true;
                        break;
                    }
                    if ($tokens[$j] === ';' || (is_array($tokens[$j]) && $tokens[$j][0] === T_NAMESPACE)) {
                        break;
                    }
                }

                if (! $isWithinClass) {
                    $i++;

                    continue;
                }

                $line = $tokens[$i][2];
                $startIndex = $i;
                $i++;

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Collect trait names (can be comma-separated)
                $traits = [];
                $currentTrait = '';

                while ($i < $count) {
                    // Collect trait name
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $currentTrait .= $tokens[$i][1];
                        $i++;

                        continue;
                    }

                    // Handle namespace separator
                    if ($tokens[$i] === '\\' || (is_array($tokens[$i]) && $tokens[$i][0] === T_NS_SEPARATOR)) {
                        $currentTrait .= '\\';
                        $i++;

                        continue;
                    }

                    // Skip whitespace
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;

                        continue;
                    }

                    // Comma means multiple traits
                    if ($tokens[$i] === ',') {
                        if ($currentTrait !== '') {
                            $traits[] = $currentTrait;
                            $currentTrait = '';
                        }
                        $i++;

                        continue;
                    }

                    // End of use clause
                    if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                        if ($currentTrait !== '') {
                            $traits[] = $currentTrait;
                        }
                        break;
                    }

                    $i++;
                }

                // Check each trait against target class
                foreach ($traits as $trait) {
                    $fqn = $this->resolveClassName($trait, $namespaceMap, $currentNamespace);

                    if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                        // Reconstruct code from tokens
                        $code = '';
                        for ($j = $startIndex; $j <= $i; $j++) {
                            if (is_array($tokens[$j])) {
                                $code .= $tokens[$j][1];
                            } else {
                                $code .= $tokens[$j];
                            }
                        }

                        $usages[] = [
                            'line' => $line,
                            'code' => trim($code),
                            'usage_type' => 'trait',
                        ];
                        break;
                    }
                }
            }

            $i++;
        }

        return $usages;
    }

    /**
     * Detect type hints in function parameters and properties.
     *
     * @param  array<int, array<int, int|string>|string>  $tokens
     * @param  array<string, string>  $namespaceMap
     * @return array<int, array{line: int, code: string, usage_type: string}>
     */
    private function detectTypeHints(array $tokens, string $targetClass, array $namespaceMap, string $currentNamespace): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            // Check for function parameters
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {

                $line = $tokens[$i][2];
                $i++;

                // Find opening parenthesis
                while ($i < $count && $tokens[$i] !== '(') {
                    $i++;
                }

                if ($i >= $count) {
                    continue;
                }

                $i++; // Skip opening parenthesis

                // Parse parameters - simplified iteration without depth tracking
                while ($i < $count && $tokens[$i] !== ')') {
                    // Skip whitespace
                    while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }

                    if ($i >= $count || $tokens[$i] === ')') {
                        break;
                    }

                    // Look for type hints before variable
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $typeHint = $tokens[$i][1];
                        $typeStartIndex = $i;
                        $typeLine = $tokens[$i][2];

                        // Collect full type hint with namespace
                        $tempIndex = $i + 1;
                        while ($tempIndex < $count && (($tokens[$tempIndex] === '\\') || (is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_NS_SEPARATOR))) {
                            $typeHint .= '\\';
                            $tempIndex++;
                            if ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_STRING) {
                                $typeHint .= $tokens[$tempIndex][1];
                                $tempIndex++;
                            }
                        }

                        // Skip whitespace
                        while ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_WHITESPACE) {
                            $tempIndex++;
                        }

                        // Check if followed by variable (type hint confirmed)
                        if ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_VARIABLE) {
                            $fqn = $this->resolveClassName($typeHint, $namespaceMap, $currentNamespace);

                            if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                                // Reconstruct code from tokens
                                $code = '';
                                for ($j = $typeStartIndex; $j <= $tempIndex; $j++) {
                                    if (is_array($tokens[$j])) {
                                        $code .= $tokens[$j][1];
                                    } else {
                                        $code .= $tokens[$j];
                                    }
                                }

                                $usages[] = [
                                    'line' => $typeLine,
                                    'code' => trim($code),
                                    'usage_type' => 'type_hint',
                                ];
                            }
                        }

                        // Always update index after processing type hint (whether it matched or not)
                        $i = $tempIndex;
                    }

                    $i++;
                }

                continue;
            }

            // Check for property type hints
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
                $visibilityIndex = $i;
                $line = $tokens[$i][2];
                $i++;

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Skip static, readonly keywords
                while ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STATIC, T_READONLY])) {
                    $i++;
                    while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }
                }

                // If next token is T_FUNCTION, this is a method, not a property - skip this block
                if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                    continue;
                }

                // Check for type hint
                if ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                    $typeHint = $tokens[$i][1];
                    $typeStartIndex = $i;
                    $typeLine = $tokens[$i][2];

                    // Collect full type hint with namespace
                    $tempIndex = $i + 1;
                    while ($tempIndex < $count && (($tokens[$tempIndex] === '\\') || (is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_NS_SEPARATOR))) {
                        $typeHint .= '\\';
                        $tempIndex++;
                        if ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_STRING) {
                            $typeHint .= $tokens[$tempIndex][1];
                            $tempIndex++;
                        }
                    }

                    // Skip whitespace
                    while ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_WHITESPACE) {
                        $tempIndex++;
                    }

                    // Check if followed by variable (property type hint confirmed)
                    if ($tempIndex < $count && is_array($tokens[$tempIndex]) && $tokens[$tempIndex][0] === T_VARIABLE) {
                        $fqn = $this->resolveClassName($typeHint, $namespaceMap, $currentNamespace);

                        if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                            // Reconstruct code from tokens
                            $code = '';
                            for ($j = $visibilityIndex; $j <= $tempIndex; $j++) {
                                if (is_array($tokens[$j])) {
                                    $code .= $tokens[$j][1];
                                } else {
                                    $code .= $tokens[$j];
                                }
                            }

                            $usages[] = [
                                'line' => $typeLine,
                                'code' => trim($code),
                                'usage_type' => 'type_hint',
                            ];
                        }

                        $i = $tempIndex;
                    }
                }
            }

            $i++;
        }

        return $usages;
    }

    private function detectReturnTypeHints(array $tokens, string $targetClass, array $namespaceMap, string $currentNamespace): array
    {
        $usages = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            // Check for function declarations
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                $line = $tokens[$i][2];
                $i++;

                // Find closing parenthesis of parameters
                $depth = 0;
                $foundOpenParen = false;
                while ($i < $count) {
                    if ($tokens[$i] === '(') {
                        $depth++;
                        $foundOpenParen = true;
                    } elseif ($tokens[$i] === ')') {
                        $depth--;
                        if ($depth === 0 && $foundOpenParen) {
                            break;
                        }
                    }
                    $i++;
                }

                if ($i >= $count) {
                    continue;
                }

                $i++; // Move past closing parenthesis

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Check for colon (return type declaration)
                if ($i < $count && $tokens[$i] === ':') {
                    $startIndex = $i;
                    $i++;

                    // Skip whitespace
                    while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }

                    // Collect return type
                    $returnType = '';
                    $typeStartIndex = $i;
                    if ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                        $returnType = $tokens[$i][1];
                        $returnTypeLine = $tokens[$i][2];
                        $i++;

                        // Collect full type with namespace
                        while ($i < $count && (($tokens[$i] === '\\') || (is_array($tokens[$i]) && $tokens[$i][0] === T_NS_SEPARATOR))) {
                            $returnType .= '\\';
                            $i++;
                            if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                                $returnType .= $tokens[$i][1];
                                $i++;
                            }
                        }

                        // Resolve return type to FQN
                        if ($returnType !== '') {
                            $fqn = $this->resolveClassName($returnType, $namespaceMap, $currentNamespace);

                            if (ltrim($fqn, '\\') === ltrim($targetClass, '\\')) {
                                // Reconstruct code from tokens
                                $code = '';
                                for ($j = $startIndex; $j < $i; $j++) {
                                    if (is_array($tokens[$j])) {
                                        $code .= $tokens[$j][1];
                                    } else {
                                        $code .= $tokens[$j];
                                    }
                                }

                                $usages[] = [
                                    'line' => $returnTypeLine,
                                    'code' => trim($code),
                                    'usage_type' => 'type_hint',
                                ];
                            }
                        }
                    }
                }

                continue;
            }

            $i++;
        }

        return $usages;
    }
}
