<?php
declare(strict_types=1);
/**
 * Parser Reflection API
 *
 * @copyright Copyright 2015, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\ParserReflection;

use Go\ParserReflection\Traits\AttributeResolverTrait;
use Go\ParserReflection\Traits\InternalPropertiesEmulationTrait;
use Go\ParserReflection\Traits\ReflectionFunctionLikeTrait;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\UnionType;
use Reflection;
use ReflectionMethod as BaseReflectionMethod;

/**
 * AST-based reflection for the method in a class
 * @see \Go\ParserReflection\ReflectionMethodTest
 */
class ReflectionMethod extends BaseReflectionMethod
{
    use InternalPropertiesEmulationTrait;
    use ReflectionFunctionLikeTrait;
    use AttributeResolverTrait;

    /**
     * Name of the class
     *
     * @var string
     */
    private $className;

    /**
     * Optional declaring class reference
     *
     * @var ReflectionClass
     */
    private $declaringClass;

    /**
     * Initializes reflection instance for the method node
     *
     * @param string           $className       Name of the class
     * @param string           $methodName      Name of the method
     * @param ?ClassMethod     $classMethodNode AST-node for method
     * @param ?ReflectionClass $declaringClass  Optional declaring class
     */
    public function __construct(
        $className,
        $methodName,
        ClassMethod $classMethodNode = null,
        ReflectionClass $declaringClass = null
    ) {
        //for some reason, ReflectionMethod->getNamespaceName in php always returns '', so we shouldn't use it too
        $this->className        = ltrim($className, '\\');
        $this->declaringClass   = $declaringClass;
        $this->functionLikeNode = $classMethodNode ?: ReflectionEngine::parseClassMethod($className, $methodName);

        // Let's unset original read-only properties to have a control over them via __get
        unset($this->name, $this->class);
    }

    /**
     * Returns an AST-node for method
     */
    public function getNode(): ClassMethod
    {
        return $this->functionLikeNode;
    }

    /**
     * Emulating original behaviour of reflection
     */
    public function __debugInfo(): array
    {
        return [
            'name'  => $this->getClassMethodNode()->name->toString(),
            'class' => $this->className
        ];
    }

    /**
     * Returns the string representation of the Reflection method object.
     *
     * @link http://php.net/manual/en/reflectionmethod.tostring.php
     */
    public function __toString(): string
    {
        // Internally $this->getReturnType() !== null is the same as $this->hasReturnType()
        $returnType       = $this->getReturnType();
        $hasReturnType    = $returnType !== null;
        $paramsNeeded     = $hasReturnType || $this->getNumberOfParameters() > 0;
        $paramFormat      = $paramsNeeded ? "\n\n  - Parameters [%d] {%s\n  }" : '';
        $returnFormat     = $hasReturnType ? "\n  - Return [ %s ]" : '';
        $methodParameters = $this->getParameters();
        try {
            $prototype = $this->getPrototype();
        } catch (\ReflectionException $e) {
            $prototype = null;
        }
        $prototypeClass = $prototype ? $prototype->getDeclaringClass()->name : '';

        $paramString = '';
        $indentation = str_repeat(' ', 4);
        foreach ($methodParameters as $methodParameter) {
            $paramString .= "\n{$indentation}" . $methodParameter;
        }

        return sprintf(
            "%sMethod [ <user%s%s>%s%s%s %s method %s ] {\n  @@ %s %d - %d{$paramFormat}{$returnFormat}\n}\n",
            $this->getDocComment() ? $this->getDocComment() . "\n" : '',
            $prototype ? ", overwrites {$prototypeClass}, prototype {$prototypeClass}" : '',
            $this->isConstructor() ? ', ctor' : '',
            $this->isFinal() ? ' final' : '',
            $this->isStatic() ? ' static' : '',
            $this->isAbstract() ? ' abstract' : '',
            implode(
                ' ',
                Reflection::getModifierNames(
                    $this->getModifiers() & (self::IS_PUBLIC | self::IS_PROTECTED | self::IS_PRIVATE)
                )
            ),
            $this->getName(),
            $this->getFileName(),
            $this->getStartLine(),
            $this->getEndLine(),
            count($methodParameters),
            $paramString,
            $returnType ? ReflectionType::convertToDisplayType($returnType) : ''
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getClosure($object = null): \Closure
    {
        $this->initializeInternalReflection();

        return parent::getClosure($object);
    }

    /**
     * {@inheritDoc}
     */
    public function getDeclaringClass(): \ReflectionClass
    {
        return $this->declaringClass ?? new ReflectionClass($this->className);
    }

    /**
     * {@inheritDoc}
     */
    public function getModifiers(): int
    {
        $modifiers = 0;
        if ($this->isPublic()) {
            $modifiers += self::IS_PUBLIC;
        }
        if ($this->isProtected()) {
            $modifiers += self::IS_PROTECTED;
        }
        if ($this->isPrivate()) {
            $modifiers += self::IS_PRIVATE;
        }
        if ($this->isAbstract()) {
            $modifiers += self::IS_ABSTRACT;
        }
        if ($this->isFinal()) {
            $modifiers += self::IS_FINAL;
        }
        if ($this->isStatic()) {
            $modifiers += self::IS_STATIC;
        }

        return $modifiers;
    }

    /**
     * {@inheritDoc}
     */
    public function getPrototype(): \ReflectionMethod
    {
        $parent = $this->getDeclaringClass()->getParentClass();
        if (!$parent) {
            throw new ReflectionException("No prototype");
        }

        $prototypeMethod = $parent->getMethod($this->getName());
        if (!$prototypeMethod) {
            throw new ReflectionException("No prototype");
        }

        return $prototypeMethod;
    }

    /**
     * {@inheritDoc}
     */
    public function invoke(?object $object, mixed ...$args): mixed
    {
        $this->initializeInternalReflection();

        return parent::invoke($object, ...$args);
    }

    /**
     * {@inheritDoc}
     */
    public function invokeArgs(?object $object, array $args): mixed
    {
        $this->initializeInternalReflection();

        return parent::invokeArgs($object, $args);
    }

    /**
     * {@inheritDoc}
     */
    public function isAbstract(): bool
    {
        return $this->getDeclaringClass()->isInterface() || $this->getClassMethodNode()->isAbstract();
    }

    /**
     * {@inheritDoc}
     */
    public function isConstructor(): bool
    {
        return $this->getClassMethodNode()->name->toLowerString() === '__construct';
    }

    /**
     * {@inheritDoc}
     */
    public function isDestructor(): bool
    {
        return $this->getClassMethodNode()->name->toLowerString() === '__destruct';
    }

    /**
     * {@inheritDoc}
     */
    public function isFinal(): bool
    {
        return $this->getClassMethodNode()->isFinal();
    }

    /**
     * {@inheritDoc}
     */
    public function isPrivate(): bool
    {
        return $this->getClassMethodNode()->isPrivate();
    }

    /**
     * {@inheritDoc}
     */
    public function isProtected(): bool
    {
        return $this->getClassMethodNode()->isProtected();
    }

    /**
     * {@inheritDoc}
     */
    public function isPublic(): bool
    {
        return $this->getClassMethodNode()->isPublic();
    }

    /**
     * {@inheritDoc}
     */
    public function isStatic(): bool
    {
        return $this->getClassMethodNode()->isStatic();
    }

    /**
     * {@inheritDoc}
     */
    public function setAccessible($accessible): void
    {
        $this->initializeInternalReflection();

        parent::setAccessible($accessible);
    }

    /**
     * Parses methods from the concrete class node
     *
     * @param ClassLike $classLikeNode Class-like node
     * @param ReflectionClass $reflectionClass Reflection of the class
     *
     * @return ReflectionMethod[]
     */
    public static function collectFromClassNode(ClassLike $classLikeNode, ReflectionClass $reflectionClass): array
    {
        $methods = [];

        foreach ($classLikeNode->stmts as $classLevelNode) {
            if ($classLevelNode instanceof ClassMethod) {
                $classLevelNode->setAttribute('fileName', $classLikeNode->getAttribute('fileName'));

                $methodName = $classLevelNode->name->toString();
                $methods[$methodName] = new ReflectionMethod(
                    $reflectionClass->name,
                    $methodName,
                    $classLevelNode,
                    $reflectionClass
                );
            }
        }

        // Enum has special `cases` (and `from`/`tryFrom` for Backed Enums) methods
        if ($classLikeNode instanceof Enum_) {
            $methods['cases'] = self::createEnumCasesMethod($reflectionClass);
            // Backed enum methods emulation
            if (isset($classLikeNode->scalarType)) {
                $methods['from']    = self::createEnumFromMethod($reflectionClass);
                $methods['tryFrom'] = self::createEnumTryFromMethod($reflectionClass);
            }
        }

        return $methods;
    }

    /**
     * Implementation of internal reflection initialization
     */
    protected function __initialize(): void
    {
        parent::__construct($this->className, $this->getName());
    }

    /**
     * Ad-Hoc constructor of Enum `cases` method, which emulates PHP behaviour
     */
    private static function createEnumCasesMethod(ReflectionClass $reflectionClass): ReflectionMethod
    {
        $casesMethodNode = (new \PhpParser\Builder\Method('cases'))
            ->makeStatic()
            ->makePublic()
            ->setReturnType('array')
            ->getNode();
        
        return new static(
            $reflectionClass->name,
            'cases',
            $casesMethodNode,
            $reflectionClass
        );
    }

    private static function createEnumFromMethod(ReflectionClass $reflectionClass): ReflectionMethod
    {
        $valueParam = (new \PhpParser\Builder\Param('value'))
            ->setType(new UnionType([new Identifier('string'), new Identifier('int')]))
            ->getNode();
        $fromMethodNode = (new \PhpParser\Builder\Method('from'))
            ->makeStatic()
            ->makePublic()
            ->addParam($valueParam)
            ->setReturnType('static')
            ->getNode();

        return new static(
            $reflectionClass->name,
            'from',
            $fromMethodNode,
            $reflectionClass
        );
    }

    private static function createEnumTryFromMethod(ReflectionClass $reflectionClass): ReflectionMethod
    {
        $valueParam = (new \PhpParser\Builder\Param('value'))
            ->setType(new UnionType([new Identifier('string'), new Identifier('int')]))
            ->getNode();
        $fromMethodNode = (new \PhpParser\Builder\Method('tryFrom'))
            ->makeStatic()
            ->makePublic()
            ->addParam($valueParam)
            ->setReturnType('?static')
            ->getNode();

        return new static(
            $reflectionClass->name,
            'tryFrom',
            $fromMethodNode,
            $reflectionClass
        );
    }
    
    /**
     * Returns ClassMethod node to prevent all possible type checks with instanceof
     */
    private function getClassMethodNode(): ClassMethod
    {
        return $this->functionLikeNode;
    }
}
