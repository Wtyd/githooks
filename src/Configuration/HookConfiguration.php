<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Hooks;

class HookConfiguration
{
    /** @var array<string, string[]> event => [flow/job names] */
    private array $hooks;

    /**
     * @param array<string, string[]> $hooks
     */
    public function __construct(array $hooks)
    {
        $this->hooks = $hooks;
    }

    /**
     * Build from raw config 'hooks' section.
     *
     * @param array $raw The 'hooks' section
     * @param string[] $availableFlowNames
     * @param string[] $availableJobNames
     */
    public static function fromArray(
        array $raw,
        array $availableFlowNames,
        array $availableJobNames,
        ValidationResult $result
    ): self {
        $hooks = [];

        foreach ($raw as $event => $refs) {
            if (!Hooks::validate($event)) {
                $result->addError("'$event' is not a valid git hook event.");
                continue;
            }

            if (!is_array($refs) || empty($refs)) {
                $result->addError("Hook '$event' must reference a non-empty array of flows or jobs.");
                continue;
            }

            foreach ($refs as $ref) {
                if (
                    !in_array($ref, $availableFlowNames, true)
                    && !in_array($ref, $availableJobNames, true)
                ) {
                    $result->addError("Hook '$event' references '$ref' which is not a defined flow or job.");
                }
            }

            $hooks[$event] = $refs;
        }

        return new self($hooks);
    }

    /**
     * Get the ordered list of flow/job names to execute for a hook event.
     *
     * @return string[]
     */
    public function resolve(string $event): array
    {
        return $this->hooks[$event] ?? [];
    }

    /** @return string[] */
    public function getEvents(): array
    {
        return array_keys($this->hooks);
    }

    /** @return array<string, string[]> */
    public function getAll(): array
    {
        return $this->hooks;
    }
}
