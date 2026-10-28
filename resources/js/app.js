import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const money = (n) => (Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—');
const parse = (value) => {
    const n = parseFloat(String(value ?? '').replace(/[, ]/g, ''));
    return Number.isFinite(n) ? n : null;
};

/* ------------------------------------------------------------------ Toasts */
function initToasts() {
    $$('.toast').forEach((el) => {
        const toast = new bootstrap.Toast(el, { delay: el.dataset.bsDelay ? Number(el.dataset.bsDelay) : 6000 });
        toast.show();
    });
}

/* ------------------------------------------------------- Confirm dialogs */
function initConfirm() {
    const modalEl = $('#confirmModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    let pendingForm = null;

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') return;
        event.preventDefault();
        pendingForm = form;
        $('#confirmTitle').textContent = form.dataset.confirmTitle || 'Are you sure?';
        $('#confirmBody').textContent = form.dataset.confirm;
        const button = $('#confirmAction');
        button.textContent = form.dataset.confirmLabel || 'Confirm';
        button.className = 'btn ' + (form.dataset.confirmTone === 'danger' ? 'btn-danger' : 'btn-primary');
        modal.show();
    });

    $('#confirmAction').addEventListener('click', () => {
        if (!pendingForm) return;
        pendingForm.dataset.confirmed = '1';
        modal.hide();
        pendingForm.requestSubmit();
    });
}

/* --------------------------------------------------------- Auto-submit filters */
function initFilters() {
    $$('[data-autosubmit]').forEach((el) => {
        el.addEventListener('change', () => el.form && el.form.requestSubmit());
    });
}

/* ------------------------------------------------ Bulk selection (documents) */
function initBulk() {
    const table = $('#documentsTable');
    if (!table) return;
    const all = $('#selectAll');
    const boxes = () => $$('input.row-check', table);
    const bar = $('#bulkBar');
    const count = $('#bulkCount');
    // Viewers have no checkboxes or bulk bar, so there is nothing to wire up.
    if (!all || !bar || !count) return;
    const refresh = () => {
        const checked = boxes().filter((b) => b.checked);
        bar.classList.toggle('d-none', checked.length === 0);
        count.textContent = `${checked.length} selected`;
        all.checked = checked.length > 0 && checked.length === boxes().length;
        all.indeterminate = checked.length > 0 && checked.length < boxes().length;
        $$('[data-bulk-ids]').forEach((holder) => {
            holder.innerHTML = '';
            checked.forEach((b) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = b.value;
                holder.appendChild(input);
            });
        });
        const exportLink = $('#bulkExport');
        if (exportLink) {
            const url = new URL(exportLink.dataset.base, window.location.origin);
            checked.forEach((b) => url.searchParams.append('ids[]', b.value));
            url.searchParams.set('status', 'all');
            exportLink.href = url.toString();
        }
    };
    all.addEventListener('change', () => {
        boxes().forEach((b) => (b.checked = all.checked));
        refresh();
    });
    table.addEventListener('change', (e) => e.target.classList.contains('row-check') && refresh());
    $('#bulkClear')?.addEventListener('click', () => {
        boxes().forEach((b) => (b.checked = false));
        refresh();
    });
    refresh();
}

/* ------------------------------------------------------------ Upload page */
function initDropzone() {
    const zone = $('#dropzone');
    if (!zone) return;

    const input = $('#fileInput');
    const list = $('#fileList');
    const submit = $('#uploadSubmit');
    const hint = $('#fileHint');
    const maxBytes = Number(zone.dataset.maxBytes);
    const maxFiles = Number(zone.dataset.maxFiles);
    let files = [];

    const render = () => {
        list.innerHTML = '';
        let valid = 0;
        files.forEach((entry, index) => {
            const row = document.createElement('div');
            row.className = 'file-row' + (entry.error ? ' invalid' : '');
            row.innerHTML = `
                <i class="bi bi-file-earmark-pdf"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-medium text-truncate"></div>
                    <div class="fs-xs ${entry.error ? 'text-danger' : 'text-muted-2'}"></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Remove file"><i class="bi bi-x-lg"></i></button>`;
            row.querySelector('.fw-medium').textContent = entry.file.name;
            row.querySelector('.fs-xs').textContent = entry.error || formatSize(entry.file.size);
            row.querySelector('button').addEventListener('click', () => {
                files.splice(index, 1);
                sync();
            });
            list.appendChild(row);
            if (!entry.error) valid++;
        });
        submit.disabled = valid === 0 || files.some((f) => f.error);
        submit.querySelector('.n').textContent = valid ? ` (${valid})` : '';
        hint.classList.toggle('d-none', files.length > 0);
    };

    const sync = () => {
        const transfer = new DataTransfer();
        files.forEach((f) => transfer.items.add(f.file));
        input.files = transfer.files;
        render();
    };

    const add = (incoming) => {
        Array.from(incoming).forEach((file) => {
            if (files.some((f) => f.file.name === file.name && f.file.size === file.size)) return;
            let error = null;
            if (!/\.pdf$/i.test(file.name) || (file.type && file.type !== 'application/pdf')) error = 'Only PDF files are accepted.';
            else if (file.size > maxBytes) error = `Larger than the ${Math.round(maxBytes / 1048576)} MB limit.`;
            else if (file.size === 0) error = 'This file is empty.';
            files.push({ file, error });
        });
        if (files.length > maxFiles) {
            files = files.slice(0, maxFiles);
            hint.textContent = `Only the first ${maxFiles} files were kept.`;
        }
        sync();
    };

    zone.addEventListener('click', () => input.click());
    zone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            input.click();
        }
    });
    ['dragenter', 'dragover'].forEach((t) =>
        zone.addEventListener(t, (e) => {
            e.preventDefault();
            zone.classList.add('is-over');
        })
    );
    ['dragleave', 'drop'].forEach((t) =>
        zone.addEventListener(t, (e) => {
            e.preventDefault();
            zone.classList.remove('is-over');
        })
    );
    zone.addEventListener('drop', (e) => add(e.dataTransfer.files));
    input.addEventListener('change', () => {
        const picked = Array.from(input.files);
        files = files.filter((f) => picked.some((p) => p.name === f.file.name && p.size === f.file.size));
        add(picked);
    });

    $('#uploadForm').addEventListener('submit', (e) => {
        if (submit.disabled) {
            e.preventDefault();
            return;
        }
        submit.disabled = true;
        submit.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Uploading and processing…';
    });

    render();
}

function formatSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

/* ------------------------------------------- Document editor (line items) */
function initDocumentEditor() {
    const form = $('#documentForm');
    if (!form) return;

    const body = $('#lineItemsBody');
    const template = $('#lineItemTemplate');
    const strip = $('#checkStrip');
    const saveBtn = $('#saveBtn');
    const editable = form.dataset.editable === '1';
    const tolerance = Number(form.dataset.tolerance || 0.01);

    const reindex = () => {
        $$('tr.li-row', body).forEach((row, i) => {
            $$('input', row).forEach((input) => {
                input.name = input.name.replace(/line_items\[[^\]]*\]/, `line_items[${i}]`);
            });
        });
        $('#lineItemsEmpty')?.classList.toggle('d-none', $$('tr.li-row', body).length > 0);
    };

    const recalc = () => {
        let sum = 0;
        let counted = 0;
        $$('tr.li-row', body).forEach((row) => {
            const qty = parse($('[data-f=quantity]', row).value);
            const unit = parse($('[data-f=unit_price]', row).value);
            const amount = parse($('[data-f=amount]', row).value);
            if (amount !== null) {
                sum += amount;
                counted++;
            }
            const off = qty !== null && unit !== null && amount !== null && Math.abs(Math.round(qty * unit * 100) / 100 - amount) > 0.02;
            row.classList.toggle('line-warn', off);
            row.title = off ? `Quantity × unit price = ${money(qty * unit)}` : '';
        });

        const subtotal = parse(form.elements.subtotal?.value);
        const tax = parse(form.elements.tax?.value);
        const total = parse(form.elements.total?.value);
        const discount = parse(form.elements.discount?.value) ?? 0;
        const shipping = parse(form.elements.shipping?.value) ?? 0;
        const rows = [];
        const flag = (label, a, b, okText) => {
            const ok = Math.abs(a - b) <= tolerance;
            rows.push(`<span class="${ok ? 'ok' : 'bad'}"><i class="bi ${ok ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'}"></i> ${label}: <span class="num">${money(a)}</span> ${ok ? okText : `vs <span class="num">${money(b)}</span>`}</span>`);
        };

        if (counted) rows.push(`<span class="k">Line items sum <span class="num">${money(sum)}</span></span>`);
        if (counted && subtotal !== null) flag('Items vs subtotal', Math.round(sum * 100) / 100, subtotal, 'matches');
        if (subtotal !== null && tax !== null && total !== null) {
            const label = discount || shipping ? 'Subtotal − discount + shipping + tax vs total' : 'Subtotal + tax vs total';
            flag(label, Math.round((subtotal - discount + shipping + tax) * 100) / 100, total, 'matches');
        }
        strip.innerHTML = rows.length ? rows.join('') : '<span class="k">Enter amounts to see automatic cross-checks.</span>';
    };

    const markDirty = () => {
        if (saveBtn) saveBtn.disabled = false;
    };

    if (editable) {
        $('#addLineItem')?.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            body.appendChild(fragment);
            reindex();
            const last = $$('tr.li-row', body).pop();
            $('input', last).focus();
            markDirty();
            recalc();
        });

        body.addEventListener('click', (e) => {
            const button = e.target.closest('[data-remove-row]');
            if (!button) return;
            button.closest('tr').remove();
            reindex();
            markDirty();
            recalc();
        });

        form.addEventListener('input', () => {
            markDirty();
            recalc();
        });

        window.addEventListener('beforeunload', (e) => {
            if (saveBtn && !saveBtn.disabled && !form.dataset.submitting) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        form.addEventListener('submit', () => {
            form.dataset.submitting = '1';
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';
            }
        });
    }

    reindex();
    recalc();
}

/* -------------------------------------------------- Preview / text tabs */
function initTabsMemory() {
    $$('[data-bs-toggle="tab"]').forEach((tab) => {
        tab.addEventListener('shown.bs.tab', () => {
            try {
                sessionStorage.setItem('ledgerly.tab', tab.dataset.bsTarget);
            } catch (_) {}
        });
    });
}

/* -------------------------------------------------- Sidebar (mobile) */
function initSidebar() {
    const el = $('#sidebar');
    if (!el) return;
    $$('a.side-link', el).forEach((a) => a.addEventListener('click', () => {
        const instance = bootstrap.Offcanvas.getInstance(el);
        if (instance) instance.hide();
    }));
}

/* --------------------------------------------------------- Demo logins */
function initDemoLogins() {
    $$('[data-demo-login]').forEach((button) => {
        button.addEventListener('click', () => {
            $('#email').value = button.dataset.demoLogin;
            $('#password').value = 'password';
            $('#password').focus();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initToasts();
    initConfirm();
    initFilters();
    initBulk();
    initDropzone();
    initDocumentEditor();
    initTabsMemory();
    initSidebar();
    initDemoLogins();
    $$('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));
});
