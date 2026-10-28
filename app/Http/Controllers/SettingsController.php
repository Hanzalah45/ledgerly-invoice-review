<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\ApprovalChain;
use App\Services\Extraction\ExtractionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(ExtractionManager $providers): View
    {
        $presetOf = fn (array $steps) => collect(ApprovalChain::PRESETS)->search(fn ($p) => $p['steps'] === $steps) ?: 'reviewer';
        $saved = collect(app(ApprovalChain::class)->tiers());
        $defaults = [['upto' => 5000, 'preset' => 'reviewer'], ['upto' => 25000, 'preset' => 'reviewer_manager'], ['upto' => null, 'preset' => 'reviewer_manager_admin']];
        $tiers = collect([0, 1, 2])->map(fn ($i) => $saved->isNotEmpty()
            ? ['upto' => $saved[$i]['upto'] ?? null, 'preset' => isset($saved[$i]) ? $presetOf($saved[$i]['steps']) : $defaults[$i]['preset']]
            : $defaults[$i])->all();

        return view('settings', [
            'policy' => [
                'enabled' => $saved->isNotEmpty(),
                'tiers' => $tiers,
                'separate_duties' => (bool) Setting::get('separate_duties', true),
                'require_coding' => (bool) Setting::get('require_coding'),
            ],
            'providers' => $providers->overview(),
            'requested' => config('docai.provider'),
            'live' => $providers->isLive(),
            'limits' => [
                'Maximum file size' => round(config('docai.max_upload_kb') / 1024).' MB',
                'Files per upload' => config('docai.max_files_per_upload'),
                'Amount tolerance' => number_format(config('docai.amount_tolerance'), 2),
                'Low-confidence threshold' => (int) (config('docai.low_confidence') * 100).'%',
                'Queue connection' => config('queue.default'),
            ],
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role']),
            'workspace' => [
                'company_name' => Setting::get('company_name'),
                'company_legal_name' => Setting::get('company_legal_name'),
                'company_address' => Setting::get('company_address'),
                'company_tax_id' => Setting::get('company_tax_id'),
                'approval_threshold' => Setting::get('approval_threshold'),
                'require_po' => (bool) Setting::get('require_po'),
                'has_logo' => (bool) Setting::get('logo_path'),
            ],
        ]);
    }

    public function updateWorkspace(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:80'],
            'company_legal_name' => ['nullable', 'string', 'max:120'],
            'company_address' => ['nullable', 'string', 'max:300'],
            'company_tax_id' => ['nullable', 'string', 'max:40'],
            'approval_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'require_po' => ['nullable', 'boolean'],
            'tiers_enabled' => ['nullable', 'boolean'],
            'tier0_upto' => ['nullable', 'numeric', 'min:0.01', 'max:999999999'],
            'tier1_upto' => ['nullable', 'numeric', 'min:0.01', 'max:999999999', 'gt:tier0_upto'],
            'tier0_steps' => ['nullable', Rule::in(array_keys(ApprovalChain::PRESETS))],
            'tier1_steps' => ['nullable', Rule::in(array_keys(ApprovalChain::PRESETS))],
            'tier2_steps' => ['nullable', Rule::in(array_keys(ApprovalChain::PRESETS))],
            'separate_duties' => ['nullable', 'boolean'],
            'require_coding' => ['nullable', 'boolean'],
        ], ['tier1_upto.gt' => 'The second tier limit must be higher than the first.']);

        if ($request->boolean('tiers_enabled')) {
            if (blank($data['tier0_upto'] ?? null) || blank($data['tier1_upto'] ?? null)) {
                throw ValidationException::withMessages(['tier0_upto' => 'Enter the limits for the first two tiers, or switch approval chains off.']);
            }
            Setting::put('approval_tiers', [
                ['upto' => (float) $data['tier0_upto'], 'steps' => ApprovalChain::PRESETS[$data['tier0_steps'] ?? 'reviewer']['steps']],
                ['upto' => (float) $data['tier1_upto'], 'steps' => ApprovalChain::PRESETS[$data['tier1_steps'] ?? 'reviewer_manager']['steps']],
                ['upto' => null, 'steps' => ApprovalChain::PRESETS[$data['tier2_steps'] ?? 'reviewer_manager_admin']['steps']],
            ]);
        } else {
            Setting::put('approval_tiers', []);
        }
        Setting::put('separate_duties', $request->boolean('separate_duties'));
        Setting::put('require_coding', $request->boolean('require_coding'));

        foreach (['company_name', 'company_legal_name', 'company_address', 'company_tax_id'] as $key) {
            Setting::put($key, filled($data[$key] ?? null) ? trim($data[$key]) : null);
        }
        Setting::put('approval_threshold', filled($data['approval_threshold'] ?? null) && (float) $data['approval_threshold'] > 0 ? (float) $data['approval_threshold'] : null);
        Setting::put('require_po', $request->boolean('require_po'));

        return back()->with('success', 'Workspace settings saved.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => [
                'required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024',
                'dimensions:min_width=64,min_height=64,max_width=2400,max_height=2400',
            ],
        ], [
            'logo.mimes' => 'The logo must be a PNG, JPG or WebP image. SVG files are not accepted.',
            'logo.mimetypes' => 'The logo must be a PNG, JPG or WebP image. SVG files are not accepted.',
            'logo.max' => 'The logo must be 1 MB or smaller.',
            'logo.dimensions' => 'The logo must be between 64 and 2400 pixels on each side.',
        ]);

        $file = $request->file('logo');
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = 'branding/logo-'.Str::random(12).'.'.$extension;
        Storage::disk('local')->put($path, $file->get());

        $old = Setting::get('logo_path');
        Setting::put('logo_path', $path);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('success', 'Logo updated. It now appears in the sidebar and on approval records.');
    }

    public function deleteLogo(): RedirectResponse
    {
        if ($old = Setting::get('logo_path')) {
            Storage::disk('local')->delete($old);
        }
        Setting::put('logo_path', null);

        return back()->with('success', 'Logo removed.');
    }
}
