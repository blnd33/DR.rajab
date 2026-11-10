<?php

use App\Enums\AssetMovementType;
use App\Enums\CustomerType;
use App\Enums\InstrumentStatus;
use App\Enums\Role;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Kits\Pages\ViewKit;
use App\Http\Controllers\LabelPrintController;
use App\Livewire\Ops\Clinical\KitShow;
use App\Models\AssetMovement;
use App\Models\Customer;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\KitType;
use App\Models\Product;
use App\Services\Assets\InstrumentService;
use App\Services\Assets\KitService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * "Fill from its list" (kits): every missing instrument of the kit type's list goes into the
 * kit — free instruments on the branch shelf first, then new ones with their own code — and
 * labels for instruments and kits.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->kits = app(KitService::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk]);

    $this->guide = InstrumentType::query()->create(['code' => '9013.50.000', 'name' => ['en' => 'Cutting guide']]);
    $this->reamer = InstrumentType::query()->create(['code' => '9013.52.046', 'name' => ['en' => 'Reamer 46']]);
    $implant = Product::factory()->create(['sku' => 'KN-1']);
    $screw = Product::factory()->lot()->create(['sku' => 'SC-1']);

    $this->type = KitType::query()->create(['code' => 'KNEE', 'name' => ['en' => 'Knee kit']]);
    $this->type->templateItems()->createMany([
        ['instrument_type_id' => $this->guide->id, 'expected_qty' => 1],
        ['instrument_type_id' => $this->reamer->id, 'expected_qty' => 3],
        ['product_id' => $implant->id, 'expected_qty' => 2],
        ['product_id' => $screw->id, 'expected_qty' => 10, 'is_required' => false],
    ]);

    // One guide already waits on the DHK shelf; one more sits in Baghdad (not usable here).
    $this->shelfGuide = app(InstrumentService::class)->create($this->dhk, ['instrument_type_id' => $this->guide->id], $this->admin);
    app(InstrumentService::class)->create($this->bgw, ['instrument_type_id' => $this->guide->id], userWithRole(Role::SuperAdmin, [$this->bgw]));

    $this->kit = $this->kits->create($this->dhk, ['kit_type_id' => $this->type->id], $this->admin);
});

it('plans the fill: shelf first, then new instruments; implants only counted', function () {
    $plan = $this->kits->fillPlan($this->kit);

    expect($plan['reuse'])->toBe(1)
        ->and($plan['register'])->toBe(3)
        ->and($plan['products_missing'])->toBe(2) // the optional screws do not count
        ->and(collect($plan['lines'])->firstWhere('instrument_type_id', $this->guide->id))
        ->toMatchArray(['expected' => 1, 'present' => 0, 'reuse' => 1, 'register' => 0])
        ->and(collect($plan['lines'])->firstWhere('instrument_type_id', $this->reamer->id))
        ->toMatchArray(['expected' => 3, 'present' => 0, 'reuse' => 0, 'register' => 3, 'label' => '9013.52.046 · Reamer 46']);
});

it('fills the kit from its list with a ledger row per instrument', function () {
    $result = $this->kits->fillFromList($this->kit, $this->staff);

    $inKit = Instrument::query()->where('kit_id', $this->kit->id)->orderBy('code')->get();

    expect($result['added'])->toBe(4)
        ->and($result['reused'])->toBe(1)
        ->and($result['registered'])->toHaveCount(3)
        ->and($inKit->pluck('code')->all())->toBe(['INS-DHK-0001', 'INS-DHK-0002', 'INS-DHK-0003', 'INS-DHK-0004'])
        ->and($inKit->every(fn (Instrument $i) => $i->status === InstrumentStatus::InKit && $i->branch_id === $this->dhk->id))->toBeTrue()
        ->and(AssetMovement::query()->where('type', AssetMovementType::AddToKit)->count())->toBe(4)
        ->and(collect($this->kits->compare($this->kit)['lines'])->where('kind', 'instrument')->sum('missing'))->toBe(0)
        ->and($this->kits->compare($this->kit)['complete'])->toBeFalse(); // the implants are still to be scanned

    expect(fn () => $this->kits->fillFromList($this->kit, $this->staff))
        ->toThrow(StockRuleViolation::class, 'Nothing to add');
});

it('only adds what is missing', function () {
    $this->kits->addInstrument($this->kit, $this->shelfGuide, $this->staff);
    $reamer = app(InstrumentService::class)->create($this->dhk, ['instrument_type_id' => $this->reamer->id], $this->admin);
    $this->kits->addInstrument($this->kit, $reamer, $this->staff);

    $result = $this->kits->fillFromList($this->kit, $this->staff);

    expect($result)->toMatchArray(['added' => 2, 'reused' => 0])
        ->and(Instrument::query()->where('kit_id', $this->kit->id)->count())->toBe(4);
});

it('refuses a kit that is out, and people without the permission', function () {
    expect(fn () => $this->kits->fillFromList($this->kit, userWithRole(Role::Doctor, [$this->dhk])))
        ->toThrow(AuthorizationException::class);

    $this->kits->checkout($this->kit, Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]), $this->staff);

    expect(fn () => $this->kits->fillFromList($this->kit->fresh(), $this->staff))->toThrow(StockRuleViolation::class)
        ->and(Instrument::query()->where('kit_id', $this->kit->id)->count())->toBe(0);
});

it('prints labels for instruments and kits, only for people who may see them', function () {
    $ids = $this->kits->fillFromList($this->kit, $this->staff)['registered'];

    $this->actingAs($this->staff)->get(LabelPrintController::url('instrument', $ids))
        ->assertOk()->assertSee('INS-DHK-0002')->assertSee('REF 9013.52.046')->assertSee('<svg', false);
    $this->actingAs($this->staff)->get(LabelPrintController::url('kit', [$this->kit->id]))
        ->assertOk()->assertSee('KIT-DHK-0001')->assertSee('Knee kit');

    $this->actingAs(userWithRole(Role::BranchStaff, [$this->bgw]))->get(LabelPrintController::url('instrument', $ids))->assertForbidden();
});

it('fills from the admin kit page and offers the new labels', function () {
    $preview = view('filament.kits.fill-plan', ['plan' => $this->kits->fillPlan($this->kit), 'canRegister' => true])->render();

    expect($preview)->toContain('9013.52.046 · Reamer 46')
        ->toContain('In total: 1 from the shelf, 3 registered new.')
        ->toContain('Implants are not added this way: 2 still missing');

    Livewire::actingAs($this->admin)->test(ViewKit::class, ['record' => $this->kit->getRouteKey()])
        ->callAction('fill')
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Instrument::query()->where('kit_id', $this->kit->id)->count())->toBe(4);
});

it('fills from the phone kit screen', function () {
    $screen = Livewire::actingAs($this->staff)->test(KitShow::class, ['kit' => $this->kit])
        ->call('setMode', 'prepare')
        ->assertSee('Fill from its list')
        ->call('fillFromList');

    expect(Instrument::query()->where('kit_id', $this->kit->id)->count())->toBe(4)
        ->and($screen->get('labelsUrl'))->toContain('kind=instrument');

    $screen->assertSee('Print labels for the new instruments')->assertDontSee('Fill the kit');
});
