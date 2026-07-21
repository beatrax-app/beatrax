<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Category;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class DefaultCategoryTreeSeeder extends Seeder
{
    /**
     * @var list<array{name: string, slug: string, kind: string, children?: list<array{name: string, slug: string, kind: string}>}>
     */
    private const TREE = [
        ['name' => 'Income', 'slug' => 'income', 'kind' => 'income', 'children' => [
            ['name' => 'Salary', 'slug' => 'income-salary', 'kind' => 'income'],
            ['name' => 'Refunds', 'slug' => 'income-refunds', 'kind' => 'income'],
            ['name' => 'Other income', 'slug' => 'income-other', 'kind' => 'income'],
        ]],
        ['name' => 'Housing', 'slug' => 'housing', 'kind' => 'expense', 'children' => [
            ['name' => 'Rent / Mortgage', 'slug' => 'housing-rent', 'kind' => 'expense'],
            ['name' => 'Utilities', 'slug' => 'housing-utilities', 'kind' => 'expense'],
            ['name' => 'Internet & Phone', 'slug' => 'housing-internet', 'kind' => 'expense'],
        ]],
        ['name' => 'Groceries', 'slug' => 'groceries', 'kind' => 'expense'],
        ['name' => 'Transport', 'slug' => 'transport', 'kind' => 'expense', 'children' => [
            ['name' => 'Public transport', 'slug' => 'transport-public', 'kind' => 'expense'],
            ['name' => 'Fuel', 'slug' => 'transport-fuel', 'kind' => 'expense'],
            ['name' => 'Car maintenance', 'slug' => 'transport-car', 'kind' => 'expense'],
        ]],
        ['name' => 'Insurance', 'slug' => 'insurance', 'kind' => 'expense', 'children' => [
            ['name' => 'Health', 'slug' => 'insurance-health', 'kind' => 'expense'],
            ['name' => 'Liability', 'slug' => 'insurance-liability', 'kind' => 'expense'],
            ['name' => 'Other', 'slug' => 'insurance-other', 'kind' => 'expense'],
        ]],
        ['name' => 'Subscriptions', 'slug' => 'subscriptions', 'kind' => 'expense', 'children' => [
            ['name' => 'Streaming', 'slug' => 'subscriptions-streaming', 'kind' => 'expense'],
            ['name' => 'Music', 'slug' => 'subscriptions-music', 'kind' => 'expense'],
            ['name' => 'Cloud / Software', 'slug' => 'subscriptions-cloud', 'kind' => 'expense'],
            ['name' => 'Memberships', 'slug' => 'subscriptions-memberships', 'kind' => 'expense'],
        ]],
        ['name' => 'Eating out', 'slug' => 'eating-out', 'kind' => 'expense'],
        ['name' => 'Cash withdrawal', 'slug' => 'cash-withdrawal', 'kind' => 'expense'],
        ['name' => 'Healthcare', 'slug' => 'healthcare', 'kind' => 'expense'],
        ['name' => 'Personal care', 'slug' => 'personal-care', 'kind' => 'expense'],
        ['name' => 'Donations', 'slug' => 'donations', 'kind' => 'expense'],
        ['name' => 'Transfers (internal)', 'slug' => 'transfers-internal', 'kind' => 'transfer'],
        ['name' => 'Fees & charges', 'slug' => 'fees', 'kind' => 'expense'],
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
