<?php

namespace Wilaak\Http;

use InvalidArgumentException;

/**
 * Simple implementation of a radix tree based router.
 */
class RadixRouter
{
    private int  $order = 0;
    public array $tree = [];

    public const DISPATCH_FOUND       = 0;
    public const DISPATCH_NOT_FOUND   = 1;
    public const DISPATCH_NOT_ALLOWED = 2;

    private const NODE_PARAMETER = '/_N_/';
    private const NODE_WILDCARD  = '/_W_/';
    private const NODE_ROUTES    = '/_R_/';

    /**
     * Adds a route to the route tree.
     */
    public function addRoute(array $methods, string $pattern, mixed $handler): void
    {
        $methods = array_map(strtoupper(...), $methods);

        $segments = array_filter(
            explode('/', $pattern),
            fn($segment) => $segment !== ''
        );

        foreach ($segments as $i => $segment) {
            $isOptional = str_starts_with($segment, '{') && str_ends_with($segment, '?}');
            $isWildcard = str_starts_with($segment, '{') && str_ends_with($segment, '*}');
            if (($isOptional || $isWildcard) && $i !== array_key_last($segments)) {
                throw new InvalidArgumentException(
                    "Optional or wildcard parameters must be the last segment in the pattern: $pattern"
                );
            }
        }

        $segments = array_map(function ($segment) {
            if (!str_starts_with($segment, '{')) {
                return $segment;
            }
            if (str_ends_with($segment, '*}')) {
                return self::NODE_WILDCARD;
            }
            if (str_ends_with($segment, '}')) {
                return self::NODE_PARAMETER;
            }
        }, $segments);

        $currentNode = &$this->tree;
        foreach ($segments as $node) {
            $currentNode[$node] ??= [];
            $currentNode = &$currentNode[$node];
        }

        if (isset($currentNode[self::NODE_ROUTES])) {
            foreach ($currentNode[self::NODE_ROUTES] as $route) {
                foreach ($methods as $method) {
                    if (in_array($method, $route['methods'], true) && $route['pattern'] === $pattern) {
                        throw new InvalidArgumentException(
                            "Duplicate route detected for method '$method' and pattern '$pattern'"
                        );
                    }
                }
            }
        }

        $currentNode[self::NODE_ROUTES] ??= [];
        $currentNode[self::NODE_ROUTES][$this->order] = [
            'methods'    => $methods,
            'pattern'    => $pattern,
            'handler'    => $handler,
        ];

        $this->order++;
    }

    /**
     * Dispatches the request against the provided HTTP method verb and URI.
     *
     * @param string $requestMethod The HTTP method (e.g., 'GET', 'POST').
     * @param string $requestPath   The URI path to match (e.g., '/users/123').
     * @return array{
     *   status: int,
     *   handler?: mixed,
     *   params?: array<string, string>,
     *   methods?: array<int, string>
     * }
     */
    public function dispatch(string $requestMethod, string $requestPath): array
    {
        $requestParts = explode('/', $requestPath);
        $routes = $this->findMatchingRoutes($requestPath);
        $allowedMethods = [];

        foreach ($routes as $route) {
            $routeMethods = $route['methods'];
            $patternParts = explode('/', $route['pattern']);
            $lastPatternPart = end($patternParts);
            $isWildcard = str_starts_with($lastPatternPart, '{') && str_ends_with($lastPatternPart, '*}');

            if (
                (!$isWildcard && count($requestParts) !== count($patternParts)) ||
                ($isWildcard && count($requestParts) < count($patternParts))
            ) {
                continue;
            }

            foreach ($patternParts as $index => $part) {
                if ($part !== $requestParts[$index] && !str_starts_with($part, '{')) {
                    continue 2;
                }
            }

            $params = [];
            foreach ($patternParts as $index => $part) {
                if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                    $paramName = trim($part, '{}?*');
                    $params[$paramName] = $requestParts[$index];
                }
            }

            if (!empty($params)) {
                $isOptional = str_starts_with($lastPatternPart, '{') && str_ends_with($lastPatternPart, '?}');
                $lastParamKey = array_key_last($params);
                if ($isOptional && empty($params[$lastParamKey])) {
                    $params[$lastParamKey] = null;
                }
                if (!$isOptional && !$isWildcard && empty($params[$lastParamKey])) {
                    continue;
                }
                if ($isWildcard) {
                    $params[$lastParamKey] = implode('/', array_slice($requestParts, count($patternParts) - 1));
                }
                if ($isWildcard && empty($params[$lastParamKey])) {
                    $params[$lastParamKey] = null;
                }
            }

            $allowedMethods = array_unique(array_merge($allowedMethods, $routeMethods));
            if (!in_array($requestMethod, $routeMethods)) {
                continue;
            }

            return [
                'status'  => self::DISPATCH_FOUND,
                'handler' => $route['handler'],
                'params'  => $params
            ];
        }

        if (empty($allowedMethods)) {
            return [
                'status' => self::DISPATCH_NOT_FOUND,
            ];
        } else {
            return [
                'status' => self::DISPATCH_NOT_ALLOWED,
                'methods' => $allowedMethods
            ];
        }
    }

    private function findMatchingRoutes(string $path): array
    {
        $segments = array_filter(
            explode('/', $path),
            fn($node) => $node !== ''
        );

        $foundRoutes = [];
        $resolve = function ($tree, $segments) use (&$resolve, &$foundRoutes) {
            if (empty($segments) && isset($tree[self::NODE_ROUTES])) {
                $foundRoutes += $tree[self::NODE_ROUTES];
            }
            $segment = array_shift($segments);
            if ($segment !== null && isset($tree[$segment])) {
                $resolve($tree[$segment], $segments);
            }
            if (isset($tree[self::NODE_PARAMETER])) {
                $resolve($tree[self::NODE_PARAMETER], $segments);
            }
            if (isset($tree[self::NODE_WILDCARD])) {
                $resolve($tree[self::NODE_WILDCARD], []);
            }
        };

        $resolve($this->tree, $segments);
        ksort($foundRoutes);
        return $foundRoutes;
    }
}