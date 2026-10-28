<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    private const MONEY = ['subtotal', 'tax', 'total', 'discount', 'shipping'];

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('document')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $normalise = fn ($v) => is_string($v) ? str_replace([',', ' '], '', trim($v)) : $v;
        $merge = [];
        foreach (self::MONEY as $field) {
            if ($this->has($field)) {
                $merge[$field] = $normalise($this->input($field));
            }
        }
        if ($this->has('currency')) {
            $merge['currency'] = strtoupper(trim((string) $this->input('currency')));
        }
        if (is_array($this->input('line_items'))) {
            $merge['line_items'] = array_values(array_map(function ($row) use ($normalise) {
                foreach (['quantity', 'unit_price', 'amount', 'tax_rate'] as $key) {
                    if (isset($row[$key])) {
                        $row[$key] = $normalise($row[$key]);
                    }
                }

                return $row;
            }, $this->input('line_items')));
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'between:-99999999999,99999999999'];

        return [
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'invoice_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'subtotal' => $money,
            'tax' => $money,
            'total' => $money,
            'discount' => $money,
            'shipping' => $money,
            'po_number' => ['nullable', 'string', 'max:80'],
            'supplier_address' => ['nullable', 'string', 'max:500'],
            'supplier_tax_id' => ['nullable', 'string', 'max:40'],
            'bill_to_name' => ['nullable', 'string', 'max:255'],
            'bill_to_address' => ['nullable', 'string', 'max:500'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:60'],
            'invoice_notes' => ['nullable', 'string', 'max:1000'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'gl_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\\-\\/ ]*$/'],
            'line_items' => ['nullable', 'array', 'max:200'],
            'line_items.*.sku' => ['nullable', 'string', 'max:60'],
            'line_items.*.description' => ['nullable', 'string', 'max:255'],
            'line_items.*.quantity' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'line_items.*.unit_price' => $money,
            'line_items.*.amount' => $money,
            'line_items.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_date.date_format' => 'Use the format YYYY-MM-DD for the invoice date.',
            'due_date.date_format' => 'Use the format YYYY-MM-DD for the due date.',
            'currency.regex' => 'Currency must be a 3-letter ISO code such as USD.',
            'gl_code.regex' => 'A GL code may contain letters, digits, dots, dashes, slashes and spaces.',
            '*.numeric' => 'Enter a valid number.',
        ];
    }
}
