<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Zero;

use LaravelZero\Framework\Commands\Command;
use LaravelZero\Framework\Components;
use Symfony\Component\Console\Input\ArrayInput;

final class InstallCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'app:install {component? : The component name}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Install optional components';

    /**
     * The list of components installers.
     *
     * @var array
     */
    protected $components = [
        'console-dusk' => Components\ConsoleDusk\Installer::class,
        'database' => Components\Database\Installer::class,
        'dotenv' => Components\Dotenv\Installer::class,
        'http' => Components\Http\Installer::class,
        'log' => Components\Log\Installer::class,
        'logo' => Components\Logo\Installer::class,
        'menu' => Components\Menu\Installer::class,
        'queue' => Components\Queue\Installer::class,
        'redis' => Components\Redis\Installer::class,
        'schedule-list' => Components\ScheduleList\Installer::class,
        'self-update' => Components\Updater\Installer::class,
    ];

    /**
     * {@inheritdoc}
     */
    public function handle()
    {
        $title = 'Laravel Zero - Component installer';

        $choices = [];
        foreach ($this->components as $name => $componentClass) {
            $choices[$name] = $this->app->make($componentClass)->getDescription();
        }

        if (!$option = $this->argument('component')) {
            $option = $this->choice($title, $choices);
        }

        if ($option !== null && !empty($this->components[$option])) {
            $command = tap($this->app[$this->components[$option]])->setLaravel($this->app);

            $command->setApplication($this->getApplication());

            $this->info("Installing {$option} component...");

            $command->run(new ArrayInput([]), $this->output);
        }
    }
}
