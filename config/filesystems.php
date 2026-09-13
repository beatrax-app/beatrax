<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

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

    // One disk, rooted through the path authority like database.php and
    // logging.php: the framework's own default here is storage_path(), which on
    // a phone names the bundle copy an app update replaces. CoreServiceProvider
    // sets the same value again at registration, and that is not redundant --
    // `config:cache` freezes whatever this file computed on the machine that
    // cached it, and the provider's runtime set() is what corrects it.
    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => UserDataPathService::appPath('private'),
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
