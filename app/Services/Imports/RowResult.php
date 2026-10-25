<?php

namespace App\Services\Imports;

use App\Enums\ImportRowAction;

/**
 * Outcome of checking one spreadsheet row.
 */
final class RowResult
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, array{old: mixed, new: mixed}> */
    public array $diff = [];

    /** @var array<string, mixed> */
    public array $normalized = [];

    public ?string $targetType = null;

    public ?int $targetId = null;

    public bool $isNew = false;

    public function error(string $message): self
    {
        $this->errors[] = $message;

        return $this;
    }

    public function warn(string $message): self
    {
        $this->warnings[] = $message;

        return $this;
    }

    /**
     * Record old → new when the value really changes.
     */
    public function change(string $field, mixed $old, mixed $new): void
    {
        if ($this->comparable($old) !== $this->comparable($new)) {
            $this->diff[$field] = ['old' => $old, 'new' => $new];
        }
    }

    public function action(): ImportRowAction
    {
        return match (true) {
            $this->errors !== [] => ImportRowAction::Error,
            $this->isNew => ImportRowAction::Create,
            $this->diff !== [] => ImportRowAction::Update,
            default => ImportRowAction::Unchanged,
        };
    }

    private function comparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }
}
