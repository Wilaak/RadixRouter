# RadixRouter

Simple implementation of a radix tree based router. Ideal as a starting point for building a more featureful routing system.

### Overview

- Fast route matching using a radix tree structure
- Supports required, optional, and wildcard parameters
- Allows handling of 404 and 405 HTTP responses

## Install

Install with composer:

    composer require wilaak/radix-router

Requires PHP 8.1 or newer.

## Usage

Here's a basic usage example:

```PHP
<?php require __DIR__ . '/../vendor/autoload.php';

use Wilaak\Http\RadixRouter;

$router = new RadixRouter();

$router->addRoute(['GET'], '/{world*}', function ($world = 'World') {
    echo "Hello, $world!";
});

// Fetch method and URI from somewhere
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = rawurldecode(strtok($_SERVER['REQUEST_URI'], '?'));

$info = $router->dispatch(
    $requestMethod,
    $requestUri
);

switch ($info['status']) {
    case RadixRouter::DISPATCH_FOUND:
        $handler = $info['handler'];
        $params = $info['params'];
        // ... call the handler with the parameters
        call_user_func_array($handler, $params);
        break;

    case RadixRouter::DISPATCH_NOT_FOUND:
        // ... 404 Not Found
        http_response_code(404);
        echo '404 Not Found';
        break;

    case RadixRouter::DISPATCH_NOT_ALLOWED:
        $allowedMethods = $info['methods'];
        // ... 405 Method Not Allowed
        header('Allow: ' . implode(', ', $allowedMethods));
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}
```

### Defining Routes

You can define routes with required, optional, or wildcard parameters. Wrap your parameters in curly braces (`{}`), each parameter should be its own path segment, inline parameters like `/user-{id}` aren’t supported. Optional and wildcard parameters can only be used as the last segment:

```php
// Required parameter
$router->addRoute(['GET'], '/user/{id}', 'handler');
// Matches: /user/123
// Does NOT match: /user/

// Multiple required parameters
$router->addRoute(['GET', 'POST'], '/user/{id}/{action}', 'handler');
// Matches: /user/123/edit

// Optional parameter (last only)
$router->addRoute(['GET'], '/hello/{name?}', 'handler');
// Matches: /hello/
// Matches: /hello/Alice

// Wildcard parameter (last only)
$router->addRoute(['GET'], '/files/{path*}', 'handler');
// Matches: /files/
// Matches: /files/image.jpg
// Matches: /files/docs/report.pdf
```

The handler can be anything you want. This router simply tells you which handler matches your URI; how you interpret or invoke the handler is entirely up to your application.

### Cache

When using Classic PHP modes, rebuilding the route tree for each request is going to slow down performance. To solve this we can cache the generated route tree.

> **Note:**  
> Anonymous functions (closures) are **not supported** for route caching because they cannot be serialized.

> **Note:**  
> When implementing route caching, care should be taken to avoid race conditions when rebuilding the cache file. Ensure that the cache is written atomically so that each request can always fully load a valid cache file without errors or partial data.

Here is a simple cache implementation:

```php
if (!file_exists('routecache.php')) {
    // Build routes
    $router->addRoute(['GET'], '/', 'homepageHandler');
    // Export generated route tree
    file_put_contents('routecache.php', '<?php return ' . var_export($router->tree, true) . ';');
} else {
    // Load routes from cache
    $router->tree = require 'routecache.php';
}
```

By storing your routes in a PHP file, you take advantage of PHP’s OPcache, which compiles and stores the route definitions in memory. Making startup times nearly instantaneous.