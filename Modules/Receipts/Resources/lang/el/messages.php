<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'ποσό',
            'currency' => 'νόμισμα',
            'description' => 'περιγραφή',
            'counterparty_name' => 'επωνυμία εμπόρου',
            'default' => 'τιμή',
        ],
        'heading_cleaner' => 'Μια απόδειξη email έχει καθαρότερο πεδίο :field',
        'heading_different' => 'Μια απόδειξη email καταγράφει διαφορετικό πεδίο :field',
        'title' => 'Η απόδειξη και η κατάσταση κινήσεων διαφωνούν.',
        'body' => ':heading («:receipt») σε σχέση με την κατάσταση κινήσεων («:statement»). Να προτιμά το Beatrax τις αποδείξεις σε μελλοντικές διαφωνίες;',
        'use_receipt' => 'Χρήση απόδειξης',
        'keep_statement' => 'Διατήρηση κατάστασης',
        'heading_restated' => 'Μια νεότερη κατάσταση κινήσεων καταγράφει διαφορετικό πεδίο :field',
        'restated_title' => 'Η τράπεζα αναμόρφωσε αυτή τη συναλλαγή.',
        'restated_body' => ':heading («:incoming») σε σχέση με την ήδη αποθηκευμένη γραμμή («:stored»). Να προτιμά το Beatrax τη νέα τιμή σε μελλοντικές διαφωνίες;',
        'use_restated' => 'Χρήση νέας τιμής',
        'keep_stored' => 'Διατήρηση αποθηκευμένης τιμής',
    ],
];
