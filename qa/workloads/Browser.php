<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Pathwise\Exceptions\PathwiseException;
use Infocyph\Pathwise\StreamHandler\PublicFileResolver;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class Browser
{
    public static function state(BrowserSession $session): Response
    {
        return Response::json([
            'count' => $session->get('count', 0),
            'csrf' => $session->csrfToken(),
            'flash' => $session->get('notice'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public static function increment(BrowserSession $session): Response
    {
        $count = countValue($session->get('count', 0)) + 1;
        $session->put('count', $count);

        return Response::json(['count' => $count])->withHeader('Cache-Control', 'no-store');
    }

    public static function rotate(BrowserSession $session): Response
    {
        $session->regenerate();

        return self::state($session);
    }

    public static function invalidate(BrowserSession $session): Response
    {
        $session->invalidate();

        return self::state($session);
    }

    public static function flash(BrowserSession $session): Response
    {
        $session->flash('notice', 'Saved');

        return Response::noContent();
    }

    public static function item(int $id): Response
    {
        return Response::json(['item' => $id]);
    }

    public static function literal(): Response
    {
        return Response::json(['item' => 'new']);
    }

    public static function negotiate(Request $request): Response
    {
        return Response::auto($request, ['item' => 'report']);
    }

    public static function compress(): Response
    {
        return Response::plaintext(str_repeat('report-line' . "\n", 1024))
            ->withHeader('Cache-Control', 'public, max-age=60');
    }

    public static function asset(Request $request, string $name): Response
    {
        try {
            $asset = new PublicFileResolver()->resolve(dirname(__DIR__) . '/storage/lab-public', $name);
        } catch (PathwiseException) {
            return Response::plaintext('Not found', 404);
        }

        return Response::rangedFile($request, $asset->path, $asset->mimeType, ['Cache-Control' => 'public, max-age=60']);
    }

    public static function stream(): Response
    {
        return Response::stream(static function (): \Generator {
            for ($i = 1; $i <= 128; ++$i) {
                yield json_encode(['line' => $i], JSON_THROW_ON_ERROR) . "\n";
            }
        })->withHeader('Content-Type', 'application/x-ndjson');
    }
}
