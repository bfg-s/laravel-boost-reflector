# Laravel Boost Reflector

[![Latest Version](https://img.shields.io/packagist/v/bfg/laravel-boost-reflector?style=flat-square)](https://packagist.org/packages/bfg/laravel-boost-reflector)
[![Total Downloads](https://img.shields.io/packagist/dt/bfg/laravel-boost-reflector?style=flat-square)](https://packagist.org/packages/bfg/laravel-boost-reflector)
[![License](https://img.shields.io/packagist/l/bfg/laravel-boost-reflector?style=flat-square)](https://packagist.org/packages/bfg/laravel-boost-reflector)

Laravel Boost Reflector extends the [Laravel Boost MCP server](https://github.com/laravel/boost) with powerful PHP class reflection and analysis capabilities. It provides three specialized tools for deep code introspection, enabling code analysis, documentation generation, refactoring planning, and architecture understanding.

Built on top of [Roave BetterReflection](https://github.com/Roave/BetterReflection), this package offers fast class discovery with advanced filtering, comprehensive API introspection including inherited members, and dependency usage analysis across your entire codebase—including vendor packages.

Whether you're exploring Laravel's Eloquent API, planning a major refactoring, generating documentation, or analyzing architectural dependencies, Laravel Boost Reflector provides the metadata you need with precision and performance.

## Installation

Install the package via Composer:

```bash
composer require bfg/laravel-boost-reflector
```

The package will automatically register its MCP tools with Laravel Boost.

## Available Tools

| Tool | Description |
|------|-------------|
| **class-list** | Fast discovery of PHP classes with powerful filtering by traits, interfaces, and methods |
| **class-detail** | Deep introspection of class structure including methods, properties, constants, and complete inheritance hierarchy |
| **class-usages** | Dependency analysis showing where and how classes are used throughout the codebase |

## Usage Examples

### class-list

Fast discovery of PHP classes with powerful filtering capabilities. Perfect for finding classes that match specific criteria like traits, interfaces, or methods.

**Find all Eloquent models with HasFactory trait:**

```json
{
  "path": "app/Models",
  "has_trait": "Illuminate\\Database\\Eloquent\\Factories\\HasFactory"
}
```

**Find all controllers:**

```json
{
  "path": "app/Http/Controllers",
  "recursive": true
}
```

**Find classes with boot() method:**

```json
{
  "path": "app",
  "has_method": "boot",
  "limit": 20
}
```

**Example output structure:**

```json
[
  {
    "file": "/path/to/app/Models/User.php",
    "name": "App\\Models\\User",
    "parent": "Illuminate\\Foundation\\Auth\\User",
    "interfaces": ["Illuminate\\Contracts\\Auth\\MustVerifyEmail"],
    "traits": [
      "Illuminate\\Database\\Eloquent\\Factories\\HasFactory",
      "Illuminate\\Notifications\\Notifiable"
    ],
    "docblock": "User model representing authenticated users"
  }
]
```

**Parameters:**
- `path` (required) - Directory to scan
- `has_trait` - Filter by trait (full namespace)
- `has_interface` - Filter by interface (full namespace)
- `has_method` - Filter by method name
- `recursive` - Scan subdirectories (default: true)
- `limit` - Maximum results to return
- `offset` - Skip first N results

**Use cases:**
- Find all Eloquent models with specific traits (SoftDeletes, HasFactory)
- Locate all controllers or middleware in your application
- Discover classes implementing specific interfaces (ShouldQueue, Responsable)
- Identify classes with specific methods for refactoring

---

### class-detail

Deep introspection of any PHP class with complete API details. Returns comprehensive information about methods, properties, constants, parameters, return types, visibility, and docblocks.

**Explore Eloquent Model API (including inherited methods):**

```json
{
  "class": "Illuminate\\Database\\Eloquent\\Model",
  "methods": true,
  "include_inherited": true,
  "visibility": "public",
  "methods_limit": 20
}
```

**Analyze a specific project class:**

```json
{
  "class": "App\\Models\\User",
  "constants": true,
  "properties": true,
  "methods": true,
  "full_docblocks": true
}
```

**Quick overview with summary mode:**

```json
{
  "class": "App\\Services\\PaymentService",
  "summary_mode": true
}
```

**Find all static helper methods:**

```json
{
  "class": "Illuminate\\Support\\Str",
  "methods": true,
  "static_only": true,
  "visibility": "public"
}
```

**Example output structure:**

```json
{
  "name": "App\\Models\\User",
  "file": "/path/to/app/Models/User.php",
  "parent": "Illuminate\\Foundation\\Auth\\User",
  "interfaces": ["Illuminate\\Contracts\\Auth\\MustVerifyEmail"],
  "traits": ["HasFactory", "Notifiable"],
  "methods": [
    {
      "name": "posts",
      "visibility": "public",
      "static": false,
      "return_type": "Illuminate\\Database\\Eloquent\\Relations\\HasMany",
      "parameters": [],
      "docblock": "Get all posts for the user"
    }
  ],
  "properties": [
    {
      "name": "fillable",
      "visibility": "protected",
      "type": "array",
      "default": ["name", "email", "password"]
    }
  ]
}
```

**Parameters:**
- `class` (required) - Full class namespace or file path
- `constants` - Include class constants (default: true)
- `properties` - Include properties (default: true)
- `methods` - Include methods (default: true)
- `include_inherited` - Show inherited members from parent classes (default: false)
- `visibility` - Filter by visibility: "public", "protected", "all" (default: "public")
- `static_only` - Show only static methods (default: false)
- `summary` - Show only summary lines in docblocks (default: true)
- `full_docblocks` - Show complete docblocks everywhere (default: false)
- `summary_mode` - Concise output for quick overview (default: false)
- `methods_limit`, `methods_offset` - Paginate methods
- `properties_limit`, `properties_offset` - Paginate properties
- `constants_limit`, `constants_offset` - Paginate constants

**Use cases:**
- Explore complete Eloquent Model API with all inherited methods
- Understand Laravel helper classes (Str, Arr, Collection)
- Generate API documentation from class metadata
- Analyze class structure before refactoring
- Discover available methods on vendor classes

---

### class-usages

Comprehensive dependency and usage analysis showing where and how classes are used throughout your codebase. Essential for refactoring impact analysis and understanding dependencies.

**Find all usages of a specific class:**

```json
{
  "target": "App\\Models\\User",
  "path": "app"
}
```

**Analyze Route facade usage:**

```json
{
  "target": "Illuminate\\Support\\Facades\\Route",
  "usage_types": ["import", "static_call"],
  "group_by_type": true
}
```

**Refactoring impact analysis:**

```json
{
  "target": "App\\Services\\PaymentService",
  "exclude_vendor": true,
  "group_by_type": true
}
```

**Find all classes extending a base class:**

```json
{
  "target": "App\\Http\\Controllers\\Controller",
  "usage_types": ["extends"]
}
```

**Example output structure:**

```json
{
  "target": "App\\Models\\User",
  "usages": [
    {
      "file": "/path/to/app/Http/Controllers/UserController.php",
      "line": 15,
      "type": "import",
      "context": "use App\\Models\\User;"
    },
    {
      "file": "/path/to/app/Http/Controllers/UserController.php",
      "line": 42,
      "type": "static_call",
      "context": "User::where('active', true)->get()"
    }
  ],
  "statistics": {
    "total_usages": 24,
    "by_type": {
      "import": 8,
      "static_call": 12,
      "new": 4
    },
    "by_file": {
      "/path/to/app/Http/Controllers/UserController.php": 5
    }
  },
  "scan_stats": {
    "files_scanned": 156,
    "scan_time_ms": 245
  }
}
```

**Parameters:**
- `target` (required) - Full class namespace to search for
- `path` - Directory to scan (default: "app")
- `usage_types` - Filter by types: "import", "new", "static_call", "extends", "implements", "trait", "type_hint"
- `exclude_vendor` - Skip vendor directory (default: true)
- `flush_cache` - Clear cached results before scanning (default: false)
- `group_by_type` - Group results by usage type (default: false)
- `sort_by` - Sort by: "line", "file", "type" (default: "line")
- `limit` - Maximum results (default: 100)
- `offset` - Skip first N results

**Use cases:**
- Refactoring impact analysis before changing a class
- Find all Route facade usages in your application
- Dead code detection (classes with zero usages)
- Understand dependency chains and coupling
- Track which classes extend or implement specific types

## Common Use Cases

### Architecture Analysis

Understand your application's structure by discovering all classes that implement specific interfaces or use particular traits:

```json
{
  "path": "app",
  "has_interface": "Illuminate\\Contracts\\Queue\\ShouldQueue"
}
```

### Refactoring Planning

Before refactoring a class, analyze its usage to understand the impact:

```json
{
  "target": "App\\Services\\LegacyService",
  "exclude_vendor": true,
  "group_by_type": true
}
```

### Documentation Generation

Extract complete API information from classes to generate documentation:

```json
{
  "class": "App\\Services\\ApiClient",
  "methods": true,
  "properties": true,
  "full_docblocks": true,
  "visibility": "public"
}
```

### Finding Models with Specific Traits

Discover all Eloquent models using SoftDeletes or other traits:

```json
{
  "path": "app/Models",
  "has_trait": "Illuminate\\Database\\Eloquent\\SoftDeletes"
}
```

### Laravel API Exploration

Learn what methods are available on Laravel's core classes, including inherited methods:

```json
{
  "class": "Illuminate\\Support\\Collection",
  "methods": true,
  "include_inherited": true,
  "visibility": "public",
  "static_only": false
}
```

### Dead Code Detection

Find classes that are never used in your codebase:

```json
{
  "target": "App\\Services\\UnusedService",
  "path": "app"
}
```

## Requirements

- PHP 8.1 or higher
- Laravel Boost ^1.8

## Credits

- [Xsaven](https://github.com/bfg-s)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.