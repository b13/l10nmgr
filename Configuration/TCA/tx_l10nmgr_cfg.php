<?php
$l10n = 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf';
return [
    'ctrl' => [
        'title' => $l10n . ':tx_l10nmgr_cfg',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'default_sortby' => 'ORDER BY title',
        'iconfile' => 'EXT:l10nmgr/Resources/Public/Icons/icon_tx_l10nmgr_cfg.gif',
    ],
    'feInterface' => [
        'fe_admin_fieldList' => 'title, depth, tablelist, exclude',
    ],
    'interface' => [
        'showRecordFieldList' => 'title,depth,pages,sourceLangStaticId,tablelist,exclude,exclude_tree,incfcewithdefaultlanguage,pretranslatecontent,overrideexistingtranslations'
    ],
    'columns' => [
        'title' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.title',
            'config' => [
                'type' => 'input',
                'size' => 48,
                'eval' => 'required',
            ]
        ],
        'filenameprefix' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.filenameprefix',
            'config' => [
                'type' => 'input',
                'size' => 48,
                'eval' => 'required',
            ]
        ],
        'depth' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.depth',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'onChange' => 'reload',
                'items' => [
                    [$l10n . ':tx_l10nmgr_cfg.depth.I.0', '0'],
                    [$l10n . ':tx_l10nmgr_cfg.depth.I.1', '1'],
                    [$l10n . ':tx_l10nmgr_cfg.depth.I.2', '2'],
                    [$l10n . ':tx_l10nmgr_cfg.depth.I.3', '3'],
                    [$l10n . ':tx_l10nmgr_cfg.depth.I.4', '100'],
                    [$l10n . ':tx_l10nmgr_cfg.depth.I.-1', '-1'],
                    [$l10n . ':tx_l10nmgr_cfg.depth.I.-2', '-2'],
                ],
                'size' => 1,
                'maxitems' => 1,
            ]
        ],
        'pages' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.pages',
            'displayCond' => 'FIELD:depth:<=:-2',
            'config' => [
                'type' => 'group',
                'internal_type' => 'db',
                'allowed' => 'pages',
                'size' => 5,
                'maxitems' => 100
            ]
        ],
        'displaymode' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.displaymode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [$l10n . ':tx_l10nmgr_cfg.displaymode.I.0', '0'],
                    [$l10n . ':tx_l10nmgr_cfg.displaymode.I.1', '1'],
                    [$l10n . ':tx_l10nmgr_cfg.displaymode.I.2', '2'],
                ],
                'size' => 1,
                'maxitems' => 1,
            ]
        ],
        'tablelist' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.tablelist',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'special' => 'tables',
                'size' => 5,
                'autoSizeMax' => 50,
                'maxitems' => 100,
            ]
        ],
        'exclude' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.exclude',
            'config' => [
                'type' => 'text',
                'cols' => 48,
                'rows' => 3,
            ]
        ],
        'exclude_tree' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.exclude_tree',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectTree',
                'foreign_table' => 'pages',
                'foreign_table_where' => 'AND pages.sys_language_uid=0 ORDER BY pages.sorting',
                'size' => 25,
                'treeConfig' => [
                    'parentField' => 'pid',
                    'appearance' => [
                        'expandAll' => false,
                        'showHeader' => true,
                    ],
                ],
            ]
        ],
        'include' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.include',
            'config' => [
                'type' => 'text',
                'cols' => 48,
                'rows' => 3,
            ]
        ],
        'metadata' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.metadata',
            'config' => [
                'readOnly' => 1,
                'type' => 'text',
                'cols' => 48,
                'rows' => 3,
            ]
        ],
        'sourceLangStaticId' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.sourceLang',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['', 0],
                ],
                'foreign_table' => 'static_languages',
                'foreign_table_where' => 'AND static_languages.pid=0 ORDER BY static_languages.lg_name_en',
                'size' => 1,
                'minitems' => 0,
                'maxitems' => 1,
            ]
        ],
        'incfcewithdefaultlanguage' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.incfcewithdefaultall',
            'config' => [
                'type' => 'check',
                'default' => 0
            ]
        ],
        'pretranslatecontent' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.pretranslatecontent',
            'config' => [
                'type' => 'check',
                'default' => 0
            ]
        ],
        'overrideexistingtranslations' => [
            'exclude' => 1,
            'label' => $l10n . ':tx_l10nmgr_cfg.overrideexistingtranslations',
            'config' => [
                'type' => 'check',
                'default' => 0
            ]
        ]
    ],
    'types' => [
        0 => ['showitem' => 'title,filenameprefix, depth, pages, sourceLangStaticId, tablelist, exclude, exclude_tree, include, metadata, displaymode, incfcewithdefaultlanguage, pretranslatecontent, overrideexistingtranslations']
    ],
    'palettes' => [
        '1' => ['showitem' => '']
    ]
];
