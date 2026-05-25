<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Execution\ExecutionMode;

/**
 * One entry of a flow's `on` map: a branch pattern paired with execution
 * attributes (FEAT-2).
 *
 * Today the only supported attribute is `execution` (`full | fast | fast-branch`).
 * The shape is intentionally an object — not `on => [pattern => mode]` — so
 * adding new attributes (e.g. `time-budget`, `fail-fast`) in later versions
 * does not break the surface.
 */
class FlowOnRule
{
    private string $pattern;

    private ?string $executionMode;

    public function __construct(string $pattern, ?string $executionMode = null)
    {
        $this->pattern = $pattern;
        $this->executionMode = $executionMode;
    }

    /**
     * @param mixed $rawAttrs raw value next to the pattern: should be array
     */
    public static function fromArray(
        string $pattern,
        $rawAttrs,
        ValidationResult $result,
        string $flowName
    ): ?self {
        if ($pattern === '') {
            $result->addError("Flow '$flowName' on rule: branch pattern must not be empty.");
            return null;
        }

        if (!is_array($rawAttrs)) {
            $result->addError("Flow '$flowName' on rule for '$pattern': attributes must be an object.");
            return null;
        }

        $executionMode = null;
        if (array_key_exists('execution', $rawAttrs)) {
            $value = $rawAttrs['execution'];
            if (!is_string($value) || !ExecutionMode::isValid($value)) {
                $suggestion = is_string($value) ? KeySuggestion::suggestionFor($value, ExecutionMode::ALL) : '';
                $result->addError(
                    "Flow '$flowName' on rule for '$pattern': 'execution' must be one of: "
                    . implode(', ', ExecutionMode::ALL) . "." . $suggestion
                );
                return null;
            }
            $executionMode = $value;
        }

        self::warnUnknownAttributes($rawAttrs, $pattern, $flowName, $result);

        return new self($pattern, $executionMode);
    }

    /**
     * @param array<string, mixed> $rawAttrs
     */
    private static function warnUnknownAttributes(
        array $rawAttrs,
        string $pattern,
        string $flowName,
        ValidationResult $result
    ): void {
        $knownKeys = ['execution'];
        foreach (array_keys($rawAttrs) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = KeySuggestion::suggestionFor((string) $key, $knownKeys);
                $result->addWarning(
                    "Flow '$flowName' on rule for '$pattern': unknown attribute '$key'.{$suggestion}"
                );
            }
        }
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getExecutionMode(): ?string
    {
        return $this->executionMode;
    }
}
