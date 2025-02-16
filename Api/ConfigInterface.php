<?php

namespace Lof\Configurator\Api;

use Lof\Configurator\Model\Component\ComponentAbstract;

interface ConfigInterface
{

    /**
     * Gets all the different available components
     * @return array
     */
    public function getAllComponents();

    /**
     * Gets a single component by its name
     *
     * @param String $name
     * @return ComponentAbstract
     */
    public function getComponentByName($name);
}
