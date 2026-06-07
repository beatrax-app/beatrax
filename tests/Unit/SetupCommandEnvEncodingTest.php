<?php

declare(strict_types=1);

use App\Console\Commands\SetupCommand;

function encodeEnvValue(string $value): string
{
    $method = new ReflectionMethod(SetupCommand::class, 'encodeEnvValue');

    return (string) $method->invoke(new SetupCommand, $value);
}

it('leaves a simple value unquoted', function (): void {
    expect(encodeEnvValue('beatrax'))->toBe('beatrax');
    expect(encodeEnvValue(''))->toBe('');
});

it('quotes values containing whitespace or #', function (): void {
    expect(encodeEnvValue('My App'))->toBe('"My App"');
    expect(encodeEnvValue('a#b'))->toBe('"a#b"');
});

it('escapes $ so DotEnv does not interpolate a password', function (): void {
    // a${b}c → quoted with the $ escaped, so DotEnv reads it back literally.
    expect(encodeEnvValue('a${b}c'))->toBe('"a\${b}c"');
    expect(encodeEnvValue('p$1ss'))->toBe('"p\$1ss"');
});

it('escapes backslashes and double quotes', function (): void {
    expect(encodeEnvValue('pa\\ss'))->toBe('"pa\\\\ss"');
    expect(encodeEnvValue('say "hi"'))->toBe('"say \"hi\""');
});
