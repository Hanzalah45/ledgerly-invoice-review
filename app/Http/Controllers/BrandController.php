<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandController extends Controller
{
    /** Serves the workspace logo (no auth: it appears on the login-adjacent chrome and in exports). */
    public function logo(): StreamedResponse
    {
        $path = Setting::get('logo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $type = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return Storage::disk('local')->response($path, 'logo', [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
