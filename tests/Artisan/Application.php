<?php

namespace Tests\Artisan;

use Illuminate\Container\Container;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Contracts\Console\Kernel;

class Application extends FoundationApplication
{
    public function __construct()
    {
        $this->namespace = 'GitHooks';

        parent::__construct();
    }

    /**
     * Register the basic bindings into the container.
     *
     * @return void
     */
    protected function registerBaseBindings()
    {
        // static::setInstance($this);
        static::setInstance(Container::getInstance());

        // $this->instance('app', $this);
        $this->instance('app', Container::getInstance());

        // $this->instance(Container::class, $this);
        $this->singleton(Mix::class);

        //No quiero instanciar el Filesystem, de momento
        // $this->instance(PackageManifest::class, new PackageManifest(
        //     new Filesystem, $this->basePath(), $this->getCachedPackagesPath()
        // ));
    }

    /**
     * Register all of the base service providers.
     *
     * @return void
     */
    protected function registerBaseServiceProviders()
    {
        $this->register(new EventServiceProvider($this));
        // $this->register(new LogServiceProvider($this));
        // $this->register(new RoutingServiceProvider($this));
    }

    /**
     * Register the core class aliases in the container.
     *
     * @return void
     */
    public function registerCoreContainerAliases()
    {
        foreach ([
            'app'                  => [\Illuminate\Foundation\Application::class, \Illuminate\Contracts\Container\Container::class, \Illuminate\Contracts\Foundation\Application::class,  \Psr\Container\ContainerInterface::class],
            // 'auth'                 => [\Illuminate\Auth\AuthManager::class, \Illuminate\Contracts\Auth\Factory::class],
            // 'auth.driver'          => [\Illuminate\Contracts\Auth\Guard::class],
            // 'blade.compiler'       => [\Illuminate\View\Compilers\BladeCompiler::class],
            // 'cache'                => [\Illuminate\Cache\CacheManager::class, \Illuminate\Contracts\Cache\Factory::class],
            // 'cache.store'          => [\Illuminate\Cache\Repository::class, \Illuminate\Contracts\Cache\Repository::class],
            // 'config'               => [\Illuminate\Config\Repository::class, \Illuminate\Contracts\Config\Repository::class],
            // 'cookie'               => [\Illuminate\Cookie\CookieJar::class, \Illuminate\Contracts\Cookie\Factory::class, \Illuminate\Contracts\Cookie\QueueingFactory::class],
            // 'encrypter'            => [\Illuminate\Encryption\Encrypter::class, \Illuminate\Contracts\Encryption\Encrypter::class],
            // 'db'                   => [\Illuminate\Database\DatabaseManager::class],
            // 'db.connection'        => [\Illuminate\Database\Connection::class, \Illuminate\Database\ConnectionInterface::class],
            'events'               => [\Illuminate\Events\Dispatcher::class, \Illuminate\Contracts\Events\Dispatcher::class],
            // 'files'                => [\Illuminate\Filesystem\Filesystem::class],
            // 'filesystem'           => [\Illuminate\Filesystem\FilesystemManager::class, \Illuminate\Contracts\Filesystem\Factory::class],
            // 'filesystem.disk'      => [\Illuminate\Contracts\Filesystem\Filesystem::class],
            // 'filesystem.cloud'     => [\Illuminate\Contracts\Filesystem\Cloud::class],
            // 'hash'                 => [\Illuminate\Hashing\HashManager::class],
            // 'hash.driver'          => [\Illuminate\Contracts\Hashing\Hasher::class],
            // 'translator'           => [\Illuminate\Translation\Translator::class, \Illuminate\Contracts\Translation\Translator::class],
            // 'log'                  => [\Illuminate\Log\LogManager::class, \Psr\Log\LoggerInterface::class],
            // 'mailer'               => [\Illuminate\Mail\Mailer::class, \Illuminate\Contracts\Mail\Mailer::class, \Illuminate\Contracts\Mail\MailQueue::class],
            // 'auth.password'        => [\Illuminate\Auth\Passwords\PasswordBrokerManager::class, \Illuminate\Contracts\Auth\PasswordBrokerFactory::class],
            // 'auth.password.broker' => [\Illuminate\Auth\Passwords\PasswordBroker::class, \Illuminate\Contracts\Auth\PasswordBroker::class],
            // 'queue'                => [\Illuminate\Queue\QueueManager::class, \Illuminate\Contracts\Queue\Factory::class, \Illuminate\Contracts\Queue\Monitor::class],
            // 'queue.connection'     => [\Illuminate\Contracts\Queue\Queue::class],
            // 'queue.failer'         => [\Illuminate\Queue\Failed\FailedJobProviderInterface::class],
            // 'redirect'             => [\Illuminate\Routing\Redirector::class],
            // 'redis'                => [\Illuminate\Redis\RedisManager::class, \Illuminate\Contracts\Redis\Factory::class],
            // 'request'              => [\Illuminate\Http\Request::class, \Symfony\Component\HttpFoundation\Request::class],
            // 'router'               => [\Illuminate\Routing\Router::class, \Illuminate\Contracts\Routing\Registrar::class, \Illuminate\Contracts\Routing\BindingRegistrar::class],
            // 'session'              => [\Illuminate\Session\SessionManager::class],
            // 'session.store'        => [\Illuminate\Session\Store::class, \Illuminate\Contracts\Session\Session::class],
            // 'url'                  => [\Illuminate\Routing\UrlGenerator::class, \Illuminate\Contracts\Routing\UrlGenerator::class],
            // 'validator'            => [\Illuminate\Validation\Factory::class, \Illuminate\Contracts\Validation\Factory::class],
            // 'view'                 => [\Illuminate\View\Factory::class, \Illuminate\Contracts\View\Factory::class],
        ] as $key => $aliases) {
            foreach ($aliases as $alias) {
                $this->alias($key, $alias);
            }
        }
    }

    /**
     * Resolve the given type from the container.
     *
     * (Overriding Container::make)
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->deferredServices[$abstract]) && !isset($this->instances[$abstract])) {
            $this->loadDeferredProvider($abstract);
        }

        $instance = parent::make($abstract, $parameters);
        return $instance;
    }
}
