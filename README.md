# RadixRouter

Simple implementation of a radix tree based router.

### Overview

- Supports parameterized (e.g., `/user/{id}`) and wildcard trailing routes (e.g., `/files/{path*}`)
- Fast, zero-allocation route matching powered by a radix tree.
- Static routes use a faster direct lookup for optimal performance.
- Method not allowed handling
- Zero-dependencies and under only 176 lines of code

## Install

Install with composer:

    composer require wilaak/radix-router

Requires PHP 8.1 or newer.

## Usage

```php
use Wilaak\Http\RadixRouter;

$router = new RadixRouter();

$router->addRoute(['GET'], '/users/{id}', function ($id) {
    echo "User ID: $id";
});

$router->addRoute(['POST'], '/users', function () {
    echo 'User created';
});

$router->addRoute(['GET'], '/files/{path*}', function ($path) {
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

### Cache

Rebuilding the route tree for each request or startup is going to have an impact on performance. By caching your routes you can achieve much faster startup times.

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

## A Note on HEAD Requests

The HTTP spec requires servers to support both GET and HEAD methods. Routes that support GET requests must also support HEAD requests that return an empty body.

Implementers outside the web SAPI environment (e.g. a custom server) MUST NOT send entity bodies generated in response to HEAD requests. If you are using a custom server, you are responsible for implementing this handling yourself to ensure compliance with the HTTP specification.

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.