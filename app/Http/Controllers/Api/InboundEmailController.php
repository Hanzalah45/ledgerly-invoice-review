<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /api/inbound/email  (Authorization: Bearer <DOCAI_INBOUND_TOKEN>)
 *
 * Accepts the JSON that mail-to-webhook services send. Two shapes are understood:
 *   Postmark inbound: { MessageID, From, Subject, Attachments: [{ Name, Content (base64), ContentType }] }
 *   Generic:          { message_id, from, subject, attachments: [{ filename, content_base64 }] }
 * Every PDF attachment becomes an invoice, uploaded by the configured inbox user and processed like a normal upload.
 * Other attachments are skipped and reported. The same message id is accepted once.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, DocumentWorkflow $workflow): JsonResponse
    {
        $token = (string) config('docai.inbound.token');
        abort_if($token === '', 404);
        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $payload = $request->json()->all();
        $from = Str::lower(trim((string) ($payload['From'] ?? $payload['from'] ?? '')));
        $email = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
        $subject = Str::limit(trim((string) ($payload['Subject'] ?? $payload['subject'] ?? '')), 150, '');
        $messageId = trim((string) ($payload['MessageID'] ?? $payload['message_id'] ?? ''));

        $allowed = array_filter(array_map('trim', explode(',', Str::lower((string) config('docai.inbound.allowed_domains')))));
        if ($allowed !== [] && ! in_array(Str::after($email, '@'), $allowed, true)) {
            return response()->json(['error' => 'sender_not_allowed'], 403);
        }

        if ($messageId !== '' && Document::where('inbound_message_id', 'like', Str::limit($messageId, 120, '').'%')->exists()) {
            return response()->json(['duplicate' => true, 'documents' => []], 200);
        }

        $user = User::where('email', config('docai.inbound.user_email'))->first() ?? User::where('role', User::ROLE_ADMIN)->orderBy('id')->first();
        abort_unless($user, 503, 'No user is available to own inbound invoices.');

        $attachments = collect($payload['Attachments'] ?? $payload['attachments'] ?? [])
            ->filter(fn ($a) => is_array($a))
            ->take((int) config('docai.max_files_per_upload'));

        $created = [];
        $skipped = [];
        foreach ($attachments->values() as $i => $attachment) {
            $name = Str::limit(basename(str_replace('\\', '/', (string) ($attachment['Name'] ?? $attachment['filename'] ?? 'attachment.pdf'))), 150, '');
            $data = base64_decode((string) ($attachment['Content'] ?? $attachment['content_base64'] ?? ''), true);

            if ($data === false || ! str_starts_with($data, '%PDF-')) {
                $skipped[] = ['name' => $name, 'reason' => 'not a PDF'];
            } elseif (strlen($data) > (int) config('docai.max_upload_kb') * 1024) {
                $skipped[] = ['name' => $name, 'reason' => 'too large'];
            } else {
                $document = $workflow->importFromEmail($data, $name, $user, $email, $subject, $messageId !== '' ? Str::limit($messageId, 120, '').'#'.($i + 1) : null);
                $created[] = $document->uuid;
            }
        }

        if ($created === []) {
            return response()->json(['error' => 'no_pdf_attachment', 'skipped' => $skipped], 422);
        }

        return response()->json(['documents' => $created, 'skipped' => $skipped], 201);
    }
}
