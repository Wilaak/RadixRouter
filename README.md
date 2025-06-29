# RadixRouter

Simple implementation of a radix tree based router. Minimal by design, handling only route matching and basic parameter extraction. Features like middleware, route groups, validation etc are not included. It is intended as a foundation for building more featureful routers or integrating into larger frameworks.

### Features

- Fast route matching using a radix tree structure
- Supports required, optional, and wildcard parameters
- Handles 404 (Not Found) and 405 (Method Not Allowed) HTTP responses
- Single file, dependency free and under 200 lines of code

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

$router->addRoute(['GET'], '/{world?}', function ($world = 'World') {
    echo "Hello, $world!";
});

$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = rawurldecode(strtok($_SERVER['REQUEST_URI'], '?'));

$info = $router->dispatch(
    $requestMethod,
    $requestUri
);

match ($info['status']) {
    RadixRouter::DISPATCH_FOUND => (function () use ($info) {
        $handler = $info['handler'];
        $params = $info['params'];
        $params = array_values(array_filter($params, function ($value) {
            return !is_null($value);
        }));
        call_user_func_array($handler, $params);
    })(),

    RadixRouter::DISPATCH_NOT_FOUND => (function () {
        http_response_code(404);
        echo '404 Not Found';
    })(),

    RadixRouter::DISPATCH_NOT_ALLOWED => (function () use ($info) {
        $allowedMethods = $info['methods'];
        header('Allow: ' . implode(', ', $allowedMethods));
        http_response_code(405);
        echo '405 Method Not Allowed';
    })(),
};
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

When using Classic PHP modes (e.g not using `ReactPHP` or `AMPHP` etc), rebuilding the route tree for each request is going to slow down performance. To solve this we can cache the generated route tree.

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

// ... dispatch
```

By storing your routes in a PHP file, you let PHP’s OPcache handle the heavy lifting, making startup times nearly instantaneous.

## A Note on HEAD Requests

The HTTP spec requires servers to support both GET and HEAD methods. Routes that support GET requests must also support HEAD requests that return an empty body.

Implementers outside the web SAPI environment (e.g. a custom server) MUST NOT send entity bodies generated in response to HEAD requests. If you are using a custom server, you are responsible for implementing this handling yourself to ensure compliance with the HTTP specification.

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.