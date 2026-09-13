<?php

declare(strict_types=1);

// Published for one key. Without this file the framework's own default stands,
// and it sets `serve => true` on the local disk: FilesystemServiceProvider then
// registers GET and PUT on /storage/{path} with where('path', '.*'), inside
// $this->app->booted() and therefore outside every middleware group.
//
// That route is not anonymous -- no `visibility` key means ServeFile falls
// through to 'private' and demands an APP_KEY signature -- but the signature
// authenticates the path, not the caller. One scheme spans every user's
// directory, and the local disk's root is the live user-data tree, which holds
// imports/{userId}/{sha256}. PUT ...?upload=1 writes the request body there.
//
// Nothing in this product mints such a URL. Nothing has to: the route exists
// because a vendor default was never chosen, and it answers with no session, no
// CSRF token, no authenticated user, no app-lock and no user scope.
/**
 * @link ../.docs/features/import/architecture.md#the-disk-holding-those-files-is-not-served
 */
return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    // One disk. `root` is the framework's own default and is overridden at
    // registration by CoreServiceProvider, which points it at the writable data
    // directory the path service answers with -- the same directory on a
    // desktop, a different one on a phone, where storage_path() names the
    // bundle copy an app update replaces.
    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

    ],

    // Nothing under the data root is web-reachable, so there is no public disk
    // and nothing for `storage:link` to link. An entry here would put a symlink
    // from public/ into the tree above.
    'links' => [],

];
