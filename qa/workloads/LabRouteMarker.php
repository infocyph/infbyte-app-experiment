<?php

declare(strict_types=1);

namespace Experiment;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class LabRouteMarker
{
    public function __invoke(Request $request, Closure $next): Response
    {
        return $next($request)->withHeader('X-Lab-Marker', 'applied');
    }
}
