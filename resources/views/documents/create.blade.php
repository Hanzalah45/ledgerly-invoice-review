@extends('layouts.app')
@section('title', 'Upload documents')
@section('crumbs')
    <li class="breadcrumb-item"><a href="{{ route('documents.index') }}">Documents</a></li>
    <li class="breadcrumb-item active" aria-current="page">Upload</li>
@endsection

@section('content')
<x-page-head title="Upload documents" subtitle="Add invoice or receipt PDFs. Each file is read, extracted and validated automatically." />

<div class="row g-3">
    <div class="col-lg-8">
        <form id="uploadForm" method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" novalidate>
            @csrf
            <div class="panel">
                <div class="panel-body">
                    <div id="dropzone" class="dropzone" tabindex="0" role="button" aria-label="Choose PDF files to upload"
                         data-max-bytes="{{ $maxMb * 1048576 }}" data-max-files="{{ $maxFiles }}">
                        <div class="icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div class="fw-semibold">Drop PDF files here, or click to browse</div>
                        <div class="text-muted-2 fs-sm mt-1">PDF only · up to {{ $maxMb }} MB each · up to {{ $maxFiles }} files at a time</div>
                    </div>
                    <input type="file" id="fileInput" name="files[]" accept="application/pdf,.pdf" multiple class="d-none">

                    @if($errors->has('files') || $errors->has('files.*'))
                        <div class="alert alert-danger mt-3 mb-0 py-2 fs-sm" role="alert">
                            @foreach($errors->get('files') as $message)@foreach((array) $message as $line)<div>{{ $line }}</div>@endforeach @endforeach
                            @foreach($errors->get('files.*') as $messages)@foreach($messages as $line)<div>{{ $line }}</div>@endforeach @endforeach
                        </div>
                    @endif

                    <div id="fileList" class="d-grid gap-2 mt-3"></div>
                    <p id="fileHint" class="fs-sm text-muted-2 mt-3 mb-0">No files selected yet.</p>
                </div>
                <div class="panel-head border-top border-bottom-0 justify-content-end">
                    <a href="{{ route('documents.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" id="uploadSubmit" class="btn btn-primary" disabled><i class="bi bi-lightning-charge"></i> Upload &amp; process<span class="n"></span></button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="panel mb-3">
            <div class="panel-head"><h2>Extraction engine</h2></div>
            <div class="panel-body fs-sm">
                @if($live)
                    <span class="tag live mb-2">Live AI</span>
                    <p class="mb-1"><strong>{{ ucfirst($active->name()) }}</strong> <span class="mono text-muted-2">{{ $active->model() }}</span></p>
                    <p class="mb-1 text-muted-2">The text of each PDF is sent to this provider. Scanned PDFs (no text layer) are sent as a file and read visually.</p>
                    <p class="text-muted-2 mb-0">Do not upload documents you are not allowed to share with it.</p>
                @else
                    <span class="tag demo mb-2">Demo parser</span>
                    <p class="mb-1">Fields are read from the PDF text with rules. <strong>This is not an AI model</strong> and works best on clean, text-based invoices.</p>
                    <p class="text-muted-2 mb-0">Scanned PDFs need a live AI provider (OpenAI or Anthropic) to be read; this parser reads text only.</p>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><div><h2>Sample invoices</h2><div class="sub">Synthetic files for trying the app</div></div></div>
            <div style="max-height: 340px; overflow-y: auto;">
            @forelse($samples as $sample)
                <a href="{{ route('samples.download', $sample['name']) }}" class="d-flex align-items-center gap-2 px-3 py-2 text-body border-bottom fs-sm">
                    <i class="bi bi-file-earmark-pdf text-danger"></i>
                    <span class="text-truncate flex-grow-1">{{ $sample['name'] }}</span>
                    <span class="text-muted-2 fs-xs text-nowrap">{{ $sample['size'] }}</span>
                </a>
            @empty
                <div class="panel-body fs-sm text-muted-2">Run <span class="mono">php artisan docai:make-samples</span> to generate sample invoices.</div>
            @endforelse
            </div>
            <div class="px-3 py-2 fs-xs text-muted-2">Files named <span class="mono">FLAWED-</span> contain deliberate errors to demonstrate validation.</div>
        </div>
    </div>
</div>
@endsection
