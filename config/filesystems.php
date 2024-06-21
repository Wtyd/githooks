<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => getcwd(),
        ],
        'testing' => [
            'driver' => 'local',
            'root' => getcwd() . '/testsDir',
        ],
    ],
];
