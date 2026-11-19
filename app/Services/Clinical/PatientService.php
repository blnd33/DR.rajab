<?php

namespace App\Services\Clinical;

use App\Enums\Gender;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\DigitConverter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Patient files (Module 12, P3-1): sensitive data, own patients for doctors unless
 * granted more, every opening of a file in the activity log.
 */
class PatientService
{
    public function __construct(
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{full_name: string, phone?: ?string, date_of_birth?: ?string, birth_year?: ?int, age?: ?int, gender?: ?string, notes?: ?string, file_number?: ?string, primary_doctor_id?: ?int}  $data
     */
    public function create(Branch $branch, array $data, User $by): Patient
    {
        $this->authorize($by, $branch);
        $data = $this->clean($data);

        return DB::transaction(function () use ($branch, $data, $by) {
            $patient = new Patient($data);
            $patient->branch_id = $branch->id;
            $patient->file_number = filled($data['file_number'] ?? null)
                ? mb_strtoupper(trim($data['file_number']))
                : $this->numbers->next('PAT', $branch);
            // The doctor who registers a patient is the primary doctor by default.
            $patient->primary_doctor_id ??= $this->scope->hasAny($by, 'surgeries.create') ? $by->id : null;
            $patient->save();

            return $patient;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Patient $patient, array $data, User $by): Patient
    {
        $this->authorize($by, $patient->branch_id);

        if (! $this->canSee($by, $patient)) {
            throw new AuthorizationException(__('This patient is not one of yours.'));
        }

        $patient->fill($this->clean($data))->save();

        return $patient;
    }

    /**
     * A patient with the same phone (and, when given, the same name) — used to avoid
     * duplicates when an offline surgery brings a "new" patient.
     */
    public function findByPhone(?string $phone, ?string $name = null): ?Patient
    {
        $hash = $this->phoneHash($phone);

        if ($hash === null) {
            return null;
        }

        return Patient::query()
            ->where('phone_hash', $hash)
            ->when(filled($name), fn ($q) => $q->whereRaw('LOWER(full_name) = ?', [mb_strtolower(trim((string) $name))]))
            ->first();
    }

    /**
     * Patients the user may see: all in scope with patients.view_all, otherwise their own
     * (primary doctor, or a doctor of one of the patient's surgeries).
     *
     * @return Builder<Patient>
     */
    public function visibleTo(User $user): Builder
    {
        $query = Patient::query();
        $all = $this->scope->branchIdsFor($user, 'patients.view_all');

        if ($all === null) {
            return $query;
        }

        $own = fn (Builder $q) => $q->where('primary_doctor_id', $user->id)
            ->orWhereHas('surgeries.doctors', fn ($d) => $d->where('users.id', $user->id))
            ->orWhereHas('surgeries', fn ($s) => $s->where('created_by', $user->id));

        $ownAllowed = $this->scope->hasAny($user, 'patients.view_own');

        return $query->where(function (Builder $q) use ($all, $own, $ownAllowed) {
            $q->whereRaw('1 = 0');

            if ($all !== []) {
                $q->orWhereIn('branch_id', $all);
            }

            if ($ownAllowed) {
                $q->orWhere($own);
            }
        });
    }

    public function canSee(User $user, Patient $patient): bool
    {
        return $this->visibleTo($user)->whereKey($patient->id)->exists();
    }

    /**
     * Opening a patient file is logged (sensitive data).
     */
    public function logView(Patient $patient, User $user): void
    {
        activity('patients')
            ->causedBy($user)
            ->performedOn($patient)
            ->event('viewed')
            ->log('viewed');
    }

    /**
     * Search by file number, name or phone, limited to what the user may see.
     *
     * @return Builder<Patient>
     */
    public function search(User $user, string $term): Builder
    {
        $term = trim((string) DigitConverter::toWestern($term));
        $hash = $this->phoneHash($term);

        return $this->visibleTo($user)->where(function (Builder $q) use ($term, $hash) {
            $q->where('file_number', 'like', '%'.mb_strtoupper($term).'%')
                ->orWhereRaw('LOWER(full_name) LIKE ?', ['%'.mb_strtolower($term).'%']);

            if ($hash !== null) {
                $q->orWhere('phone_hash', $hash);
            }
        });
    }

    public function phoneHash(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) DigitConverter::toWestern($phone));

        if ($digits === null || strlen($digits) < 6) {
            return null;
        }

        // Iraqi numbers are written with 0 or +964 in front; store one form.
        $digits = preg_replace('/^(00964|964|0)/', '', $digits) ?? $digits;

        return hash_hmac('sha256', $digits, (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        if (blank($data['full_name'] ?? null) && array_key_exists('full_name', $data)) {
            throw new StockRuleViolation(__('The patient name is required.'), 'full_name');
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = filled($data['phone']) ? trim((string) DigitConverter::toWestern((string) $data['phone'])) : null;
            $data['phone_hash'] = $this->phoneHash($data['phone']);
        }

        if (filled($data['age'] ?? null) && blank($data['birth_year'] ?? null) && blank($data['date_of_birth'] ?? null)) {
            $data['birth_year'] = (int) now()->format('Y') - (int) $data['age'];
        }

        if (filled($data['gender'] ?? null)) {
            $data['gender'] = Gender::from((string) $data['gender']);
        }

        unset($data['age']);

        return array_intersect_key($data, array_flip([
            'full_name', 'phone', 'phone_hash', 'date_of_birth', 'birth_year', 'gender', 'notes', 'file_number', 'primary_doctor_id',
        ]));
    }

    private function authorize(User $by, Branch|int $branch): void
    {
        if (! $this->scope->allows($by, 'patients.manage', $branch)) {
            throw new AuthorizationException(__('You are not allowed to manage patients in this station.'));
        }
    }
}
