<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Group;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;
use Infocyph\Webrick\Router\Definition\Attribute\Route;
use Infocyph\Webrick\Response\Response;

#[Group(prefix: '/attributes', domain: 'api.example.test', name: 'lab.attribute.')]
#[Produces(['application/json'])]
final class LabAttributeController
{
    #[Route('GET', '/items/{id:int}', 'show', middleware: [LabRouteMarker::class])]
    #[Route('GET', '/legacy/{id:int}', 'legacy')]
    #[Cors(['https://client.example.test'], methods: 'GET', maxAgeSeconds: 60)]
    public static function item(string $id): Response
    {
        return Response::json(['id' => (int) $id]);
    }

    public static function index(): Response
    {
        return Response::json(['action' => 'index']);
    }

    public static function store(): Response
    {
        return Response::json(['action' => 'store'], 201);
    }

    public static function show(string $record): Response
    {
        return Response::json(['action' => 'show', 'id' => $record]);
    }

    public static function update(string $record): Response
    {
        return Response::json(['action' => 'update', 'id' => $record]);
    }

    public static function patch(string $record): Response
    {
        return Response::json(['action' => 'patch', 'id' => $record]);
    }

    public static function destroy(string $record): Response
    {
        return Response::json(['action' => 'destroy', 'id' => $record]);
    }
}
