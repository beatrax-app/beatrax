<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\CategoryKind;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class DefaultCategoryTreeSeeder extends Seeder
{
    /**
     * @var list<array{name: string, slug: string, kind: string, children?: list<array{name: string, slug: string, kind: string}>}>
     */
    private const TREE = [
        ['name' => 'Income', 'slug' => 'income', 'kind' => CategoryKind::Income->value, 'children' => [
            ['name' => 'Salary', 'slug' => 'income-salary', 'kind' => CategoryKind::Income->value],
            ['name' => 'Refunds', 'slug' => 'income-refunds', 'kind' => CategoryKind::Income->value],
            ['name' => 'Other income', 'slug' => 'income-other', 'kind' => CategoryKind::Income->value],
        ]],
        ['name' => 'Housing', 'slug' => 'housing', 'kind' => CategoryKind::Expense->value, 'children' => [
            ['name' => 'Rent / Mortgage', 'slug' => 'housing-rent', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Utilities', 'slug' => 'housing-utilities', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Internet & Phone', 'slug' => 'housing-internet', 'kind' => CategoryKind::Expense->value],
        ]],
        ['name' => 'Groceries', 'slug' => 'groceries', 'kind' => CategoryKind::Expense->value],
        ['name' => 'Transport', 'slug' => 'transport', 'kind' => CategoryKind::Expense->value, 'children' => [
            ['name' => 'Public transport', 'slug' => 'transport-public', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Fuel', 'slug' => 'transport-fuel', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Car maintenance', 'slug' => 'transport-car', 'kind' => CategoryKind::Expense->value],
        ]],
        ['name' => 'Insurance', 'slug' => 'insurance', 'kind' => CategoryKind::Expense->value, 'children' => [
            ['name' => 'Health', 'slug' => 'insurance-health', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Liability', 'slug' => 'insurance-liability', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Other', 'slug' => 'insurance-other', 'kind' => CategoryKind::Expense->value],
        ]],
        ['name' => 'Subscriptions', 'slug' => 'subscriptions', 'kind' => CategoryKind::Expense->value, 'children' => [
            ['name' => 'Streaming', 'slug' => 'subscriptions-streaming', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Music', 'slug' => 'subscriptions-music', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Cloud / Software', 'slug' => 'subscriptions-cloud', 'kind' => CategoryKind::Expense->value],
            ['name' => 'Memberships', 'slug' => 'subscriptions-memberships', 'kind' => CategoryKind::Expense->value],
        ]],
        ['name' => 'Eating out', 'slug' => 'eating-out', 'kind' => CategoryKind::Expense->value],
        ['name' => 'Cash withdrawal', 'slug' => 'cash-withdrawal', 'kind' => CategoryKind::Expense->value],
        ['name' => 'Healthcare', 'slug' => 'healthcare', 'kind' => CategoryKind::Expense->value],
        ['name' => 'Personal care', 'slug' => 'personal-care', 'kind' => CategoryKind::Expense->value],
        ['name' => 'Donations', 'slug' => 'donations', 'kind' => CategoryKind::Expense->value],
        ['name' => 'Transfers (internal)', 'slug' => 'transfers-internal', 'kind' => CategoryKind::Transfer->value],
        ['name' => 'Fees & charges', 'slug' => 'fees', 'kind' => CategoryKind::Expense->value],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::TREE as $parent) {
            $order += 10;

            $parentModel = Category::withoutGlobalScopes()->updateOrCreate(
                ['slug' => $parent['slug'], 'user_id' => null],
                [
                    'name' => $parent['name'],
                    'kind' => $parent['kind'],
                    'parent_id' => null,
                    'display_order' => $order,
                ],
            );

            foreach (($parent['children'] ?? []) as $child) {
                $order += 1;
                Category::withoutGlobalScopes()->updateOrCreate(
                    ['slug' => $child['slug'], 'user_id' => null],
                    [
                        'name' => $child['name'],
                        'kind' => $child['kind'],
                        'parent_id' => $parentModel->id,
                        'display_order' => $order,
                    ],
                );
            }
        }
    }
}
