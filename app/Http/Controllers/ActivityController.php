<?php

namespace App\Http\Controllers;

use App\Models\DocumentEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $type = $request->query('type');

        $events = DocumentEvent::query()
            ->with(['document:id,uuid,original_name,supplier_name,invoice_number', 'user:id,name'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('created_at')->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('activity', [
            'events' => $events,
            'type' => $type,
            'types' => DocumentEvent::query()->select('type')->distinct()->orderBy('type')->pluck('type'),
        ]);
    }
}
