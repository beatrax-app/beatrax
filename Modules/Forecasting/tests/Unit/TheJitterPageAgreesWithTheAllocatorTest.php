<?php

declare(strict_types=1);

// The page carried two answers about the same seven replicas: one section said
// the shares add back up exactly and quoted them, and a later one said they do
// not and that -1000 becomes -1001. The second outlived the commit that moved
// the cut onto the allocator, and nothing read the prose against the code.
it('does not tell a reader the jitter drifts while the allocator cuts it', function (): void {
    $code = (string) file_get_contents(base_path('Modules/Forecasting/Internal/Pipeline/CadenceJitter.php'));
    $page = (string) file_get_contents(base_path('.docs/features/forecasting/projection-math.md'));

    expect($code)->toContain('CrossCurrencyTotal::apportion(');

    expect($page)->not->toContain('do not necessarily re-sum')
        ->and($page)->not->toContain('becomes -1001')
        ->and($page)->toContain('re-sum to the original amount');
});
