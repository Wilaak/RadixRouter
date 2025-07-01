# RadixRouter

Simple implementation of a radix tree based router.

### Overview

- Supports parameterized routes (e.g., `/user/:id`) and wildcard trailing routes (e.g., `/files/:path*`)
- Fast direct lookup for static routes ensures optimal performance
- Built-in handling for "Method Not Allowed" responses
- Lightweight: single file, zero dependencies, under 200 lines of code

## Install

Install with composer:

    composer require wilaak/radix-router

Requires PHP 8.1 or newer.

## Usage

Here's a basic usage example:

```php
use Wilaak\Http\RadixRouter;

$router = new RadixRouter();

$router->addRoute(['GET'], '/users/:id', function ($id) {
    echo "User ID: $id";
});

$router->addRoute(['POST'], '/users', function () {
    echo 'User created';
});

$router->addRoute(['GET'], '/files/:path*', function ($path) {
    echo "File path: $path";
});

$info = $router->dispatch('GET', '/users/123');

switch ($info['status']) {
    case RadixRouter::DISPATCH_FOUND:
        call_user_func_array(
            $info['handler'],
            $info['params']
        );
        break;

    case RadixRouter::DISPATCH_NOT_FOUND:
        http_response_code(404);
        echo '404 Not Found';
        break;

    case RadixRouter::DISPATCH_NOT_ALLOWED:
        header('Allow: ' . implode(', ', $info['methods']));
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}
```

### Defining routes

Define routes using `addRoute([methods], pattern, handler)`. Patterns can be static (e.g., `/about`), parameterized (e.g., `/user/:id`), or wildcard (e.g., `/files/:path*`).

```php
$router->addRoute(['GET'], '/about', $aboutHandler);           // Static
$router->addRoute(['GET'], '/user/:id', $userHandler);         // Parameter
$router->addRoute(['GET'], '/files/:path*', $filesHandler);    // Wildcard
```

Static routes are matched first (exact path), parameter routes (e.g., `:id`) are matched next. Wildcard routes (e.g., `:path*`) are matched last.

### How to Cache Routes

Rebuilding the route tree on every request or application startup can slow down your application. By caching your routes, you can significantly improve startup performance and ensure your router is ready to handle requests instantly.

> **Note:**  
> Anonymous functions (closures) are **not supported** for route caching because they cannot be serialized.

> **Note:**  
> When implementing route caching, care should be taken to avoid race conditions when rebuilding the cache file. Ensure that the cache is written atomically so that each request can always fully load a valid cache file without errors or partial data.

Here is a simple cache implementation:

```php
$cacheFile = __DIR__ . '/routes.cache.php';
if (!file_exists($cacheFile)) {
    // Build routes
    $router->addRoute(['GET'], '/', $handler);
    // Export generated routes 
    file_put_contents($cacheFile, '<?php return ' . var_export($router->routes, true) . ';');
} else {
    // Load routes from cache
    $router->routes = require $cacheFile;
}

// Dispatch your routes here!
$router->dispatch('GET', '/your/path');
```

By storing your routes in a PHP file, you let PHP’s OPcache handle the heavy lifting, making startup times nearly instantaneous.

### HEAD Requests

According to the HTTP specification, servers should support both GET and HEAD methods. Any route that handles GET requests should also handle HEAD requests, returning the same headers but with an empty body.

If you're running RadixRouter outside of a standard web SAPI (for example, in a custom server), ensure that responses to HEAD requests do not include a message body. This helps maintain compatibility with the HTTP specification and ensures correct client behavior.

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.