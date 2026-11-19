<?php

use App\Enums\CustomerType;
use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\Role;
use App\Filament\Admin\Pages\QuickAnswersPage;
use App\Livewire\Ops\Ask;
use App\Models\Customer;
use App\Models\InstrumentType;
use App\Models\KitType;
use App\Models\Product;
use App\Models\StockUnit;
use App\Models\UnrecognizedScan;
use App\Models\User;
use App\Services\Assets\KitService;
use App\Services\Clinical\PatientService;
use App\Services\Insight\KeywordMatcher;
use App\Services\Insight\QuickAnswers;
use App\Services\Insight\QuickQuestions;
use App\Services\Issues\IssueService;
use App\Services\Movement\TransferService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;

/*
 * Quick answers (Phase 6): ready-made questions and "type or scan anything" — no AI; every
 * answer comes from the facts and the existing lookups, limited to what the person may see.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->answers = app(QuickAnswers::class);
    $this->hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);

    // Wednesday 11 November 2026, 10:00 in Baghdad.
    $this->travelTo(Carbon::parse('2026-11-11 10:00', 'Asia/Baghdad'));
});

/**
 * @return list<string> question keys in the menu
 */
function menuKeys(User $user): array
{
    return collect(app(QuickAnswers::class)->menu($user))->flatMap(fn ($group) => array_column($group['questions'], 'key'))->all();
}

it('offers each person only the questions their permissions answer', function () {
    $doctor = userWithRole(Role::Doctor, [$this->dhk]);
    $cashier = userWithRole(Role::Cashier, [$this->dhk]);

    expect(menuKeys($this->admin))->toContain('today', 'expiring-30', 'item', 'kits-overdue', 'kit-complete', 'surgeries-doctor', 'sales', 'profit', 'issues')
        ->and(menuKeys($doctor))->toContain('kits-out', 'surgeries-hospital')->not->toContain('sales', 'profit', 'cash')
        ->and(menuKeys($cashier))->toContain('cash', 'payments')->not->toContain('profit', 'kits-out');

    expect($this->answers->ask($doctor, 'profit')['text'])->toBe('This question is not available to you.');

    SpatieRole::findByName(Role::Cashier->value)->revokePermissionTo('assistant.use');
    expect(QuickAnswers::canUse($cashier->fresh()))->toBeFalse();
});

it('answers a question with cards for the chosen period and branch', function () {
    $kits = app(KitService::class);
    $type = KitType::query()->create(['code' => 'KNEE', 'name' => ['en' => 'Knee kit']]);
    $late = $kits->create($this->dhk, ['kit_type_id' => $type->id], $this->admin);
    $onTime = $kits->create($this->dhk, ['kit_type_id' => $type->id], $this->admin);
    $kits->checkout($late, $this->hospital, $this->admin)->forceFill(['due_back_at' => now()->subDays(3)])->save();
    $kits->checkout($onTime, $this->hospital, $this->admin)->forceFill(['due_back_at' => now()->addDays(2)])->save();

    $all = $this->answers->ask($this->admin, 'kits-out');
    $overdue = $this->answers->ask($this->admin, 'kits-overdue');

    expect($all['cards'][0]['headline'])->toBe('2')
        ->and($overdue['cards'][0]['headline'])->toBe('1')
        ->and($overdue['cards'][0]['lines'][0]['label'])->toContain($late->code)
        ->and($overdue['period'])->toBeNull(); // how things stand now

    $sales = $this->answers->ask($this->admin, 'sales', ['period' => 'week']);
    expect($sales['period'])->toBe('2026-11-07 – 2026-11-11'); // the week starts on Saturday

    $elsewhere = $this->answers->ask($this->admin, 'kits-out', ['branch' => $this->ebl->id]);
    expect($elsewhere['cards'][0]['headline'])->toBe('0');
});

it('tells what expires in the next 30 / 90 days, soonest first', function () {
    $product = Product::factory()->create(['sku' => 'KN-1', 'name' => ['en' => 'Knee insert']]);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $product->id, 'serial' => 'SN-20', 'expiry_date' => '2026-12-01'],
        ['product_id' => $product->id, 'serial' => 'SN-80', 'expiry_date' => '2027-01-25'],
        ['product_id' => $product->id, 'serial' => 'SN-400', 'expiry_date' => '2027-12-01'],
    ]);

    $soon = $this->answers->ask($this->admin, 'expiring-30')['cards'][0];
    $later = $this->answers->ask($this->admin, 'expiring-90')['cards'][0];

    expect($soon['headline'])->toBe('1')
        ->and($soon['lines'][0]['label'])->toBe('KN-1 · Knee insert · 2026-12-01')
        ->and($later['headline'])->toBe('2');
});

it('answers today and yesterday like the morning summary, and asks for a code when needed', function () {
    app(IssueService::class)->open(IssueType::CashDifference, IssueSeverity::Warning, $this->dhk->id, null, 'Duhok difference');

    $today = $this->answers->ask($this->admin, 'today');
    expect($today['period'])->toBe('2026-11-11')
        ->and(collect($today['cards'])->pluck('key'))->toContain('open-issues')->not->toContain('sales');

    $prompt = $this->answers->ask($this->admin, 'serial');
    expect($prompt['text'])->toBe('Scan or type the serial number.')
        ->and($prompt['focus'])->toBeTrue();
});

it('finds a serial, a product and a lot, with where and how many', function () {
    $implant = Product::factory()->create(['sku' => 'KN-1', 'name' => ['en' => 'Knee insert']]);
    $screw = Product::factory()->lot()->create(['sku' => 'SC-1', 'name' => ['en' => 'Screw']]);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $implant->id, 'serial' => 'SN-1', 'lot' => 'LOT-9', 'expiry_date' => '2027-03-01'],
        ['product_id' => $implant->id, 'serial' => 'SN-2', 'lot' => 'LOT-9', 'expiry_date' => '2027-05-01'],
        ['product_id' => $screw->id, 'lot' => 'L-77', 'quantity' => 12],
    ]);

    $serial = $this->answers->lookup($this->admin, 'SN-1')['cards'][0];
    expect($serial['title'])->toBe('Serial SN-1')
        ->and($serial['headline'])->toBe(StockUnit::query()->where('serial', 'SN-1')->sole()->status->getLabel())
        ->and(collect($serial['lines'])->pluck('value'))->toContain('LOT-9', '2027-03-01');

    $product = $this->answers->lookup($this->admin, 'kn-1')['cards'][0];
    expect($product['headline'])->toBe('2')
        ->and($product['lines'][0])->toMatchArray(['label' => 'DHK', 'value' => '2'])
        ->and(collect($product['lines'])->pluck('value'))->toContain('2027-03-01');

    $lot = $this->answers->lookup($this->admin, 'L-77')['cards'][0];
    expect($lot['headline'])->toBe('12');

    // Someone without stock in that branch sees only where the serial is.
    $ebl = userWithRole(Role::BranchStaff, [$this->ebl]);
    expect($this->answers->lookup($ebl, 'SN-1')['cards'][0]['lines'])->toBe([['label' => 'Station', 'value' => 'DHK']]);
});

it('tells whether a kit is complete, and finds documents and patient files the person may see', function () {
    $guide = InstrumentType::query()->create(['code' => 'G-1', 'name' => ['en' => 'Cutting guide']]);
    $type = KitType::query()->create(['code' => 'KNEE', 'name' => ['en' => 'Knee kit']]);
    $type->templateItems()->create(['instrument_type_id' => $guide->id, 'expected_qty' => 2]);
    $kit = app(KitService::class)->create($this->dhk, ['kit_type_id' => $type->id], $this->admin);

    $card = $this->answers->lookup($this->admin, $kit->code)['cards'][0];
    expect($card['headline'])->toBe('2 items missing')
        ->and($card['tone'])->toBe('warning');

    $implant = Product::factory()->create(['sku' => 'KN-1']);
    stockIn($this->dhk, $this->admin, [['product_id' => $implant->id, 'serial' => 'SN-1']]);
    $transfers = app(TransferService::class);
    $transfer = $transfers->createDraft($this->dhk, $this->ebl, $this->admin);
    $transfers->addUnit($transfer, StockUnit::query()->sole(), $this->admin);

    $this->actingAs($this->admin);
    $doc = $this->answers->lookup($this->admin, mb_strtolower($transfer->number))['cards'][0];
    expect($doc['headline'])->toBe($transfer->number)
        ->and($doc['url'])->toContain('/admin/transfers/'.$transfer->id);

    $outsider = userWithRole(Role::BranchStaff, [branch('BGW')]);
    $this->actingAs($outsider);
    expect($this->answers->lookup($outsider, $transfer->number)['cards'])->toBe([]);

    $doctor = userWithRole(Role::Doctor, [$this->dhk]);
    $patient = app(PatientService::class)->create($this->dhk, ['full_name' => 'Sara Ali', 'age' => 40, 'gender' => 'female'], $doctor);
    expect($this->answers->lookup($doctor, $patient->file_number)['cards'][0]['headline'])->toBe('Sara Ali')
        ->and($this->answers->lookup(userWithRole(Role::Doctor, [$this->dhk]), $patient->file_number)['cards'])->toBe([]);
});

it('says when nothing has a code, without saving it as an unknown scan', function () {
    $answer = $this->answers->lookup($this->admin, 'ZZ-404-NOPE');

    expect($answer['cards'])->toBe([])
        ->and($answer['text'])->toBe('Nothing has the code ZZ-404-NOPE. I can answer these:')
        ->and($answer['suggestions'])->not->toBeEmpty()
        ->and(UnrecognizedScan::query()->count())->toBe(0);
});

it('points typed words to questions in English, Arabic and Kurdish', function (string $text, string $first) {
    $answer = $this->answers->lookup($this->admin, $text);

    expect($answer['text'])->toBe('Did you mean:')
        ->and($answer['suggestions'][0]['key'])->toBe($first);
})->with([
    ['what expires in 90 days', 'expiring-90'],
    ['overdue kits', 'kits-overdue'],
    ['انتهاء الصلاحية', 'expiring-30'],
    ['مبيعات الشهر', 'sales'],
    ['النقد في الصندوق', 'cash'],
    ['کیتی دواکەوتوو', 'kits-overdue'],
    ['خەرجییەکان', 'expenses'],
]);

it('folds Arabic and Kurdish spellings and ignores words it does not know', function () {
    expect(KeywordMatcher::normalize('مبيعاتي'))->toBe(KeywordMatcher::normalize('مبیعاتی'))
        ->and($this->answers->lookup($this->admin, 'hello there')['text'])->toBe('I did not understand. I can answer these:');
});

it('runs as a chat on the admin page and on the phone', function () {
    Livewire::actingAs($this->admin)->test(QuickAnswersPage::class)
        ->assertSee('What happened today?')
        ->call('askQuestion', 'kits-out')
        ->assertCount('messages', 2)
        ->set('text', 'kits')
        ->call('send')
        ->assertCount('messages', 4)
        ->assertSet('text', '')
        ->assertSee('Did you mean:')
        ->call('clearChat')
        ->assertCount('messages', 0);

    $staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->actingAs($staff)->get(route('ops.ask'))->assertOk()->assertSee('Quick answers');
    $this->actingAs($staff)->get(route('ops.home'))->assertOk()->assertSee(route('ops.ask'), false);

    Livewire::actingAs($staff)->test(Ask::class)
        ->call('handleScan', 'NOPE-123')
        ->assertCount('messages', 2)
        ->set('period', 'forever')
        ->assertSet('period', 'month');

    SpatieRole::findByName(Role::BranchStaff->value)->revokePermissionTo('assistant.use');
    $this->actingAs($staff->fresh())->get(route('ops.ask'))->assertForbidden();
    expect(QuickAnswersPage::canAccess())->toBeFalse();
});

it('keeps the question list consistent', function () {
    $questions = app(QuickQuestions::class)->all();

    expect(collect($questions)->pluck('key')->duplicates())->toBeEmpty();

    foreach ($questions as $question) {
        expect(array_key_exists($question->group, QuickQuestions::groups()))->toBeTrue($question->key)
            ->and($question->fact !== null || $question->isDay() || $question->isPrompt())->toBeTrue($question->key);
    }
});
