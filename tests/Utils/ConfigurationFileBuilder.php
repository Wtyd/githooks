<?php

namespace Tests\Utils;

/**
 * Builds v3 (hooks/flows/jobs) configuration files for tests. The default is
 * a `qa` flow with two always-passing custom jobs wired to pre-commit; every
 * part can be replaced with the setters before building:
 *      1. buildV3Array: the configuration as an associative array.
 *      2. buildPhp: the same as the body of a `<?php return [...];` file.
 *      3. buildInFileSystem: writes that file under the root path (or a sub path).
 */
class ConfigurationFileBuilder
{
    public const FILE_NAME = 'githooks.php';

    protected $name;

    protected $rootPath;

    /** @var array<string, string[]> Hook event → target names */
    protected $v3Hooks = [];

    /** @var array<string, array> Flow name → flow definition */
    protected $v3Flows = [];

    /** @var array<string, array> Job name → job definition */
    protected $v3Jobs = [];

    /** @var array{fail-fast: bool, processes: int} */
    protected $v3GlobalOptions = ['fail-fast' => false, 'processes' => 1];

    /**
     * @param string $rootPath Customize what path you would as project root
     */
    public function __construct(string $rootPath)
    {
        $this->name = self::FILE_NAME;
        $this->rootPath = $rootPath;
        $this->enableV3Mode();
    }

    /**
     * Reset to the v3 defaults. The builder has produced nothing but v3 since
     * the v2 engine was removed, so right after construction this is a no-op;
     * it is kept so the tests written when the two shapes coexisted still read.
     */
    public function enableV3Mode(): self
    {
        $this->v3Hooks = ['pre-commit' => ['qa']];

        $this->v3GlobalOptions = ['fail-fast' => false, 'processes' => 1];

        $this->v3Flows = [
            'qa' => [
                'jobs' => ['phpcs_src', 'phpstan_src'],
            ],
        ];

        $this->v3Jobs = [
            'phpcs_src' => [
                'type' => 'custom',
                'script' => 'true',
            ],
            'phpstan_src' => [
                'type' => 'custom',
                'script' => 'true',
            ],
        ];

        return $this;
    }

    /** @param array<string, string[]> $hooks */
    public function setV3Hooks(array $hooks): self
    {
        $this->v3Hooks = $hooks;
        return $this;
    }

    /**
     * @param string $name Flow name
     * @param string[] $jobs Job names in this flow
     * @param array|null $options Per-flow options override
     */
    public function addV3Flow(string $name, array $jobs, ?array $options = null): self
    {
        $flow = ['jobs' => $jobs];
        if ($options !== null) {
            $flow['options'] = $options;
        }
        $this->v3Flows[$name] = $flow;
        return $this;
    }

    /**
     * Replace all flows at once.
     * @param array<string, array> $flows
     */
    public function setV3Flows(array $flows): self
    {
        $this->v3Flows = $flows;
        return $this;
    }

    public function addV3Job(string $name, string $type, array $config = []): self
    {
        $this->v3Jobs[$name] = array_merge(['type' => $type], $config);
        return $this;
    }

    /**
     * Replace all jobs at once.
     * @param array<string, array> $jobs
     */
    public function setV3Jobs(array $jobs): self
    {
        $this->v3Jobs = $jobs;
        return $this;
    }

    public function setV3GlobalOptions(array $options): self
    {
        $this->v3GlobalOptions = $options;
        return $this;
    }

    public function setName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    public function buildV3Array(): array
    {
        $config = [];

        if (!empty($this->v3Hooks)) {
            $config['hooks'] = $this->v3Hooks;
        }

        $flows = [];
        if (!empty($this->v3GlobalOptions)) {
            $flows['options'] = $this->v3GlobalOptions;
        }
        foreach ($this->v3Flows as $name => $flow) {
            $flows[$name] = $flow;
        }
        if (!empty($flows)) {
            $config['flows'] = $flows;
        }

        if (!empty($this->v3Jobs)) {
            $config['jobs'] = $this->v3Jobs;
        }

        return $config;
    }

    public function buildV3Php(): string
    {
        return '<?php' . PHP_EOL . 'return ' . var_export($this->buildV3Array(), true) . ';';
    }

    public function buildPhp(): string
    {
        return $this->buildV3Php();
    }

    public function buildInFileSystem($path = '', bool $absolutePath = false): void
    {
        $finalPath = '';
        if (!empty($path)) {
            $finalPath = $absolutePath ? $path : "$this->rootPath/$path";
            $finalPath = rtrim($finalPath, '/');
        } else {
            $finalPath = $this->rootPath;
        }

        if (!is_dir($finalPath)) {
            mkdir($finalPath, 0777, true);
        }

        file_put_contents("$finalPath/{$this->name}", $this->buildPhp());
    }
}
