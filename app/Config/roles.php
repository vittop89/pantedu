<?php

return [
    'roles' => [
        'guest'         => 0,
        'student'       => 10,
        'teacher'       => 40,
        'collaborator'  => 50,
        'administrator' => 100,
    ],

    'access_zones' => [
        'public'       => ['guest', 'student', 'teacher', 'collaborator', 'administrator'],
        'student'      => ['student', 'teacher', 'collaborator', 'administrator'],
        'teacher'      => ['teacher', 'collaborator', 'administrator'],
        'collaborator' => ['collaborator', 'administrator'],
        'admin'        => ['administrator'],
    ],
];
