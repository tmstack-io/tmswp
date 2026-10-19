<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan\Actions;

use JsonSerializable;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
final class NoOutputRule implements Rule
{
    /** @var list<string> */
    private const OUTPUT_FUNCTIONS = [
        'debug_zval_dump',
        'esc_attr_e',
        'esc_html_e',
        'flush',
        'ob_flush',
        'passthru',
        'printf',
        'readfile',
        'the_content',
        'the_title',
        'var_dump',
        'vprintf',
        'wp_die',
    ];

    /** @var array<string, array<int, true>> */
    private array $allowedRedirectExitLinesByFile = [];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isActionFile($scope->getFile())) {
            return [];
        }

        if ($node instanceof Echo_ || $node instanceof Print_) {
            return [$this->error('actions file must not output with echo or print.')];
        }

        if ($node instanceof InlineHTML) {
            return $this->isWhitespaceInlineHtml($node) ? [] : [$this->error('actions file must not contain inline HTML.')];
        }

        if ($node instanceof Exit_) {
            return $this->isAllowedRedirectExit($node, $scope->getFile()) ? [] : [$this->error('actions file must not exit except immediately after wp_safe_redirect().')];
        }

        if ($node instanceof FuncCall && $this->isOutputFunctionCall($node)) {
            return [$this->error('actions file must not call output functions.')];
        }

        return [];
    }

    private function isActionFile(string $file): bool
    {
        return preg_match('~(?:^|/)actions/.+\.php\z~', str_replace('\\', '/', $file)) === 1;
    }

    private function isWhitespaceInlineHtml(InlineHTML $node): bool
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $node->value);

        return trim($value ?? '') === '';
    }

    private function isAllowedRedirectExit(Exit_ $node, string $file): bool
    {
        return \array_key_exists($node->getStartLine(), $this->allowedRedirectExitLines($file));
    }

    /** @return array<int, true> */
    private function allowedRedirectExitLines(string $file): array
    {
        if (\array_key_exists($file, $this->allowedRedirectExitLinesByFile)) {
            return $this->allowedRedirectExitLinesByFile[$file];
        }

        $code = file_get_contents($file);

        if (!\is_string($code)) {
            $this->allowedRedirectExitLinesByFile[$file] = [];

            return [];
        }

        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($code);
        } catch (Error) {
            $this->allowedRedirectExitLinesByFile[$file] = [];

            return [];
        }

        $allowed = [];

        if ($statements !== null) {
            $this->collectAllowedRedirectExitLines(array_values($statements), $allowed);
        }

        $this->allowedRedirectExitLinesByFile[$file] = $allowed;

        return $allowed;
    }

    /**
     * @param list<Stmt> $statements
     * @param array<int, true> $allowed
     */
    private function collectAllowedRedirectExitLines(array $statements, array &$allowed): void
    {
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Expression && $statement->expr instanceof Exit_) {
                $previous = $statements[$index - 1] ?? null;

                if ($previous instanceof Expression && $previous->expr instanceof FuncCall && $this->functionName($previous->expr) === 'wp_safe_redirect') {
                    $allowed[$statement->getStartLine()] = true;
                }
            }

            $this->collectNestedStatementLists($statement, $allowed);
        }
    }

    /** @param array<int, true> $allowed */
    private function collectNestedStatementLists(Node $node, array &$allowed): void
    {
        if (!$node instanceof JsonSerializable) {
            return;
        }

        $subNodes = $node->jsonSerialize();

        if (!\is_array($subNodes)) {
            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $subNodes[$name] ?? null;

            if ($value instanceof Node) {
                $this->collectNestedStatementLists($value, $allowed);
                continue;
            }

            if (!\is_array($value)) {
                continue;
            }

            $statements = [];

            foreach ($value as $item) {
                if ($item instanceof Stmt) {
                    $statements[] = $item;
                    continue;
                }

                if ($item instanceof Node) {
                    $this->collectNestedStatementLists($item, $allowed);
                }
            }

            if ($statements !== []) {
                $this->collectAllowedRedirectExitLines($statements, $allowed);
            }
        }
    }

    private function isOutputFunctionCall(FuncCall $node): bool
    {
        $name = $this->functionName($node);

        if ($name === null) {
            return false;
        }

        if ($name === 'print_r' || $name === 'var_export') {
            return !$this->hasLiteralTrueReturnFlag($node);
        }

        return \in_array($name, self::OUTPUT_FUNCTIONS, true);
    }

    private function functionName(FuncCall $node): ?string
    {
        if (!$node->name instanceof Name) {
            return null;
        }

        return strtolower($node->name->toString());
    }

    private function hasLiteralTrueReturnFlag(FuncCall $node): bool
    {
        if (!\array_key_exists(1, $node->getArgs())) {
            return false;
        }

        $returnArg = $node->getArgs()[1]->value;

        return $returnArg instanceof ConstFetch && strtolower($returnArg->name->toString()) === 'true';
    }

    private function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('tmswp.actions.output')
            ->build();
    }
}
