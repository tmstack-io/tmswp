<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan\Actions;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<MethodCall>
 */
final class NoSet404Rule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isActionFile($scope->getFile())) {
            return [];
        }

        if (!$node->name instanceof Identifier || $node->name->toString() !== 'set_404') {
            return [];
        }

        if (!$node->var instanceof Variable || $node->var->name !== 'wp_query') {
            return [];
        }

        return [
            RuleErrorBuilder::message('actions file must use ActionRouter::notFound() instead of $wp_query->set_404().')
                ->identifier('tmswp.actions.set404')
                ->build(),
        ];
    }

    private function isActionFile(string $file): bool
    {
        return preg_match('~(?:^|/)actions/.+\.php\z~', str_replace('\\', '/', $file)) === 1;
    }
}
