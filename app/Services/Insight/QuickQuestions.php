<?php

namespace App\Services\Insight;

use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;

/**
 * The ready-made questions of Quick answers, in screen order (docs/phases/PHASE-6-PLAN.md §3).
 * Each person sees only the questions their permissions answer. Keywords (all three languages,
 * matched by KeywordMatcher whatever the screen language) point typed words to questions.
 */
final class QuickQuestions
{
    public function __construct(
        private readonly FactRegistry $facts,
        private readonly BranchScopeResolver $scope,
    ) {}

    /**
     * @return array<string, string> group → label
     */
    public static function groups(): array
    {
        return [
            'day' => __('Today and yesterday'),
            'stock' => __('Stock'),
            'kits' => __('Kits'),
            'surgeries' => __('Surgeries'),
            'money' => __('Money'),
            'attention' => __('Needs attention'),
        ];
    }

    /**
     * @return list<QuickQuestion>
     */
    public function all(): array
    {
        $expire = ['expire', 'expiry', 'expiring', 'expires', 'صلاحية', 'انتهاء', 'ينتهي', 'تنتهي', 'منتهي', 'بەسەرچوون', 'بەسەردەچێت', 'بەسەرچوو'];
        $kit = ['kit', 'طقم', 'أطقم', 'اطقم', 'کیت'];
        $surgery = ['surgery', 'surgeries', 'operation', 'عملية', 'عمليات', 'نەشتەرگەری'];

        return [
            new QuickQuestion('today', 'day', __('What happened today?'), day: 'today',
                keywords: ['today', 'happened', 'اليوم', 'حدث', 'ئەمڕۆ', 'چی ڕوویدا']),
            new QuickQuestion('yesterday', 'day', __('What happened yesterday?'), day: 'yesterday',
                keywords: ['yesterday', 'summary', 'أمس', 'البارحة', 'ملخص', 'دوێنێ', 'پوختە']),

            new QuickQuestion('expiring-30', 'stock', __('What expires in the next :days days?', ['days' => Display::number(30)]), 'expiring-within', ['days' => 30],
                keywords: [...$expire, '30']),
            new QuickQuestion('expiring-60', 'stock', __('What expires in the next :days days?', ['days' => Display::number(60)]), 'expiring-within', ['days' => 60],
                keywords: [...$expire, '60']),
            new QuickQuestion('expiring-90', 'stock', __('What expires in the next :days days?', ['days' => Display::number(90)]), 'expiring-within', ['days' => 90],
                keywords: [...$expire, '90']),
            new QuickQuestion('below-minimum', 'stock', __('What is below minimum?'), 'below-minimum',
                keywords: ['minimum', 'low stock', 'reorder', 'shortage', 'الحد الأدنى', 'نقص', 'قليل', 'کەمترین', 'کەمبوون']),
            new QuickQuestion('item', 'stock', __('How many of an item do we have, and where?'),
                prompt: __("Scan or type the item's code (SKU, barcode or lot)."), permission: 'stock.view',
                keywords: ['how many', 'quantity', 'where', 'item', 'product', 'stock', 'كم', 'كمية', 'أين', 'وين', 'مخزون', 'منتج', 'چەند', 'بڕ', 'لەکوێ', 'کۆگا', 'بەرهەم']),
            new QuickQuestion('serial', 'stock', __('Where is a serial number?'),
                prompt: __('Scan or type the serial number.'), permission: 'stock.view',
                keywords: ['serial', 'تسلسلي', 'سيريال', 'زنجیرە', 'سریاڵ']),

            new QuickQuestion('kits-out', 'kits', __('Which kits are out now?'), 'kits-out',
                keywords: [...$kit, 'out', 'hospital', 'مستشفى', 'خارج', 'نەخۆشخانە', 'دەرەوە']),
            new QuickQuestion('kits-overdue', 'kits', __('Which kits are overdue?'), 'kits-out', ['overdue' => true],
                keywords: [...$kit, 'overdue', 'late', 'متأخر', 'تأخر', 'دواکەوتوو', 'دواکەوت']),
            new QuickQuestion('kits-missing', 'kits', __('What is missing from returned kits?'), 'unaccounted-implants',
                keywords: [...$kit, 'missing', 'lost', 'unaccounted', 'ناقص', 'مفقود', 'نقص', 'ون بوو', 'بزر', 'کەم']),
            new QuickQuestion('kit-complete', 'kits', __('Is a kit complete?'),
                prompt: __("Scan or type the kit's code."), permission: 'kits.view_any',
                keywords: [...$kit, 'complete', 'full', 'كامل', 'مكتمل', 'تەواو']),

            new QuickQuestion('surgeries-hospital', 'surgeries', __('Surgeries by hospital'), 'surgeries-done',
                keywords: [...$surgery, 'hospital', 'مستشفى', 'نەخۆشخانە']),
            new QuickQuestion('surgeries-doctor', 'surgeries', __('Surgeries by doctor'), 'surgeries-done', ['by' => 'doctor'],
                keywords: [...$surgery, 'doctor', 'surgeon', 'طبيب', 'دكتور', 'جراح', 'پزیشک', 'دکتۆر']),
            new QuickQuestion('draft-surgeries', 'surgeries', __('Surgeries not finished'), 'draft-surgeries',
                keywords: [...$surgery, 'draft', 'unfinished', 'not finished', 'مسودة', 'غير مكتمل', 'ڕەشنووس', 'تەواو نەبوو']),

            new QuickQuestion('sales', 'money', __('Sales by hospital'), 'sales',
                keywords: ['sales', 'sold', 'revenue', 'مبيعات', 'بيع', 'فرۆشتن']),
            new QuickQuestion('payments', 'money', __('Payments received'), 'payments-received',
                keywords: ['payment', 'received', 'collected', 'دفعات', 'مقبوضات', 'تحصيل', 'پارەدان', 'وەرگیراو']),
            new QuickQuestion('balances', 'money', __('Unpaid hospital balances'), 'hospital-balances',
                keywords: ['balance', 'owe', 'debt', 'unpaid', 'رصيد', 'ديون', 'دين', 'غير مدفوع', 'باڵانس', 'قەرز']),
            new QuickQuestion('supplier-due', 'money', __('Supplier invoices due'), 'supplier-invoices-due',
                keywords: ['supplier', 'invoice', 'due', 'مورد', 'فاتورة', 'فواتير', 'مستحق', 'دابینکەر', 'پسوولە']),
            new QuickQuestion('cash', 'money', __('Cash in the cash boxes now'), 'cash-in-boxes',
                keywords: ['cash', 'box', 'money', 'نقد', 'صندوق', 'نەختینە', 'سندوق', 'پارە']),
            new QuickQuestion('expenses', 'money', __('Expenses by category'), 'expenses',
                keywords: ['expense', 'spent', 'spending', 'مصاريف', 'مصروف', 'نفقات', 'خەرجی']),
            new QuickQuestion('profit', 'money', __('Profit'), 'profit',
                keywords: ['profit', 'margin', 'ربح', 'أرباح', 'قازانج']),

            new QuickQuestion('issues', 'attention', __('Open issues'), 'open-issues',
                keywords: ['issue', 'problem', 'مشكلة', 'مشكلات', 'مشاكل', 'کێشە']),
            new QuickQuestion('approvals', 'attention', __('Approvals waiting for me'), 'approvals-waiting',
                keywords: ['approval', 'approve', 'waiting', 'موافقة', 'موافقات', 'انتظار', 'پەسەند', 'چاوەڕێ']),
            new QuickQuestion('transfers-to-receive', 'attention', __('Transfers waiting to be received'), 'transfers-to-receive',
                keywords: ['transfer', 'receive', 'incoming', 'تحويل', 'تحويلات', 'نقل', 'استلام', 'گواستنەوە', 'وەرگرتن']),
        ];
    }

    public function find(string $key): ?QuickQuestion
    {
        foreach ($this->all() as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }

        return null;
    }

    /**
     * @return list<QuickQuestion>
     */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (QuickQuestion $q) => $this->canAsk($user, $q)));
    }

    public function canAsk(User $user, QuickQuestion $question): bool
    {
        return match (true) {
            $question->fact !== null => ($fact = $this->facts->find($question->fact)) !== null && $fact->canSee($user),
            $question->permission !== null => $this->scope->hasAny($user, $question->permission),
            $question->isDay() => $this->facts->visibleTo($user) !== [],
            default => false,
        };
    }

    /**
     * True when the answer depends on the chosen period.
     */
    public function usesPeriod(QuickQuestion $question): bool
    {
        return $question->fact !== null && ($this->facts->find($question->fact)?->usesPeriod() ?? false);
    }
}
