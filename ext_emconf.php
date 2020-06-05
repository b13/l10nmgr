<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Localization Manager',
    'description' => 'Module for managing localization import and export',
    'category' => 'module',
    'version' => '9.2.0',
    'state' => 'stable',
    'uploadfolder' => false,
    'createDirs' => 'uploads/tx_l10nmgr/settings,uploads/tx_l10nmgr/saved_files,uploads/tx_l10nmgr/jobs,uploads/tx_l10nmgr/jobs/out,uploads/tx_l10nmgr/jobs/in,uploads/tx_l10nmgr/jobs/done,uploads/tx_l10nmgr/jobs/_cmd',
    'clearCacheOnLoad' => true,
    'author' => 'Kasper Skaarhoej, Daniel Zielinski, Daniel Poetzinger, Fabian Seltmann, Andreas Otto, Jo Hasenau, Peter Russ',
    'author_email' => 'kasperYYYY@typo3.com, info@loctimize.com, info@cybercraft.de, pruss@uon.li',
    'author_company' => 'Localization Manager Team',
    'constraints' => [
        'depends' => [
            'typo3' => '9.5.0-10.4.99'
        ],
        'conflicts' => [],
        'suggests' => [
            'static_info_tables' => '6.4.2-0.0.0'
        ]
    ]
];
