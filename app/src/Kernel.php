<?php

namespace App;

use App\Util\Trans;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();
        // Per entita' e helper statici: il traduttore si crea solo se serve davvero.
        $container = $this->container;
        Trans::setResolver(static fn () => $container->get('translator'));
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
