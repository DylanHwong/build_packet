<?php
/**
 * smtp mail conf
 */
$g_c['mail'] = [
    'build_packet' => [
        'host' => 'smtp.exmail.qq.com',
        'username' => 'monitor@q-dazzle.com',
        'password' => 'qdazzle0312',
        'from' => ['monitor@q-dazzle.com' => 'Build Packet Monitor'],
        'to' => ['monitor-php@q-dazzle.com','maintenance@q-dazzle.com'],
        'subject' => 'Build Packet Monitor'
    ]
];