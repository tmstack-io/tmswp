<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use TMSWP\Core\Option\OptionAccess;

final class OptionGroupGetterReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(
        private readonly MetaSchemaReflector $reflector,
    ) {}

    public function getClass(): string
    {
        return OptionAccess::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'get';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        if (!$methodCall->var instanceof ClassConstFetch || !$methodCall->var->class instanceof Name || !$methodCall->var->name instanceof Identifier) {
            return null;
        }

        $enumClass = $scope->resolveName($methodCall->var->class);

        if (!enum_exists($enumClass)) {
            return null;
        }

        $schema = $this->reflector->optionSchemaOf($enumClass, $methodCall->var->name->toString());

        if ($schema === null) {
            return null;
        }

        $type = SchemaTypeMapper::typeFor($schema['type']);

        if (\array_key_exists(0, $methodCall->getArgs())) {
            return TypeCombinator::union($type, $scope->getType($methodCall->getArgs()[0]->value));
        }

        if ($schema['hasDefault']) {
            return $type;
        }

        return TypeCombinator::addNull($type);
    }
}
