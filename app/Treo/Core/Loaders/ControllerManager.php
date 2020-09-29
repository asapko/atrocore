<?php

declare(strict_types=1);

namespace Treo\Core\Loaders;

use Treo\Core\ControllerManager as Instance;

/**
 * ControllerManager loader
 *
 * @author r.ratsun@zinitsolutions.com
 */
class ControllerManager extends Base
{

    /**
     * Load ControllerManager
     *
     * @return Instance
     */
    public function load()
    {
        return (new Instance())->setContainer($this->getContainer());
    }
}