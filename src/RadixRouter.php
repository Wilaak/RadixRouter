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

    private const NODE_PARAMETER = '_PARAM_';
    private const NODE_WILDCARD  = '_WILDCARD_';
    private const NODE_ROUTES    = '_ROUTES_';

    /**
     * Adds a new route to the router for the specified HTTP methods, pattern, and handler.
     *
     * @param array $methods  The HTTP methods (e.g., ['GET', 'POST']) this route should match.
     * @param string $pattern The URI pattern for the route (e.g., '/users/{id}').
     * @param mixed $handler  The handler associated with the route.
     * 
     * @return void
     */
    public function addRoute(array $methods, string $pattern, mixed $handler): void
    {
        $methods = array_map(strtoupper(...), $methods);
        $segments = explode('/', $pattern);

        $hasParameter = false;
        foreach ($segments as $index => $node) {
            if (str_starts_with($node, '{') && str_ends_with($node, '*}')) {
                if ($index !== count($segments) - 1) {
                    throw new InvalidArgumentException("Wildcard parameter can only be in the last segment: $pattern");
                }
                $node = self::NODE_WILDCARD;
                $hasParameter = true;
            } elseif (str_starts_with($node, '{') && str_ends_with($node, '}')) {
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
     *   params?: array<int, string>,
     *   methods?: array<int, string> // Only present if the method is not allowed
     * }
     */
    public function dispatch(string $requestMethod, string $requestPath): array
    {
        $routes = $this->findRoutes($requestPath);

        if ($routes === null) {
            return [
                'status' => self::DISPATCH_NOT_FOUND,
            ];
        }

        if (isset($routes['routes'][$requestMethod])) {
            return [
                'status'  => self::DISPATCH_FOUND,
                'handler' => $routes['routes'][$requestMethod]['handler'],
                'params'  => $routes['params'] ?? [],
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
            return [
                'routes' => $this->routes['static'][$path],
            ];
        }

        $segments = explode('/', $path);
        $segmentsCount = count($segments);

        $treeStack = [];
        $idxStack = [];
        $paramsStack = [];
        $sp = 0;

        $treeStack[$sp] = $this->routes['tree'];
        $idxStack[$sp] = 0;
        $paramsStack[$sp++] = [];

        while ($sp > 0) {
            $tree = $treeStack[--$sp];
            $idx = $idxStack[$sp];
            $params = $paramsStack[$sp];

            if ($idx === $segmentsCount) {
                if (isset($tree[self::NODE_ROUTES])) {
                    return [
                        'routes' => $tree[self::NODE_ROUTES],
                        'params' => $params,
                    ];
                }
                continue;
            }

            $segment = $segments[$idx];

            if (isset($tree[self::NODE_WILDCARD])) {
                $wildParams = $params;
                $wildParams[] = implode('/', array_slice($segments, $idx));
                $treeStack[$sp] = $tree[self::NODE_WILDCARD];
                $idxStack[$sp] = $segmentsCount;
                $paramsStack[$sp++] = $wildParams;
            }
            if (isset($tree[self::NODE_PARAMETER]) && $segment !== '') {
                $paramParams = $params;
                $paramParams[] = $segment;
                $treeStack[$sp] = $tree[self::NODE_PARAMETER];
                $idxStack[$sp] = $idx + 1;
                $paramsStack[$sp++] = $paramParams;
            }
            if (isset($tree[$segment])) {
                $treeStack[$sp] = $tree[$segment];
                $idxStack[$sp] = $idx + 1;
                $paramsStack[$sp++] = $params;
            }
        }
        return null;
    }
}
