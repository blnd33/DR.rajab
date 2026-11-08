<?php

use App\Enums\AttachmentKind;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Livewire\Ops\Movement\TransferShow;
use App\Models\Product;
use App\Models\StockUnit;
use App\Models\Transfer;
use App\Services\Movement\TransferService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * Transfer photos (client, 2026-11-08): the sending branch photographs the items before they
 * leave, the receiving branch photographs them on arrival; both are required and both are
 * shown on the transfer.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->transfers = app(TransferService::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->sender = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->receiver = userWithRole(Role::BranchStaff, [$this->bgw]);
    $implant = Product::factory()->create(['sku' => 'KN-1']);
    stockIn($this->dhk, $this->admin, [['product_id' => $implant->id, 'serial' => 'SN-1']]);
    $this->unit = StockUnit::query()->sole();

    $this->transfer = $this->transfers->createDraft($this->dhk, $this->bgw, $this->sender);
    $this->transfers->addUnit($this->transfer, $this->unit, $this->sender);
});

it('needs our photo to send and their photo to receive, and keeps both', function () {
    expect(fn () => $this->transfers->send($this->transfer, $this->sender))->toThrow(StockRuleViolation::class, 'photo');

    $this->transfers->send($this->transfer, $this->sender, photo: transferPhoto('ours.jpg'));

    expect(fn () => $this->transfers->receive($this->transfer->fresh(), [$this->unit->id], [], $this->receiver))->toThrow(StockRuleViolation::class, 'photo');

    $received = $this->transfers->receive($this->transfer->fresh(), [$this->unit->id], [], $this->receiver, photo: transferPhoto('theirs.jpg'));

    expect($received->status)->toBe(TransferStatus::Received)
        ->and($received->photo(AttachmentKind::TransferSent)?->original_name)->toBe('ours.jpg')
        ->and($received->photo(AttachmentKind::TransferSent)?->uploaded_by)->toBe($this->sender->id)
        ->and($received->photo(AttachmentKind::TransferReceived)?->original_name)->toBe('theirs.jpg')
        ->and($received->photo(AttachmentKind::TransferReceived)?->uploaded_by)->toBe($this->receiver->id);
});

it('lets only the right branch add each photo, at the right step', function () {
    // The receiver cannot add the "sent" photo, nor anyone add "received" to a draft.
    expect(fn () => $this->transfers->attachPhoto($this->transfer, transferPhoto(), AttachmentKind::TransferSent, $this->receiver))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->transfers->attachPhoto($this->transfer, transferPhoto(), AttachmentKind::TransferReceived, $this->receiver))->toThrow(StockRuleViolation::class, 'on its way');

    $this->transfers->send($this->transfer, $this->sender, photo: transferPhoto());

    expect(fn () => $this->transfers->attachPhoto($this->transfer->fresh(), transferPhoto(), AttachmentKind::TransferSent, $this->sender))->toThrow(StockRuleViolation::class, 'draft')
        ->and(fn () => $this->transfers->attachPhoto($this->transfer->fresh(), UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), AttachmentKind::TransferReceived, $this->receiver))
        ->toThrow(StockRuleViolation::class, 'image');
});

it('shows the photos only to people who may see the transfer', function () {
    $this->transfers->send($this->transfer, $this->sender, photo: transferPhoto());
    $photo = $this->transfer->fresh()->photo(AttachmentKind::TransferSent);
    $stranger = userWithRole(Role::BranchStaff, [branch('EBL')]);

    $this->actingAs($this->receiver)->get(route('attachments.show', $photo))->assertOk();
    $this->actingAs($stranger)->get(route('attachments.show', $photo))->assertForbidden();
});

it('takes the photos on the phone before send and receive', function () {
    $screen = Livewire::actingAs($this->sender)->test(TransferShow::class, ['transfer' => $this->transfer])
        ->assertSee('Take a photo of the items before sending')
        ->call('send')
        ->assertDispatched('toast', type: 'error');

    expect($this->transfer->fresh()->status)->toBe(TransferStatus::Draft);

    $screen->set('photo', transferPhoto())
        ->assertDispatched('toast', type: 'success')
        ->call('send');

    expect($this->transfer->fresh()->status)->toBe(TransferStatus::InTransit);

    Livewire::actingAs($this->receiver)->test(TransferShow::class, ['transfer' => $this->transfer->fresh()])
        ->assertSee('Take a photo of the items as they arrived')
        ->call('handleScan', 'SN-1')
        ->set('photo', transferPhoto())
        ->call('receive');

    expect(Transfer::query()->sole()->status)->toBe(TransferStatus::Received);
});
