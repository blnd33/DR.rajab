<?php

namespace App\Services\Agents;

use App\Enums\AgentType;
use App\Enums\AttachmentKind;
use App\Models\CompanyAgent;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Company agents kept by the super admin (permission agents.manage). Only the information of
 * the agent's type is kept (AgentType::fields()); the rest is emptied. Nothing is deleted:
 * an agent who leaves is made inactive. A new CV is added next to the old ones.
 */
class CompanyAgentService
{
    public const CV_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(AgentType $type, array $data, User $by, ?UploadedFile $cv = null): CompanyAgent
    {
        $this->authorize($by);

        if ($type === AgentType::Technician) {
            throw new \LogicException('Technicians are added as technician accounts; use saveTechnicianProfile().');
        }

        return DB::transaction(function () use ($type, $data, $by, $cv) {
            $agent = CompanyAgent::query()->create(['type' => $type] + $this->clean($type, $data));
            $this->attachCv($agent, $cv, $by);

            return $agent;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CompanyAgent $agent, array $data, User $by, ?UploadedFile $cv = null): CompanyAgent
    {
        $this->authorize($by);

        return DB::transaction(function () use ($agent, $data, $by, $cv) {
            $clean = $this->clean($agent->type, $data, $agent);

            if ($agent->type === AgentType::Technician) {
                // Name, phone and the account come from the technician account.
                unset($clean['name'], $clean['phone'], $clean['user_id']);
            }

            $agent->update($clean);
            $this->attachCv($agent, $cv, $by);

            return $agent->refresh();
        });
    }

    /**
     * CV, position and salary of a technician (kept next to their technician account).
     *
     * @param  array<string, mixed>  $data
     */
    public function saveTechnicianProfile(User $technician, array $data, User $by, ?UploadedFile $cv = null): CompanyAgent
    {
        $this->authorize($by);
        abort_unless($technician->isTechnician(), 404);

        return DB::transaction(function () use ($technician, $data, $by, $cv) {
            $clean = $this->clean(AgentType::Technician, $data + ['name' => $technician->name]);
            $agent = CompanyAgent::query()->firstOrNew(['user_id' => $technician->id], ['type' => AgentType::Technician]);
            $agent->fill([
                ...$clean,
                'user_id' => $technician->id,
                'type' => AgentType::Technician,
                'name' => $technician->name,
                'phone' => $technician->phone,
                'is_active' => $technician->is_active,
            ])->save();
            $this->attachCv($agent, $cv, $by);

            return $agent;
        });
    }

    /**
     * Keep a technician's profile in step with the account (name, phone, active).
     */
    public function syncTechnician(User $technician): void
    {
        CompanyAgent::query()->where('user_id', $technician->id)->where('type', AgentType::Technician->value)
            ->first()?->update(['name' => $technician->name, 'phone' => $technician->phone, 'is_active' => $technician->is_active]);
    }

    public function attachCv(CompanyAgent $agent, ?UploadedFile $file, User $by): void
    {
        if ($file === null) {
            return;
        }

        if (! in_array($file->getMimeType(), self::CV_TYPES, true)) {
            throw ValidationException::withMessages(['cv' => __('The CV must be a PDF, a Word file or a photo.')]);
        }

        $path = $file->store('agents/'.$agent->id, 'local');

        $agent->attachments()->create([
            'kind' => AttachmentKind::Cv,
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'mime' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * The columns this type keeps, cleaned; the others are emptied.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function clean(AgentType $type, array $data, ?CompanyAgent $existing = null): array
    {
        $text = fn (string $key, int $max) => filled($data[$key] ?? null) ? mb_substr(trim((string) $data[$key]), 0, $max) : null;

        $name = $text('name', 150);

        if ($name === null) {
            throw ValidationException::withMessages(['name' => __('Enter the name.')]);
        }

        $clean = [
            'name' => $name,
            'phone' => $text('phone', 32),
            'notes' => $text('notes', 5000),
            'is_active' => (bool) ($data['is_active'] ?? $existing->is_active ?? true),
            'city' => $type->has('city') ? $text('city', 120) : null,
            'persons' => $type->has('persons') ? $text('persons', 5000) : null,
            'stock' => $type->has('stock') ? $text('stock', 255) : null,
            'position' => $type->has('position') ? $text('position', 120) : null,
            'shares_percent' => $type->has('shares') ? $this->percent($data['shares_percent'] ?? null) : null,
            'user_id' => $type->has('user') ? $this->linkedUser($data['user_id'] ?? null, $existing) : null,
        ];

        foreach (['value' => 'value', 'salary' => 'salary', 'mission_fee' => 'mission_fee'] as $field => $prefix) {
            [$clean["{$prefix}_amount"], $clean["{$prefix}_currency"]] = $type->has($field)
                ? $this->money($data["{$prefix}_amount"] ?? null, $data["{$prefix}_currency"] ?? null, "{$prefix}_amount", "{$prefix}_currency")
                : [null, null];
        }

        $month = $year = null;

        if ($type->has('profit')) {
            [$month] = $this->money($data['profit_month_amount'] ?? null, $data['profit_currency'] ?? null, 'profit_month_amount', 'profit_currency');
            [$year] = $this->money($data['profit_year_amount'] ?? null, $data['profit_currency'] ?? null, 'profit_year_amount', 'profit_currency');
            // Left empty: a year is twelve months.
            $year ??= $month !== null ? (string) BigDecimal::of($month)->multipliedBy(12)->toScale(4, RoundingMode::HalfUp) : null;
        }

        $clean['profit_month_amount'] = $month;
        $clean['profit_year_amount'] = $year;
        $clean['profit_currency'] = $month !== null || $year !== null ? strtoupper(trim((string) $data['profit_currency'])) : null;

        return $clean;
    }

    public function authorize(User $by): void
    {
        if (! $by->can('agents.manage')) {
            throw new AuthorizationException(__('You are not allowed to manage company agents.'));
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} [amount, currency]
     */
    private function money(mixed $amount, mixed $currency, string $field, string $currencyField): array
    {
        if (blank($amount)) {
            return [null, null];
        }

        try {
            $value = BigDecimal::of(str_replace([',', ' '], '', (string) $amount))->toScale(4, RoundingMode::HalfUp);
        } catch (MathException) {
            throw ValidationException::withMessages([$field => __('":value" is not a valid amount.', ['value' => (string) $amount])]);
        }

        if ($value->isNegative()) {
            throw ValidationException::withMessages([$field => __('The amount cannot be below zero.')]);
        }

        $currency = strtoupper(trim((string) $currency));

        if (! in_array($currency, config('orthotrack.currencies'), true)) {
            throw ValidationException::withMessages([$currencyField => __('Choose the currency.')]);
        }

        return [(string) $value, $currency];
    }

    private function percent(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            $percent = BigDecimal::of(str_replace([',', ' ', '%'], '', (string) $value))->toScale(4, RoundingMode::HalfUp);
        } catch (MathException) {
            throw ValidationException::withMessages(['shares_percent' => __('The share is a percentage from 0 to 100.')]);
        }

        if ($percent->isNegative() || $percent->isGreaterThan(100)) {
            throw ValidationException::withMessages(['shares_percent' => __('The share is a percentage from 0 to 100.')]);
        }

        return (string) $percent;
    }

    private function linkedUser(mixed $userId, ?CompanyAgent $existing): ?int
    {
        if (blank($userId)) {
            return null;
        }

        $user = User::query()->find((int) $userId);

        if ($user === null || $user->isTechnician()) {
            throw ValidationException::withMessages(['user_id' => __('Choose a system account.')]);
        }

        $taken = CompanyAgent::query()->where('user_id', $user->id)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['user_id' => __('This account already belongs to another employee.')]);
        }

        return $user->id;
    }
}
