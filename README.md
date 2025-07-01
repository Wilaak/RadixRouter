# RadixRouter

Simple implementation of a radix tree based router. Minimal feature set, serving as a foundation for building more advanced and feature-rich routers.

### Overview

- Supports parameterized routes (e.g., `/user/:id`) and wildcard trailing routes (e.g., `/files/:path*`)
- Fast direct lookup for static routes ensures optimal performance
- Provides handling for "Method Not Allowed" responses
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

$router->addRoute(['GET'], '/:world?', function ($world = 'World') {
    echo "Hello, $world!";
});

$info = $router->dispatch('GET', '/');

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

Define routes using the `addRoute()` method.

```php
// Static: matches only "/about"
$router->addRoute(['GET'], '/about', $aboutHandler);

// Parameter: matches "/user/123", "/user/abc", but NOT "/user/"
$router->addRoute(['GET'], '/user/:id', $userHandler);

// Wildcard: matches "/files/doc.txt", "/files/images/photo.jpg", "/files/" (captures empty path)
$router->addRoute(['GET'], '/files/:path*', $filesHandler);

// Multiple parameters: matches "/posts/42/comments/7"
$router->addRoute(['GET'], '/posts/:postId/comments/:commentId', $commentHandler);

// Mixed parameters and wildcard: matches "/archive/2024/06/notes", "/archive/2024/06/"
$router->addRoute(['GET'], '/archive/:year/:month/:rest*', $archiveHandler);

// Multiple methods: matches both GET and POST requests to "/login"
$router->addRoute(['GET', 'POST'], '/login', $loginHandler);
```

Route matching follows an order for predictability and performance:

- **Static routes** (exact paths) are matched first.
- **Parameterized routes** (e.g., `:id`) are checked next.
- **Wildcard routes** (e.g., `:path*`) are matched last.

### How to Cache Routes

Rebuilding the route tree on every request or application startup can slow down performance.

> **Note:**  
> Anonymous functions (closures) are **not supported** for route caching because they cannot be serialized.

> **Note:**  
> When implementing route caching, care should be taken to avoid race conditions when rebuilding the cache file. Ensure that the cache is written atomically so that each request can always fully load a valid cache file without errors or partial data.

Here is a simple cache implementation:

```php
$cacheFile = __DIR__ . '/routes.cache.php';
if (!file_exists($cacheFile)) {
    // Build routes here
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

### Note on HEAD Requests

According to the HTTP specification, any route that handles a GET request should also support the HEAD method for the same path. RadixRouter does not add this behavior automatically. If you're running outside a typical web server environment (like in a custom server), make sure your GET routes also respond to HEAD requests—just remember that HEAD responses should not include a message body.

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.