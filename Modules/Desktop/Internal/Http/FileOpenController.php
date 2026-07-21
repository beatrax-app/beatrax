<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Desktop\Internal\Native\FileOpenIntake;

/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final class FileOpenController
{
    public function __construct(
        private readonly FileOpenIntake $intake,
    ) {}

    public function __invoke(Request $request): Response
    {
        $path = $request->input('path');
        if (! is_string($path) || $path === '') {
            // Malformed payload — accept the HTTP request but emit no
            // event. The Electron caller is best-effort; a 200 keeps
            // it from retrying needlessly.
            return new Response('', 204);
        }

        $this->intake->receive($path);

        return new Response('', 204);
    }
}
