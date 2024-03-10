<?php

declare(strict_types=1);
/**
 * Parser Reflection API
 *
 * @copyright Copyright 2024, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\ParserReflection\Resolver;

use Go\ParserReflection\ReflectionClass;
use Go\ParserReflection\ReflectionException;
use Go\ParserReflection\ReflectionFileNamespace;
use Go\ParserReflection\ReflectionNamedType;
use Go\ParserReflection\ReflectionUnionType;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\MagicConst\Line;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter\Standard;

/**
 * Tries to resolve expression into value
 */
class TypeExpressionResolver
{

    /**
     * Current reflection context for parsing
     */
    private
        \ReflectionClass|\ReflectionFunction|\ReflectionMethod|\ReflectionClassConstant|
        \ReflectionParameter|\ReflectionAttribute|\ReflectionProperty|ReflectionFileNamespace|null $context;

    /**
     * Node resolving level, 1 = top-level
     */
    private int $nodeLevel = 0;

    /**
     * @var Node[]
     */
    private array $nodeStack = [];

    private \ReflectionNamedType|\ReflectionUnionType|\ReflectionIntersectionType|null $type;

    public function __construct($context)
    {
        $this->context = $context;
    }

    /**
     * @throws ReflectionException If node could not be resolved
     */
    final public function process(Node $node): void
    {
        $this->nodeLevel    = 0;
        $this->nodeStack    = [$node]; // Always keep the root node
        $this->type         = $this->resolve($node);
    }

    final public function getType(): \ReflectionNamedType|\ReflectionUnionType|\ReflectionIntersectionType|null
    {
        return $this->type;
    }

    /**
     * Recursively resolves node into valid type
     *
     * @throws ReflectionException If couldn't resolve value for given Node
     */
    final protected function resolve(Node $node): mixed
    {
        $type = null;
        try {
            $this->nodeStack[] = $node;
            ++$this->nodeLevel;

            $methodName = $this->getDispatchMethodFor($node);
            if (!method_exists($this, $methodName)) {
                throw new ReflectionException("Could not find handler for the " . __CLASS__ . "::{$methodName} method");
            }
            $type = $this->$methodName($node);
        } finally {
            array_pop($this->nodeStack);
            --$this->nodeLevel;
        }

        return $type;
    }

    private function resolveUnionType(Node\UnionType $unionType): ReflectionUnionType
    {
        $resolvedTypes = array_map(
            fn(Identifier|IntersectionType|Name $singleType) => $this->resolve($singleType),
            $unionType->types
        );

        return new ReflectionUnionType(...$resolvedTypes);
    }

    private function resolveNullableType(Node\NullableType $node): ReflectionNamedType
    {
        $type = $this->resolve($node->type);

        return new ReflectionNamedType((string) $type, true, false);
    }

    private function resolveIdentifier(Node\Identifier $node): ReflectionNamedType
    {
        $typeString = $node->toString();
        $allowsNull = $typeString === 'null';

        return new ReflectionNamedType($typeString, $allowsNull, true);
    }

    private function resolveName(Name $node): ReflectionNamedType
    {
        return new ReflectionNamedType($node->toString(), false, false);
    }

    private function resolveNameFullyQualified(Name\FullyQualified $node): ReflectionNamedType
    {
        return new ReflectionNamedType((string) $node, false, false);
    }

    private function getDispatchMethodFor(Node $node): string
    {
        $nodeType = $node->getType();

        return 'resolve' . str_replace('_', '', $nodeType);
    }

    /**
     * Utility method to fetch reflection class instance by name
     *
     * Supports:
     *   'self' keyword
     *   'parent' keyword
     *    not-FQN class names
     *
     * @param Node\Name $node Class name node
     *
     * @return bool|\ReflectionClass
     *
     * @throws ReflectionException
     */
    private function fetchReflectionClass(Node\Name $node)
    {
        $className  = $node->toString();
        $isFQNClass = $node instanceof Node\Name\FullyQualified;
        if ($isFQNClass) {
            // check to see if the class is already loaded and is safe to use
            // PHP's ReflectionClass to determine if the class is user defined
            if (class_exists($className, false)) {
                $refClass = new \ReflectionClass($className);
                if (!$refClass->isUserDefined()) {
                    return $refClass;
                }
            }

            return new ReflectionClass($className);
        }

        if ('self' === $className) {
            if ($this->context instanceof \ReflectionClass) {
                return $this->context;
            }

            if (method_exists($this->context, 'getDeclaringClass')) {
                return $this->context->getDeclaringClass();
            }
        }

        if ('parent' === $className) {
            if ($this->context instanceof \ReflectionClass) {
                return $this->context->getParentClass();
            }

            if (method_exists($this->context, 'getDeclaringClass')) {
                return $this->context->getDeclaringClass()
                                     ->getParentClass()
                    ;
            }
        }

        if (method_exists($this->context, 'getFileName')) {
            /** @var ReflectionFileNamespace|null $fileNamespace */
            $fileName      = $this->context->getFileName();
            $namespaceName = $this->resolveScalarMagicConstNamespace();

            $fileNamespace = new ReflectionFileNamespace($fileName, $namespaceName);

            return $fileNamespace->getClass($className);
        }

        throw new ReflectionException("Can not resolve class $className");
    }
}
