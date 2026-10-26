<?php

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\BoxTransferStatus;
use App\Enums\CashBoxType;
use App\Enums\ClosingStatus;
use App\Enums\MoneyTransactionType;
use App\Enums\PartyEntryType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches'); // NULL = head office
            $table->jsonb('name');
            $table->string('type', 16)->default(CashBoxType::Cash->value);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number', 64)->nullable();
            $table->string('iban', 64)->nullable();
            $table->boolean('allow_overdraft')->default(false);
            $table->decimal('current_balance', 18, 4)->default(0); // cached, updated under lock
            $table->boolean('is_active')->default(true);
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['branch_id', 'currency']);
        });

        Pg::enumCheck('cash_boxes', 'type', CashBoxType::values());
        Pg::check('cash_boxes', 'cash_boxes_no_negative_cash', 'allow_overdraft OR current_balance >= 0');
        // One open cash box per branch and currency.
        DB::statement("CREATE UNIQUE INDEX cash_boxes_one_cash_per_currency ON cash_boxes (COALESCE(branch_id, 0), currency) WHERE type = 'cash' AND is_active");

        // THE money ledger — append-only.
        Schema::create('money_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('movement_group');
            $table->foreignId('cash_box_id')->constrained('cash_boxes');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('type', 32);
            $table->decimal('amount', 18, 4); // signed: + in / − out, in the box currency
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('applied_rate', 18, 8)->nullable();
            $table->decimal('amount_usd', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->nullableMorphs('reference');
            $table->foreignId('reverses_id')->nullable()->constrained('money_transactions');
            $table->text('description')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['cash_box_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('movement_group');
        });

        Pg::enumCheck('money_transactions', 'type', MoneyTransactionType::values());
        Pg::check('money_transactions', 'money_transactions_not_zero', 'amount <> 0');
        Pg::appendOnly('money_transactions');

        Schema::create('cash_box_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('cash_boxes');
            $table->date('business_date');
            $table->decimal('opening_balance', 18, 4);
            $table->decimal('expected_balance', 18, 4);
            $table->decimal('counted_balance', 18, 4)->nullable();
            $table->decimal('difference', 18, 4)->default(0);
            $table->text('difference_reason')->nullable();
            $table->jsonb('denominations')->nullable();
            $table->foreignId('last_money_transaction_id')->nullable()->constrained('money_transactions');
            $table->foreignId('adjustment_transaction_id')->nullable()->constrained('money_transactions');
            $table->string('status', 16)->default(ClosingStatus::Draft->value);
            $table->foreignId('counted_by')->nullable()->constrained('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->unique(['cash_box_id', 'business_date']);
        });

        Pg::enumCheck('cash_box_closings', 'status', ClosingStatus::values());
        Pg::check('cash_box_closings', 'cash_box_closings_reason', "difference = 0 OR difference_reason IS NOT NULL OR status = 'draft'");

        Schema::create('box_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('from_cash_box_id')->constrained('cash_boxes');
            $table->foreignId('to_cash_box_id')->constrained('cash_boxes');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->string('status', 16)->default(BoxTransferStatus::Sent->value);
            $table->text('note')->nullable();
            $table->foreignId('sent_by')->constrained('users');
            $table->timestampTz('sent_at');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestampTz('received_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();
        });

        Pg::enumCheck('box_transfers', 'status', BoxTransferStatus::values());
        Pg::check('box_transfers', 'box_transfers_positive', 'amount > 0 AND from_cash_box_id <> to_cash_box_id');

        Schema::create('currency_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('from_cash_box_id')->constrained('cash_boxes');
            $table->foreignId('to_cash_box_id')->constrained('cash_boxes');
            $table->decimal('from_amount', 18, 4);
            $table->char('from_currency', 3);
            $table->decimal('to_amount', 18, 4);
            $table->char('to_currency', 3);
            $table->decimal('rate', 18, 8); // deal rate: 1 from = rate to
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('gain_loss_usd', 18, 4)->default(0); // deal vs reference
            $table->foreignId('performed_by')->constrained('users');
            $table->timestampTz('performed_at');
            $table->text('note')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();
        });

        Pg::check('currency_exchanges', 'currency_exchanges_positive', 'from_amount > 0 AND to_amount > 0 AND rate > 0 AND from_currency <> to_currency');

        // Supplier and customer balances per currency — append-only.
        Schema::create('party_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('party');
            $table->string('entry_type', 32);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->decimal('amount', 18, 4); // + increases what is owed
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->nullableMorphs('reference');
            $table->text('description')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['party_type', 'party_id', 'currency', 'occurred_at']);
        });

        Pg::enumCheck('party_ledger_entries', 'entry_type', PartyEntryType::values());
        Pg::appendOnly('party_ledger_entries');

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->string('type', 32);
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->decimal('amount', 18, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('amount_usd', 18, 4)->nullable();
            $table->string('status', 16)->default(ApprovalStatus::Pending->value);
            $table->foreignId('requested_by')->constrained('users');
            $table->timestampTz('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'type']);
        });

        Pg::enumCheck('approvals', 'type', ApprovalType::values());
        Pg::enumCheck('approvals', 'status', ApprovalStatus::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('party_ledger_entries');
        Schema::dropIfExists('currency_exchanges');
        Schema::dropIfExists('box_transfers');
        Schema::dropIfExists('cash_box_closings');
        Schema::dropIfExists('money_transactions');
        Schema::dropIfExists('cash_boxes');
    }
};
