<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\CategoryKind;

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

            $parentModel = $this->upsert($parent, null, $order);

            foreach (($parent['children'] ?? []) as $child) {
                $order += 1;
                $this->upsert($child, $parentModel->id, $order);
            }
        }
    }

    // Structure is re-asserted every run; the name and the flag are written
    // once, at creation — re-asserting either would undo a rename on a tree
    // that is shared and re-seeded per user. The name goes in untranslated,
    // because CategoryDisplayName translates it per reader at render.
    /**
     * @param  array{name: string, slug: string, kind: string}  $node
     */
    private function upsert(array $node, ?int $parentId, int $order): Category
    {
        $model = Category::withoutGlobalScopes()->firstOrNew(
            ['slug' => $node['slug'], 'user_id' => null],
        );

        if (! $model->exists) {
            $model->name = $node['name'];
            $model->name_is_default = true;
        }

        $model->kind = $node['kind'];
        $model->parent_id = $parentId;
        $model->display_order = $order;
        $model->save();

        return $model;
    }
}
