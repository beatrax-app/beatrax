<?php

declare(strict_types=1);

return [

    // The interfaces this install serves on beyond loopback, as a comma-separated
    // list of literal IP addresses, matched against the bind address the SAPI
    // publishes. Empty — the shipped default — refuses every non-loopback request
    // with not-found. A wildcard, a hostname or a CIDR range is refused rather
    // than honoured: each would turn a list of interfaces into "everything".
    'served_interfaces' => env('BEATRAX_SERVED_INTERFACES', ''),

];
