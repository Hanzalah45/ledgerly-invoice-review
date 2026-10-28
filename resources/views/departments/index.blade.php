@extends('layouts.app')
@section('title', 'Departments')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Departments</li>@endsection

@section('content')
<x-page-head title="Departments" subtitle="Cost centres that invoices are coded to. A department's manager approves the department manager step of an approval chain.">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" data-dept="{{ json_encode(['id' => null]) }}"><i class="bi bi-plus-lg"></i> Add department</button>
</x-page-head>

<div class="panel panel-flush">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Code</th><th>Department</th><th>Cost centre</th><th>Manager</th><th class="text-end">Invoices</th><th></th></tr></thead>
            <tbody>
            @forelse($departments as $d)
                <tr class="{{ $d->active ? '' : 'opacity-50' }}">
                    <td class="mono">{{ $d->code }}</td>
                    <td class="fw-medium">{{ $d->name }} @unless($d->active)<span class="tag">inactive</span>@endunless</td>
                    <td class="mono text-muted-2">{{ $d->cost_center ?? '—' }}</td>
                    <td>{{ $d->manager?->name ?? '—' }}</td>
                    <td class="text-end num">{{ $d->documents_count }}</td>
                    <td class="text-end"><button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#deptModal" data-dept="{{ json_encode($d->only(['id', 'name', 'code', 'cost_center', 'manager_id', 'active'])) }}">Edit</button></td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><i class="bi bi-diagram-3"></i><h3>No departments yet</h3><div>Add one so invoices can be coded and routed to a manager.</div></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="deptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" id="deptForm" action="{{ route('departments.store') }}">
        @csrf <input type="hidden" name="_method" value="POST" id="deptMethod">
        <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6" id="deptTitle">Add department</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-8"><label class="form-label" for="d-name">Name</label><input class="form-control" id="d-name" name="name" required maxlength="120"></div>
            <div class="col-4"><label class="form-label" for="d-code">Code</label><input class="form-control mono" id="d-code" name="code" required maxlength="20" placeholder="IT"></div>
            <div class="col-6"><label class="form-label" for="d-cc">Cost centre</label><input class="form-control mono" id="d-cc" name="cost_center" maxlength="40" placeholder="CC-4100"></div>
            <div class="col-6"><label class="form-label" for="d-mgr">Manager</label>
                <select class="form-select" id="d-mgr" name="manager_id"><option value="">None</option>@foreach($managers as $m)<option value="{{ $m->id }}">{{ $m->name }} ({{ $m->role }})</option>@endforeach</select></div>
            <div class="col-12" id="deptActiveRow"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="active" value="1" id="d-active" checked><label class="form-check-label" for="d-active">Active (can be chosen on invoices)</label></div></div>
        </div></div>
        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form></div>
</div>
<script>
document.getElementById('deptModal').addEventListener('show.bs.modal', (e) => {
    const d = JSON.parse(e.relatedTarget.dataset.dept), isNew = !d.id, f = document.getElementById('deptForm');
    f.action = isNew ? @json(route('departments.store')) : @json(url('/departments')) + '/' + d.id;
    document.getElementById('deptMethod').value = isNew ? 'POST' : 'PUT';
    document.getElementById('deptTitle').textContent = isNew ? 'Add department' : 'Edit ' + d.name;
    document.getElementById('deptActiveRow').style.display = isNew ? 'none' : '';
    document.getElementById('d-name').value = d.name ?? '';
    document.getElementById('d-code').value = d.code ?? '';
    document.getElementById('d-cc').value = d.cost_center ?? '';
    document.getElementById('d-mgr').value = d.manager_id ?? '';
    document.getElementById('d-active').checked = isNew ? true : !!d.active;
});
</script>
@endsection
