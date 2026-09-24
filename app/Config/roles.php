<?php

return [
    // I livelli non si confrontano da nessuna parte: l'accesso lo decidono le
    // zone qui sotto (Auth::hasAccess). Restano come ordine descrittivo.
    'roles' => [
        'guest'           => 0,
        'student'         => 10,
        'teacher'         => 40,
        'institute_admin' => 60,
        'administrator'   => 100,
    ],

    'access_zones' => [
        'public'       => ['guest', 'student', 'teacher', 'institute_admin', 'administrator'],
        'student'      => ['student', 'teacher', 'administrator'],
        'teacher'      => ['teacher', 'administrator'],
        'admin'        => ['administrator'],
        // ADR-040 — l'amministratore di istituto sta solo qui (e nella zona
        // pubblica): tutto ciò che è delle altre zone gli resta chiuso per
        // costruzione, comprese le rotte aggiunte domani. E solo nello
        // scenario 3: fuori, Auth::hasAccess non gli concede niente.
        'istituto'     => ['institute_admin', 'administrator'],
    ],
];
