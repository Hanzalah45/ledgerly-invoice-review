<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case NeedsReview = 'needs_review'; // extracted, validation found errors or warnings
    case Ready = 'ready';              // extracted, validation clean, awaiting human approval
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Queued',
            self::Processing => 'Processing',
            self::NeedsReview => 'Needs review',
            self::Ready => 'Ready to approve',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    /** CSS modifier used by the .status-pill component. */
    public function tone(): string
    {
        return match ($this) {
            self::Uploaded, self::Processing => 'info',
            self::NeedsReview => 'warn',
            self::Ready => 'accent',
            self::Approved => 'ok',
            self::Rejected, self::Failed => 'bad',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::NeedsReview, self::Ready, self::Failed], true);
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
