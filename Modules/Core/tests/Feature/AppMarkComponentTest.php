<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * @return array<string, string>
 */
function appMarkAttributes(string $template): array
{
    $html = Blade::render($template);

    expect($html)->toContain('<img');

    preg_match_all('/([a-zA-Z-]+)="([^"]*)"/', $html, $matches, PREG_SET_ORDER);

    $attributes = [];
    foreach ($matches as $match) {
        $attributes[$match[1]] = $match[2];
    }

    return $attributes;
}

it('draws the lock and setup screens exactly as their hand-written block did', function (): void {
    $attributes = appMarkAttributes('<x-core::app-mark />');

    expect($attributes)->toMatchArray([
        'width' => '48',
        'height' => '48',
        'alt' => 'Beatrax',
        'class' => 'rounded-xl',
        'aria-hidden' => 'true',
    ]);
    expect($attributes['src'] ?? '')->toContain('logo');
});

it('draws the lock veil mark exactly as the layout did', function (): void {
    expect(appMarkAttributes('<x-core::app-mark alt="" class="rounded-xl opacity-40" />'))
        ->toMatchArray([
            'width' => '48',
            'height' => '48',
            'alt' => '',
            'class' => 'rounded-xl opacity-40',
            'aria-hidden' => 'true',
        ]);
});

it('draws the phone top-bar mark exactly as the top bar did', function (): void {
    expect(appMarkAttributes('<x-core::app-mark :size="20" alt="" class="top-bar-logo" />'))
        ->toMatchArray([
            'width' => '20',
            'height' => '20',
            'alt' => '',
            'class' => 'top-bar-logo',
            'aria-hidden' => 'true',
        ]);
});

it('draws the sidebar mark exactly as the sidebar did, aria-hidden included', function (): void {
    $attributes = appMarkAttributes('<x-core::app-mark :size="24" class="logo logo-svg" :decorative="false" />');

    expect($attributes)->toMatchArray([
        'width' => '24',
        'height' => '24',
        'alt' => 'Beatrax',
        'class' => 'logo logo-svg',
    ]);
    expect($attributes)->not->toHaveKey('aria-hidden');
});

it('draws the setup-wizard mark exactly as the wizard did', function (): void {
    $attributes = appMarkAttributes('<x-core::app-mark :size="22" class="wiz-brand-mark logo-svg" :decorative="false" />');

    expect($attributes)->toMatchArray([
        'width' => '22',
        'height' => '22',
        'alt' => 'Beatrax',
        'class' => 'wiz-brand-mark logo-svg',
    ]);
    expect($attributes)->not->toHaveKey('aria-hidden');
});

it('leaves width and height off the welcome mark, which sizes itself by class', function (): void {
    $attributes = appMarkAttributes('<x-core::app-mark :size="false" class="h-20 w-20" :decorative="false" />');

    expect($attributes)->toMatchArray([
        'alt' => 'Beatrax',
        'class' => 'h-20 w-20',
    ]);
    expect($attributes)->not->toHaveKey('width');
    expect($attributes)->not->toHaveKey('height');
    expect($attributes)->not->toHaveKey('aria-hidden');
});

it('names the brand with the capital the guard requires', function (): void {
    expect(Blade::render('<x-core::app-mark />'))->toContain('alt="Beatrax"');
});
