<?php

declare(strict_types=1);

return [
    'heading' => 'Επισκόπηση',
    'subtitle' => 'Λειτουργική εικόνα της ενσωματωμένης κονσόλας προγραμματιστή.',
    'worker_heartbeat' => 'Παλμός worker',
    'not_running' => 'ΔΕΝ ΕΚΤΕΛΕΙΤΑΙ',
    // i18n-review: el · heartbeat_age — δλ is the symbol this locale already uses
    // for a compact duration in emailscan::inboxes.retry_seconds, while
    // mobile::lock.errors.too_many_attempts writes δευτ. core::sidebar.dev.worker_ago
    // follows this one; a native reader settles which of the two the app keeps.
    'heartbeat_age' => 'πριν από :count δλ · ttl :ttl δλ|πριν από :count δλ · ttl :ttl δλ',
    'queue' => 'Ουρά',
    'pending' => 'σε αναμονή',
    'failed' => 'απέτυχαν',
    'batches' => 'παρτίδες',
    'queue_summary' => ':failed · :batches',
    'queue_summary_failed' => ':count εργασία που απέτυχε|:count εργασίες που απέτυχαν',
    'queue_summary_batches' => ':count ενεργή παρτίδα|:count ενεργές παρτίδες',
    'last_command' => 'Τελευταία εντολή',
    'waiting_for_logs' => 'Αναμονή για γραμμές καταγραφής…',
    'recent_runs' => 'Πρόσφατες εκτελέσεις',
    'recent_runs_empty' => 'Καμία εκτέλεση ακόμη. Πάτησε ⌘K για να εκτελέσεις μια εντολή.',
    'open_alerts' => 'Ανοιχτές ειδοποιήσεις',
    'open_alerts_empty' => 'Δεν υπάρχουν ανοιχτές ειδοποιήσεις.',
    'reauth' => 'Επανεξουσιοδότηση →',
];
