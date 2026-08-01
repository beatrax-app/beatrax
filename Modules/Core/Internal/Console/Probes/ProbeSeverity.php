<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

// The three health levels a Probe reports through ProbeResult. `ok` has no
// analogue in SystemAlertSeverity — alerts are only raised for a problem,
// whereas doctor probes also report the healthy path. The `info`-only
// ext-imap row stays outside this model by design (see DoctorCommand).
enum ProbeSeverity: string
{
    case Ok = 'ok';

    case Warning = 'warning';

    case Critical = 'critical';
}
