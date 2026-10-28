{{-- Ledgerly mark: three offset ledger sheets with a brass folded corner. Drawn to match the approved Canva logo (public/brand). --}}
@php($tone = $tone ?? 'light')
<svg width="{{ $size ?? 30 }}" height="{{ $size ?? 30 }}" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path d="M0 11.24 4.34 5.05H27.13V11.24Z" fill="{{ $tone === 'dark' ? '#EDE6D6' : '#245B54' }}"/>
    <path d="M0 19.15 4.34 13.08H32L27.88 19.15Z" fill="{{ $tone === 'dark' ? '#EDE6D6' : '#245B54' }}"/>
    <path d="M4.88 21H22.46L27.34 26.97H4.88Z" fill="{{ $tone === 'dark' ? '#EDE6D6' : '#245B54' }}"/>
    <path d="M22.46 21H32L27.34 26.97Z" fill="#C49A3F"/>
</svg>
