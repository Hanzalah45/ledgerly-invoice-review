<?php

use App\Http\Controllers\Api\InboundEmailController;
use Illuminate\Support\Facades\Route;

// Inbound invoices by email. Point a mail-to-webhook service (Postmark inbound, Mailgun routes, SendGrid inbound parse,
// n8n, Zapier) at this URL. Disabled (404) until DOCAI_INBOUND_TOKEN is set.
Route::post('/inbound/email', InboundEmailController::class)->middleware('throttle:60,1')->name('inbound.email');
