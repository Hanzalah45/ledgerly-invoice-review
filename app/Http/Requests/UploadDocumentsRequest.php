<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Document::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.config('docai.max_files_per_upload')],
            'files.*' => [
                'required', 'file', 'mimes:pdf', 'mimetypes:application/pdf',
                'max:'.config('docai.max_upload_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Choose at least one PDF to upload.',
            'files.max' => 'You can upload up to '.config('docai.max_files_per_upload').' files at a time.',
            'files.*.mimes' => 'Only PDF files are accepted.',
            'files.*.mimetypes' => 'Only PDF files are accepted.',
            'files.*.max' => 'Each file must be '.round(config('docai.max_upload_kb') / 1024).' MB or smaller.',
            'files.*.uploaded' => 'A file failed to upload. It may be larger than the server allows.',
        ];
    }
}
