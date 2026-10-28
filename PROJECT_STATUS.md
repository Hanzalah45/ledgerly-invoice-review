# Project 1: Ledgerly (AI Document Intelligence) — Status

**Folder:** `C:\laragon\www\corehives-doc-ai`  
**Status:** Complete for its scope (see limitations). Last verified: 2026-10-28.

## What works (verified)

- Sign in / sign out, three roles with policy-enforced permissions
- Dashboard (KPIs, 14-day chart, status breakdown, approved value by currency, top suppliers, attention list)
- Upload (client + server validation), processing, document list (search, status tabs, source / validation / assignee / date filters, sorting, pagination)
- Review screen: PDF preview, extracted text, editable fields and line items (with live cross-checks), validation panel, PO match panel, notes, history
- **Order and billing details**: PO number, supplier address and tax ID, bill-to, payment terms, discount, shipping, bank account (masked outside the edit form and exports), notes, line SKU and tax rate
- **Purchase-order register** (manual and CSV import) and PO matching, including cumulative invoicing against one PO
- **Workspace branding**: logo upload (validated, SVG refused), company details, shown in the sidebar and on the **approval record PDF**
- **Approval limit** for reviewers, **assignment**, **reviewer notes**, **bulk actions** (approve, reject, assign, reprocess, export selected, delete), **suppliers directory**
- Approve / reject / reopen / reprocess / delete with confirmation dialogs
- CSV and JSON exports (filter-aware, approved by default, demo records labelled, bank masked, CSV formula injection neutralised)
- Activity log, admin settings (workspace, approval rules, provider status, limits, members)
- Demo parser (local rules) clearly labelled as not AI; OpenAI and Anthropic adapters behind one interface

## Tests actually run

| Suite | Result |
|---|---|
| `php artisan test` | **169 passed** (0 failed) |
| Browser end-to-end (`docs/e2e-results.txt`, Chrome via Playwright) | **81 / 81 checks passed** |

The browser run found and fixed one real bug (a JavaScript error on the document list for read-only users, whose page has no bulk-selection controls).
A portfolio screenshot review later found a broken Blade directive in the approval chain panel (it printed "waiting@else later" instead of the step state); fixed and covered by an assertion in `EnterpriseApprovalTest`.
Two earlier bugs were found by the demo-parser tests: a line item called "Delivery" being read as the shipping charge, and the
pagination collection error on the list page. 403 responses produced by deliberate permission checks appear in the browser
console and are excluded from the "no console errors" check.

## Screenshots (real captures)

`docs/screenshots/` — login, dashboard, documents, upload, review (PO exceeded, totals mismatch, approved, failed scan), exports,
activity, settings (with logo and approval rules), purchase orders, suppliers, and three mobile pages. No demo video was recorded.

## Known limitations

- Scans: image-only PDFs are read by a live provider (OpenAI/Anthropic) sent as a file; in demo mode they fail with a clear message (shown in the seed data). Verified with HTTP-faked tests and one real call on a synthetic scan (gpt-4o correct; gpt-4o-mini misread a date and PO on a lower-resolution scan). No JPG/PNG upload, no local OCR engine, accuracy on real-world scans is not proven.
- Demo parser is rule-based and tuned for clean text PDFs; not representative of real-world invoice variety. Extraction of the supplier's logo from a PDF is not attempted.
- OpenAI/Anthropic adapters are tested with faked HTTP only; never run against live APIs (no keys in this repository).
- The approval limit and PO comparison use amounts as printed; there is no currency conversion.
- The PDF itself and the "Extracted text" tab show the bank account exactly as printed; masking applies to the form for read-only states, lists and exports.
- Ambiguous slash dates are read US-style. No password reset / email, no dark mode, single workspace.

## Commands

```bash
php artisan migrate:fresh --seed   # reset demo data (includes a PO register, logo, notes, assignments)
php artisan serve                  # http://127.0.0.1:8000
npm run build                      # rebuild assets
php artisan test                   # backend tests
```

## Public demo mode

`DOCAI_PUBLIC_DEMO=true`: one-click reviewer login, password login off, demo parser forced, upload caps, `docai:demo-reset`. Tested by `PublicDemoTest` and `DemoResetTest`. The `Dockerfile`, `docker/entrypoint.sh` and `render.yaml` have **not been built or run** (no Docker on the build machine). See `docs/DEPLOY_DEMO.md`.

## Portfolio readiness

Strong. The depth of validation, PO matching, approval controls, tests and honest demo/live labelling are the selling points.
Before showing a client: record a short screen capture of upload → PO check → fix error → approve → download approval record,
and run one real extraction with your own API key so the live path is demonstrated, not only unit-tested.
