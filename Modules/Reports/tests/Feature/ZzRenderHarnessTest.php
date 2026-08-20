<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

it('dumps the reports index with the delete confirm strip open', function (): void {
    $dump = function (string $name, string $html): void {
        $dir = (string) (getenv('HARNESS_OUT') ?: '/tmp/harness');
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $stripped = (string) preg_replace(
            [
                '/\swire:id="[^"]*"/',
                '/\swire:snapshot="[^"]*"/',
                '/\swire:effects="[^"]*"/',
                '/\swire:key="[^"]*"/',
            ],
            '',
            $html,
        );
        $stripped = (string) preg_replace('/>\s+</', ">\n<", $stripped);
        file_put_contents($dir.'/'.$name.'.html', $stripped);
    };

    $user = User::query()->create([
        'username' => 'harness-reports',
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($user);

    $saved = app(SaveReport::class)->save($user, new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
    ), 'Monthly spend by category');

    $cards = (string) Livewire::test(ReportsIndex::class)
        ->set('view', 'cards')
        ->set('confirmingDeleteId', $saved->id)
        ->html();
    $dump('reports-index-cards', $cards);

    $list = (string) Livewire::test(ReportsIndex::class)
        ->set('view', 'list')
        ->set('confirmingDeleteId', $saved->id)
        ->html();
    $dump('reports-index-list', $list);

    expect($cards)->toContain('Monthly spend by category');
    expect($list)->toContain('Monthly spend by category');
});
