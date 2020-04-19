<?php

namespace GitHooks;

use GitHooks\LoadTools\FullStrategy;
use GitHooks\LoadTools\SmartStrategy;
use GitHooks\LoadTools\StrategyInterface;
use Illuminate\Container\Container;

class ChooseStrategy
{
    /**
     * Crea y devuelve la estrategia configurada en el fichero de configuración
     *
     * @param array $file. Fichero de configuración.
     * @return StrategyInterface
     */
    public function __invoke(array $file): StrategyInterface
    {
        $container = Container::getInstance();

        if ($this->isTheSmartStrategyConfigured($file)) {
            $strategy = $container->makeWith(SmartStrategy::class, ['configurationFile' => $file]);
        } else {
            $strategy = $container->makeWith(FullStrategy::class, ['configurationFile' => $file]);
        }

        return $strategy;
    }

    /**
     * La smartStrategy se selecciona cuando existe el array Options y este tiene el valor SMART_EXECUTION con valor true. False en cualquier otro caso
     * @param array $file. Fichero de configuración.
     *
     * @return bool
     */
    protected function isTheSmartStrategyConfigured(array $file): bool
    {
        if (isset($file[Constants::OPTIONS]) && in_array([Constants::SMART_EXECUTION => true], $file[Constants::OPTIONS])) {
            return true;
        }

        return false;
    }
}
