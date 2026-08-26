<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

// The one BrowserWindow this app opens, named so the shell can be addressed
// without asking which window has focus — a question with no answer while the
// app is in the background, hidden in the tray, or answering a notification.
final class AppWindow
{
    public const string ID = 'main';
}
