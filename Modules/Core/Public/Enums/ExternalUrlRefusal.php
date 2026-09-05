<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// Why a URL that did not originate in this codebase was not turned into an
// href, a window address or an argument to the shell. The cases are ordered the
// way they are tested, so each one names a cause the ones before it have ruled
// out and no refusal blames a scheme the check has already accepted.
enum ExternalUrlRefusal: string
{
    case NotHttps = 'not_https';

    case Malformed = 'malformed';

    case CarriesCredentials = 'carries_credentials';

    case HostIsNotPublic = 'host_is_not_public';

    case NonDefaultPort = 'non_default_port';

    case HostNotAllowListed = 'host_not_allow_listed';
}
