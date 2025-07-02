<?php

namespace Wilaak\Http;

use InvalidArgumentException;

class RadixRouter
{
    public array $routes = [
        'static' => [],
        'tree' => []
    ];

    public const DISPATCH_FOUND       = 'found';
    public const DISPATCH_NOT_FOUND   = 'not_found';
    public const DISPATCH_NOT_ALLOWED = 'not_allowed';

    private const NODE_PARAMETER = '/p';
    private const NODE_WILDCARD  = '/w';
    private const NODE_ROUTES    = '/r';

    /**
     * Adds a new route to the router for the specified HTTP methods, pattern, and handler.
     *
     * @param array $methods  The HTTP methods (e.g., ['GET', 'POST']) this route should match.
     * @param string $pattern The URI pattern for the route (e.g., '/users/:id', '/files/:path*').
     * @param mixed $handler  The handler associated with the route.
     * 
     * @return void
     */
    public function addRoute(array $methods, string $pattern, mixed $handler): void
    {
        $methods = array_map('strtoupper', $methods);
        $segments = explode('/', $pattern);

        $hasParameter = false;
        $paramNames = [];
        foreach ($segments as $index => $node) {
            if (
                str_starts_with($node, ':') &&
                str_ends_with($node, '*')
            ) {
                if ($index !== count($segments) - 1) {
                    throw new InvalidArgumentException("Wildcard parameter must be the last segment: $pattern");
                }
                $paramName = substr($node, 1, -1);
                if ($paramName === '') {
                    throw new InvalidArgumentException("Wildcard parameter must have a name: $pattern");
                }
                $paramNames[] = $paramName;
                $node = self::NODE_WILDCARD;
                $hasParameter = true;
            } elseif (str_starts_with($node, ':')) {
                $paramName = substr($node, 1);
                if ($paramName === '') {
                    throw new InvalidArgumentException("Parameter must have a name: $pattern");
                }
                $paramNames[] = $paramName;
                $node = self::NODE_PARAMETER;
                $hasParameter = true;
            }
            $segments[$index] = $node;
        }

        if (!$hasParameter) {
            foreach ($methods as $method) {
                if (isset($this->routes['static'][$pattern][$method])) {
                    throw new InvalidArgumentException("Duplicate route for [$method] $pattern");
                }
                $this->routes['static'][$pattern][$method] = [
                    'handler' => $handler,
                ];
            }
            return;
        }

        $currentNode = &$this->routes['tree'];
        foreach ($segments as $node) {
            $currentNode[$node] ??= [];
            $currentNode = &$currentNode[$node];
        }

        $currentNode[self::NODE_ROUTES] ??= [];
        foreach ($methods as $method) {
            if (isset($currentNode[self::NODE_ROUTES][$method])) {
                throw new InvalidArgumentException("Duplicate route for [$method] $pattern");
            }
            $currentNode[self::NODE_ROUTES][$method] = [
                'handler' => $handler,
                'paramNames' => $paramNames,
            ];
        }
    }

    /**
     * Dispatches the request against the provided HTTP method verb and URI.
     *
     * @param string $requestMethod The HTTP method (e.g., 'GET', 'POST').
     * @param string $requestPath   The URI path to match (e.g., '/users/123').
     * @return array{
     *   status: self::DISPATCH_FOUND|self::DISPATCH_NOT_FOUND|self::DISPATCH_NOT_ALLOWED,
     *   handler?: mixed,
     *   params?: array<string, string>,
     *   methods?: array<int, string>
     * }
     */
    public function dispatch(string $requestMethod, string $requestPath): array
    {
        $routes = $this->findRoutes($requestPath);

        if ($routes === null) {
            return ['status' => self::DISPATCH_NOT_FOUND];
        }

        if (isset($routes['routes'][$requestMethod])) {
            $route = $routes['routes'][$requestMethod];
            $params = $routes['params'] ?? [];
            if (!empty($params)) {
                $params = array_combine($route['paramNames'], $params);
            }
            return [
                'status'  => self::DISPATCH_FOUND,
                'handler' => $route['handler'],
                'params'  => $params,
            ];
        }

        return [
            'status'  => self::DISPATCH_NOT_ALLOWED,
            'methods' => array_keys($routes['routes']),
        ];
    }

    private function findRoutes(string $path): ?array
    {
        if (isset($this->routes['static'][$path])) {
            return ['routes' => $this->routes['static'][$path]];
        }

        $segments = explode('/', $path);
        $segmentsCount = count($segments);
        $currentSegment = 0;
        $params = [];
        $tree = &$this->routes['tree'];

        // Track the deepest encountered wildcard node for fallback matching
        $wildcardNode = null;
        $wildcardIdx = -1;
        $wildcardParams = null;

        while (true) {
            if ($currentSegment === $segmentsCount) {
                if (isset($tree[self::NODE_ROUTES])) {
                    return [
                        'routes' => $tree[self::NODE_ROUTES],
                        'params' => $params,
                    ];
                }
                if ($wildcardNode !== null && $wildcardIdx >= 0 && isset($wildcardNode[self::NODE_ROUTES])) {
                    $wildParams = $wildcardParams;
                    $wildParams[] = implode('/', array_slice($segments, $wildcardIdx));
                    return [
                        'routes' => $wildcardNode[self::NODE_ROUTES],
                        'params' => $wildParams,
                    ];
                }
                return null;
            }
            $segment = $segments[$currentSegment];
            if (isset($tree[$segment])) {
                if (isset($tree[self::NODE_WILDCARD])) {
                    $wildcardNode = $tree[self::NODE_WILDCARD];
                    $wildcardIdx = $currentSegment;
                    $wildcardParams = $params;
                }
                $tree = &$tree[$segment];
                $currentSegment++;
                continue;
            }
            if (isset($tree[self::NODE_PARAMETER]) && $segment !== '') {
                if (isset($tree[self::NODE_WILDCARD])) {
                    $wildcardNode = $tree[self::NODE_WILDCARD];
                    $wildcardIdx = $currentSegment;
                    $wildcardParams = $params;
                }
                $params[] = $segment;
                $tree = &$tree[self::NODE_PARAMETER];
                $currentSegment++;
                continue;
            }
            if (isset($tree[self::NODE_WILDCARD])) {
                $wildParams = $params;
                $wildParams[] = implode('/', array_slice($segments, $currentSegment));
                $tree = &$tree[self::NODE_WILDCARD];
                $currentSegment = $segmentsCount;
                if (isset($tree[self::NODE_ROUTES])) {
                    return [
                        'routes' => $tree[self::NODE_ROUTES],
                        'params' => $wildParams,
                    ];
                }
                return null;
            }
            if ($wildcardNode !== null && $wildcardIdx >= 0 && isset($wildcardNode[self::NODE_ROUTES])) {
                $wildParams = $wildcardParams;
                $wildParams[] = implode('/', array_slice($segments, $wildcardIdx));
                return [
                    'routes' => $wildcardNode[self::NODE_ROUTES],
                    'params' => $wildParams,
                ];
            }
            return null;
        }
    }
}
