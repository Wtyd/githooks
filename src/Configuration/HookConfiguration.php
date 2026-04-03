<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Hooks;

class HookConfiguration
{
    private const DEFAULT_COMMAND = 'php vendor/bin/githooks';

    /** @var array<string, HookRef[]> event => HookRef[] */
    private array $hooks;

    private string $command;

    /**
     * @param array<string, HookRef[]> $hooks
     */
    public function __construct(array $hooks, string $command = '')
    {
        $this->hooks = $hooks;
        $this->command = !empty($command) ? $command : self::DEFAULT_COMMAND;
    }

    /**
     * Build from raw config 'hooks' section.
     *
     * @param array<string, mixed> $raw The 'hooks' section
     * @param string[] $availableFlowNames
     * @param string[] $availableJobNames
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates both string and array ref formats
     * @SuppressWarnings(PHPMD.NPathComplexity) Extracts command + validates events with string/array refs
     */
    public static function fromArray(
        array $raw,
        array $availableFlowNames,
        array $availableJobNames,
        ValidationResult $result
    ): self {
        $hooks = [];
        $command = '';

        if (array_key_exists('command', $raw)) {
            if (!is_string($raw['command'])) {
                $result->addError("'hooks.command' must be a string (e.g. 'php7.4 vendor/bin/githooks').");
            } else {
                $command = $raw['command'];
            }
            unset($raw['command']);
        }

        foreach ($raw as $event => $refs) {
            if (!Hooks::validate($event)) {
                $result->addError("'$event' is not a valid git hook event.");
                continue;
            }

            if (!is_array($refs) || empty($refs)) {
                $result->addError("Hook '$event' must reference a non-empty array of flows or jobs.");
                continue;
            }

            $hookRefs = [];
            foreach ($refs as $ref) {
                $hookRef = null;

                if (is_string($ref)) {
                    $hookRef = HookRef::fromString($ref);
                } elseif (is_array($ref)) {
                    $hookRef = HookRef::fromArray($ref, $result);
                } else {
                    $result->addError("Hook '$event': each ref must be a string or array.");
                    continue;
                }

                if ($hookRef === null) {
                    continue;
                }

                $target = $hookRef->getTarget();
                if (
                    !in_array($target, $availableFlowNames, true)
                    && !in_array($target, $availableJobNames, true)
                ) {
                    $result->addError("Hook '$event' references '$target' which is not a defined flow or job.");
                }

                $hookRefs[] = $hookRef;
            }

            $hooks[$event] = $hookRefs;
        }

        return new self($hooks, $command);
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Get the HookRefs to execute for a hook event.
     *
     * @return HookRef[]
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

    /** @return array<string, HookRef[]> */
    public function getAll(): array
    {
        return $this->hooks;
    }
}
