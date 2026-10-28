<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SampleController extends Controller
{
    /** Serves one of the generated synthetic sample invoices (whitelisted by file name). */
    public function __invoke(string $file): BinaryFileResponse
    {
        $name = basename($file);
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $name) === 1, 404);

        $path = storage_path('app/samples/'.$name);
        abort_unless(is_file($path), 404);

        return response()->download($path, $name, ['Content-Type' => 'application/pdf']);
    }
}
