<?php

declare(strict_types=1);

/*
 * Per-module Pest.php is documented inert. Pest's BootFiles
 * bootstrapper only auto-loads `tests/Pest.php` at the project root,
 * so this file is never executed by Pest itself.
 *
 * The per-module TestCase + RefreshDatabase wire-up lives in the
 * project-root `tests/Pest.php` per-module foreach map. Keep this
 * file present (matches the convention shared by every other module)
 * so a future contributor doesn't expect a separate bootstrap here.
 */
