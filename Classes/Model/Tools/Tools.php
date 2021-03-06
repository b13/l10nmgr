<?php

namespace Localizationteam\L10nmgr\Model\Tools;

/***************************************************************
 * Copyright notice
 * (c) 2006 Kasper Skårhøj <kasperYYYY@typo3.com>
 * All rights reserved
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Contains translation tools
 * $Id: class.t3lib_loaddbgroup.php 1816 2006-11-26 00:43:24Z mundaun $
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 */

use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\BackendWorkspaceRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Contains translation tools
 *
 * @authorKasper Skaarhoj <kasperYYYY@typo3.com>
 * @packageTYPO3
 * @subpackage tx_l10nmgr
 */
class Tools
{
    protected $tcaTableTypeConfigurationCache;

    // External:
    /**
     * @var array
     */
    static $systemLanguages;
    /**
     * @var array
     */
    public $filters = [
        'fieldTypes' => 'text,input',
        'noEmptyValues' => true,
        'noIntegers' => true,
        'excludeTranslatedFields' => false,
        'l10n_categories' => '', // could be "text,media" for instance.
        // Never export those fields
        'excludes' => [
            'sys_file_reference' => [
                'table_local',
                'fieldname',
                'tablenames'
            ],
            'pages' => [
                'canonical_link'
            ]
        ]
    ];
    // Array of sys_language_uids, eg. array(1,2)
    /**
     * @var array
     */
    public $previewLanguages = []; // If TRUE, when fields are not included there will be shown a detailed explanation.
    /**
     * @var bool
     */
    public $verbose = true; // If TRUE, do not call filter function
    /**
     * @var bool
     */
    public $bypassFilter = false; //if set to true also FCE with language setting default will be included (not only All)
    /**
     * @var bool
     */
    public $includeFceWithDefaultLanguage = false; // Object to t3lib_transl8tools, set in constructor
    /**
     * @var null|TranslationConfigurationProvider
     */
    public $t8Tools = null; // Output for translation details
    // Internal:
    /**
     * @var array
     */
    protected $detailsOutput = []; // System languages initialized
    /**
     * @var array
     */
    protected $sysLanguages = []; // FlexForm diff data
    /**
     * @var array
     */
    protected $flexFormDiff = []; // System languages records, loaded by constructor
    /**
     * @var array|NULL
     */
    protected $sys_languages = [];
    /**
     * @var array
     */
    protected $indexFilterObjects = [];
    /**
     * @var array
     */
    protected $_callBackParams_translationDiffsourceXMLArray;
    /**
     * @var array
     */
    protected $_callBackParams_translationXMLArray;
    /**
     * @var array
     */
    protected $_callBackParams_previewLanguageXMLArrays;
    /**
     * @var string
     */
    protected $_callBackParams_keyForTranslationDetails;
    /**
     * @var array
     */
    protected $_callBackParams_currentRow;

    /**
     * Constructor
     * Setting up internal variable ->t8Tools
     */
    public function __construct()
    {
        $this->t8Tools = GeneralUtility::makeInstance(TranslationConfigurationProvider::class);
        // Find all system languages:
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_language');
        $this->sys_languages = $queryBuilder->select('*')->from('sys_language')->execute()->fetchAll();
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['filterManipulation'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['filterManipulation'] as $classReference) {
                $processingObject = GeneralUtility::makeInstance($classReference);
                $this->filters = $processingObject->manipulateFilters($this->filters);
            }
        }
    }

    /**
     * FlexForm call back function, see translationDetails
     *
     * @param array $dsArr Data Structure
     * @param string $dataValue Data value
     * @param array $PA Various stuff in an array
     * @param string $structurePath Path to location in flexform
     * @param FlexFormTools $pObj parent object
     * @return void
     */
    public function translationDetails_flexFormCallBack($dsArr, $dataValue, $PA, $structurePath, $pObj)
    {
        // Only take lead from default values (since this is "Inheritance" localization we parse for)
        if (substr($structurePath, -5) == '/vDEF') {
            // So, find translated value:
            $baseStructPath = substr($structurePath, 0, -3);
            $structurePath = $baseStructPath . $this->detailsOutput['ISOcode'];
            $translValue = $pObj->getArrayValueByPath($structurePath, $pObj->traverseFlexFormXMLData_Data);
            // Generate preview values:
            $previewLanguageValues = [];
            foreach ($this->previewLanguages as $prevSysUid) {
                $previewLanguageValues[$prevSysUid] = $pObj->getArrayValueByPath(
                    $baseStructPath . $this->sysLanguages[$prevSysUid]['ISOcode'],
                    $pObj->traverseFlexFormXMLData_Data
                );
            }
            $key = $ffKey = $PA['table'] . ':' . BackendUtility::wsMapId(
                    $PA['table'],
                    $PA['uid']
                ) . ':' . $PA['field'] . ':' . $structurePath;
            $ffKeyOrig = $PA['table'] . ':' . $PA['uid'] . ':' . $PA['field'] . ':' . $structurePath;
            // Now, in case this record has just been created in the workspace the diff-information is still found bound to the UID of the original record.
            // So we will look for that until it has been created for the workspace record:
            if (!is_array($this->flexFormDiff[$ffKey]) && is_array($this->flexFormDiff[$ffKeyOrig])) {
                $ffKey = $ffKeyOrig;
                // debug('orig...');
            }
            // Look for diff-value inside the XML (new way):
            if ($GLOBALS['TYPO3_CONF_VARS']['BE']['flexFormXMLincludeDiffBase']) {
                $diffDefaultValue = $pObj->getArrayValueByPath(
                    $structurePath . '.vDEFbase',
                    $pObj->traverseFlexFormXMLData_Data
                );
            } else {
                // Set diff-value from l10n-cfg record (deprecated)
                if (is_array($this->flexFormDiff[$ffKey]) && trim($this->flexFormDiff[$ffKey]['translated']) === trim($translValue)) {
                    $diffDefaultValue = $this->flexFormDiff[$ffKey]['default'];
                } else {
                    $diffDefaultValue = '';
                }
            }
            // Add field:
            $this->translationDetails_addField(
                $key,
                $dsArr['TCEforms'],
                $dataValue,
                $translValue,
                $diffDefaultValue,
                $previewLanguageValues
            );
        }
        unset($pObj);
    }

    /**
     * Add field to detailsOutput array. First, a lot of checks are done...
     *
     * @param string $key Key is a combination of table, uid, field and structure path, identifying the field
     * @param array $TCEformsCfg TCA configuration for field
     * @param string $dataValue Default value (current)
     * @param string $translationValue Translated value (current)
     * @param string $diffDefaultValue Default value of time of current translated value (used for diff'ing with $dataValue)
     * @param array $previewLanguageValues Array of preview language values identified by keys (which are sys_language uids)
     * @param array $contentRow Content row
     * @return void
     */
    protected function translationDetails_addField(
        $key,
        $TCEformsCfg,
        $dataValue,
        $translationValue,
        $diffDefaultValue = '',
        $previewLanguageValues = [],
        $contentRow = []
    ) {
        $msg = '';
        list($kTableName, , $kFieldName) = explode(':', $key);

        if (array_key_exists($kTableName, $this->filters['excludes']) && in_array($kFieldName, $this->filters['excludes'][$kTableName])) {
            return;
        }

        if ($TCEformsCfg['config']['type'] === 'flex') {
            if ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; fields of type "flex" can only be translated in the context of an "ALL" language record';
            }
            return;
        }

        if ($TCEformsCfg['l10n_mode'] === 'exclude') {
            if ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; "l10n_mode" for the field was "exclude" and field is not translated then.';
            }
            return;
        }

        if (GeneralUtility::isFirstPartOfStr($kFieldName, 't3ver_')) {
            if ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; Fieldname "' . $kFieldName . '" was prefixed "t3ver_"';
            }
            $this->bypassFilter = false;
            return;
        }

        if ($TCEformsCfg['l10n_mode'] == 'mergeIfNotBlank') {
            $msg .= 'This field is optional. If not filled in, the default language value will be used.';
        }
        if (GeneralUtility::inList('shortcut,shortcut_mode,urltype,url_scheme', $kFieldName) && $kTableName === 'pages') {
            $this->bypassFilter = true;
        }

        $is_HIDE_L10N_SIBLINGS = false;
        if (is_array($TCEformsCfg['displayCond'])) {
            $GLOBALS['is_HIDE_L10N_SIBLINGS'] = $is_HIDE_L10N_SIBLINGS;
            array_walk_recursive($TCEformsCfg['displayCond'], function ($i, $k) {
                if (GeneralUtility::isFirstPartOfStr($i, 'HIDE_L10N_SIBLINGS')) {
                    $GLOBALS['is_HIDE_L10N_SIBLINGS'] = true;
                }
            });
            $is_HIDE_L10N_SIBLINGS = $GLOBALS['is_HIDE_L10N_SIBLINGS'];
        } else {
            $is_HIDE_L10N_SIBLINGS = GeneralUtility::isFirstPartOfStr($TCEformsCfg['displayCond'], 'HIDE_L10N_SIBLINGS');
        }
        if ($is_HIDE_L10N_SIBLINGS) {
            if ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; displayCondition HIDE_L10N_SIBLINGS was set.';
            }
            $this->bypassFilter = false;
            return;
        }

        if ($this->filters['l10n_categories'] && !GeneralUtility::inList($this->filters['l10n_categories'], $TCEformsCfg['l10n_cat'])) {
            if ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; ->filters[l10n_categories] was set to "'
                    . $this->filters['l10n_categories'] . '" and l10n_cat for field ("' . $TCEformsCfg['l10n_cat'] . '") did not match.';
            }
            $this->bypassFilter = false;
            return;
        }


        if ($this->filters['fieldTypes']
            && !GeneralUtility::inList($this->filters['fieldTypes'], $TCEformsCfg['config']['type'])
            && !$this->bypassFilter
        ) {
            if ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; fields of type "' . $TCEformsCfg['config']['type'] . '" was filtered out in ->filters[fieldTypes]';
            }
            $this->bypassFilter = false;
            return;
        }

        if (!$this->filters['noEmptyValues'] || !(!$dataValue && !$translationValue)
            || !empty($previewLanguageValues[key($previewLanguageValues)]) || $TCEformsCfg['labelField'] === $kFieldName
        ) {
            // Checking that no translation value exists either; if a translation value is found it is considered that it should be translated
            // even if the default value is empty for some reason.
            if (!$this->filters['noIntegers'] || !MathUtility::canBeInterpretedAsInteger($dataValue) || $this->bypassFilter) {
                $this->detailsOutput['fields'][$key] = [
                    'defaultValue' => $dataValue,
                    'translationValue' => $translationValue,
                    'diffDefaultValue' => $TCEformsCfg['l10n_display'] != 'hideDiff' ? $diffDefaultValue : '',
                    'previewLanguageValues' => $previewLanguageValues,
                    'msg' => $msg,
                    'readOnly' => $TCEformsCfg['l10n_display'] == 'defaultAsReadonly',
                    'fieldType' => $TCEformsCfg['config']['type'],
                    'isRTE' => $this->_isRTEField($key, $TCEformsCfg, $contentRow)
                ];
            } elseif ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; ->filters[noIntegers] was set and dataValue "' . $dataValue . '" was an integer';
            }
        } elseif ($this->verbose) {
            $this->detailsOutput['fields'][$key] = 'Bypassing; ->filters[noEmptyValues] was set and dataValue "'
                . $dataValue . '" was empty an field was no label field and no translation or alternative source language value found either.';
        }
        $this->bypassFilter = false;
    }

    /**
     * Check if the field is an RTE in the Backend, for a given row of data
     *
     * @param string $key Key is a combination of table, uid, field and structure path, identifying the field
     * @param array $TCEformsCfg TCA configuration for field
     * @param array $contentRow The table row being handled
     * @return boolean
     */
    protected function _isRTEField($key, $TCEformsCfg, $contentRow)
    {
        $isRTE = false;
        if (is_array($contentRow)) {
            list($table, $uid, $field) = explode(':', $key);
            // Check if the RTE is explicitly declared in the defaultExtras configuration
            if (isset($TCEformsCfg['defaultExtras']) && strpos($TCEformsCfg['defaultExtras'], 'richtext') !== false) {
                $isRTE = true;
                // If not, then we must check per type configuration
            } else {
                $typesDefinition = $this->getTCAtypes($table, $contentRow, true);
                $isRTE = !empty($typesDefinition[$field]['spec']['richtext']);
            }
        }
        return $isRTE;
    }

    /**
     * FlexForm call back function, see translationDetails. This is used for langDatabaseOverlay FCEs!
     * Two additional paramas are used:
     * $this->_callBackParams_translationXMLArray
     * $this->_callBackParams_keyForTranslationDetails
     *
     * @param array $dsArr Data Structure
     * @param string $dataValue Data value
     * @param array $PA Various stuff in an array
     * @param string $structurePath Path to location in flexform
     * @param FlexFormTools $pObj parent object
     * @return void
     */
    public function translationDetails_flexFormCallBackForOverlay($dsArr, $dataValue, $PA, $structurePath, $pObj)
    {
        //echo $dataValue.'<hr>';
        $translValue = $pObj->getArrayValueByPath($structurePath, $this->_callBackParams_translationXMLArray);
        $diffDefaultValue = $pObj->getArrayValueByPath(
            $structurePath,
            $this->_callBackParams_translationDiffsourceXMLArray
        );
        $previewLanguageValues = [];
        foreach ($this->previewLanguages as $prevSysUid) {
            $previewLanguageValues[$prevSysUid] = $pObj->getArrayValueByPath(
                $structurePath,
                $this->_callBackParams_previewLanguageXMLArrays[$prevSysUid]
            );
        }
        $key = $this->_callBackParams_keyForTranslationDetails . ':' . $structurePath;
        $this->translationDetails_addField(
            $key,
            $dsArr['TCEforms'],
            $dataValue,
            $translValue,
            $diffDefaultValue,
            $previewLanguageValues,
            $this->_callBackParams_currentRow
        );
        unset($pObj);
    }

    /**
     * Update index for record
     *
     * @param string $table Table name
     * @param int $uid UID
     * @return string
     */
    public function updateIndexForRecord($table, $uid)
    {
        $output = '';
        if ($table == 'pages') {
            $items = $this->indexDetailsPage($uid);
        } else {
            $items = [];
            if ($tmp = $this->indexDetailsRecord($table, $uid)) {
                $items[$table][$uid] = $tmp;
            }
        }
        if (count($items)) {
            foreach ($items as $tt => $rr) {
                foreach ($rr as $rUid => $rDetails) {
                    $this->updateIndexTableFromDetailsArray($rDetails);
                    $output .= 'Updated <em>' . $tt . ':' . $rUid . '</em></br>';
                }
            }
        } else {
            $output .= 'No records to update (you can only update records that can actually be translated)';
        }
        return $output;
    }

    /**
     * Creating localization index for all records on a page
     *
     * @param integer $pageId Page ID
     * @param int $previewLanguage
     * @return array Array of the traversed items
     */
    public function indexDetailsPage($pageId, $previewLanguage = 0)
    {

        $items = [];
        // Traverse tables:
        foreach ($GLOBALS['TCA'] as $table => $cfg) {
            // Only those tables we want to work on:
            if ($table === 'pages') {
                $items[$table][$pageId] = $this->indexDetailsRecord('pages', $pageId, $previewLanguage);
            } else {
                $allRows = $this->getRecordsToTranslateFromTable($table, $pageId);
                if (is_array($allRows)) {
                    if (count($allRows)) {
                        // Now, for each record, look for localization:
                        foreach ($allRows as $row) {
                            if (is_array($row)) {
                                $items[$table][$row['uid']] = $this->indexDetailsRecord(
                                    $table,
                                    $row['uid'],
                                    $previewLanguage
                                );
                            }
                        }
                    }
                }
            }
        }
        return $items;
    }

    /**
     * Creating localization index for a single record (which must be default/international language and an online version!)
     *
     * @param string $table Table name
     * @param integer $uid Record UID
     * @param integer|NULL $languageID Language ID of the record
     * @return mixed FALSE if the input record is not one that can be translated. Otherwise an array holding information about the status.
     */
    public function indexDetailsRecord($table, $uid, $languageID = null)
    {
        $rec = $table == 'pages'
            ? BackendUtility::getRecord($table, $uid)
            : $this->getSingleRecordToTranslate($table, $uid, $languageID);

        if (is_array($rec) && $rec['pid'] != -1) {
            $pid = $table == 'pages' ? $rec['uid'] : $rec['pid'];
            if ($this->bypassFilter || $this->filterIndex($table, $uid, $pid)) {
                BackendUtility::workspaceOL($table, $rec);
                $items = [];
                foreach ($this->sys_languages as $r) {
                    if (is_null($languageID) || $r['uid'] === $languageID) {
                        $items['fullDetails'][$r['uid']] = $this->translationDetails(
                            $table,
                            $rec,
                            $r['uid'],
                            $languageID
                        );
                        $items['indexRecord'][$r['uid']] = $this->compileIndexRecord(
                            $items['fullDetails'][$r['uid']],
                            $r['uid'],
                            $pid
                        );
                    }
                }
                return $items;
            }
        }
        return false;
    }

    /**
     * Selecting single record from a table filtering whether it is a default language / international element.
     *
     * @param string $table Table name
     * @param integer $uid Record uid
     * @param integer $previewLanguage
     * @return array | bool Record array if found, otherwise FALSE
     */
    protected function getSingleRecordToTranslate($table, $uid, $previewLanguage = 0)
    {
        if (!isset($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
            return false;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(BackendWorkspaceRestriction::class));
        $queryBuilder->select('*')->from($table);
        if ($previewLanguage > 0) {
            $constraints = [];
            $constraints[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter($previewLanguage, \PDO::PARAM_INT)
            );

            if (isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                $constraints[] = $queryBuilder->expr()->eq(
                    $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                );
            }

            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter((int)$uid, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->lte(
                            $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                            $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                        ),
                        $queryBuilder->expr()->andX(...$constraints)
                    )
                )
            );
        } else {
            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter((int)$uid, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->lte(
                        $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                        $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                    )
                )
            );
        }

        // First, select all records that are default language OR international:
        $allRows = $queryBuilder->execute()->fetchAll();
        return is_array($allRows) && count($allRows) ? $allRows[0] : false;
    }

    /**
     * Returns true if the record can be included in index.
     *
     * @param $table
     * @param $uid
     * @param $pageId
     * @return bool
     */
    protected function filterIndex($table, $uid, $pageId)
    {
        // Initialize (only first time)
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['indexFilter']) && !is_array($this->indexFilterObjects[$pageId])) {
            $this->indexFilterObjects[$pageId] = [];
            $c = 0;
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['indexFilter'] as $objArray) {
                $this->indexFilterObjects[$pageId][$c] = GeneralUtility::makeInstance($objArray[0]);
                $this->indexFilterObjects[$pageId][$c]->init($pageId);
                $c++;
            }
        }
        // Check record:
        if (is_array($this->indexFilterObjects[$pageId])) {
            foreach ($this->indexFilterObjects[$pageId] as $obj) {
                if (!$obj->filter($table, $uid)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Generate details about translation
     *
     * @param string $table Table name
     * @param array $row Row (one from getRecordsToTranslateFromTable())
     * @param integer $sysLang sys_language uid
     * @param array $flexFormDiff FlexForm diff data
     * @param integer $previewLanguage previewLanguage
     * @return array Returns details array
     */
    public function translationDetails($table, $row, $sysLang, $flexFormDiff = [], $previewLanguage = 0)
    {
        // Initialize:
        $tInfo = $this->translationInfo($table, $row['uid'], $sysLang, null, '', $previewLanguage);
        $this->detailsOutput = [];
        $this->flexFormDiff = $flexFormDiff;
        if (is_array($tInfo)) {
            // Initialize some more:
            $this->detailsOutput['translationInfo'] = $tInfo;
            $this->sysLanguages = $this->getSystemLanguages();
            $this->detailsOutput['ISOcode'] = $this->sysLanguages[$sysLang]['ISOcode'];
            // decide how translations are stored:
            // there are three ways: flexformInternalTranslation (for FCE with langChildren)
            // useOverlay (for elements with classic overlay record)
            // noTranslation
            $translationModes = $this->_detectTranslationModes($tInfo, $table, $row);
            foreach ($translationModes as $translationMode) {
                switch ($translationMode) {
                    case 'flexformInternalTranslation':
                        $this->detailsOutput['log'][] = 'Mode: flexFormTranslation with no translation set; looking for flexform fields';
                        $this->_lookForFlexFormFieldAndAddToInternalTranslationDetails($table, $row);
                        break;
                    case 'useOverlay':
                        if (count($tInfo['translations'])) {
                            if ($this->filters['excludeTranslatedFields']) {
                                continue 2;
                            }
                            $this->detailsOutput['log'][] = 'Mode: translate existing record';
                            $translationUID = $tInfo['translations'][$sysLang]['uid'];
                            $translationRecord = BackendUtility::getRecordWSOL(
                                $tInfo['translation_table'],
                                $tInfo['translations'][$sysLang]['uid']
                            );
                        } else {
                            // Will also suggest to translate a default language record which are in a container block with Inheritance or Separate mode.
                            // This might not be something people wish, but there is no way we can prevent it because its a deprecated localization paradigm
                            // to use container blocks with localization. The way out might be setting the language to "All" for such elements.
                            $this->detailsOutput['log'][] = 'Mode: translate to new record';
                            $translationUID = 'NEW/' . $sysLang . '/' . $row['uid'];
                            $translationRecord = [];
                        }
                        if ($GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigDiffSourceField']) {
                            $diffArray = unserialize($translationRecord[$GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigDiffSourceField']]);
                            // debug($diffArray);
                        } else {
                            $diffArray = [];
                        }
                        $prevLangRec = [];
                        foreach ($this->previewLanguages as $prevSysUid) {
                            $prevLangInfo = $this->translationInfo(
                                $table,
                                $row['uid'],
                                $prevSysUid,
                                null,
                                '',
                                $previewLanguage
                            );
                            if (!empty($prevLangInfo) && $prevLangInfo['translations'][$prevSysUid]) {
                                $prevLangRec[$prevSysUid] = BackendUtility::getRecordWSOL(
                                    $prevLangInfo['translation_table'],
                                    $prevLangInfo['translations'][$prevSysUid]['uid']
                                );
                            } else {
                                $prevLangRec[$prevSysUid] = BackendUtility::getRecordWSOL(
                                    $prevLangInfo['translation_table'],
                                    $row['uid']
                                );
                            }
                        }
                        foreach ($GLOBALS['TCA'][$tInfo['translation_table']]['columns'] as $field => $cfg) {
                            $cfg['labelField'] = trim($GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['label']);
                            if ($GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['languageField'] !== $field
                                && $GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigPointerField'] !== $field
                                && $GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigDiffSourceField'] !== $field
                            ) {
                                $key = $tInfo['translation_table'] . ':' . BackendUtility::wsMapId(
                                        $tInfo['translation_table'],
                                        $translationUID
                                    ) . ':' . $field;
                                if ($cfg['config']['type'] == 'flex') {
                                    $dataStructArray = $this->_getFlexFormMetaDataForContentElement(
                                        $table,
                                        $field,
                                        $row
                                    );
                                    if ($dataStructArray['meta']['langDisable']
                                        && $dataStructArray['meta']['langDatabaseOverlay'] == 1
                                        || $table === 'tt_content' && $row['CType'] === 'fluidcontent_content'
                                    ) {
                                        // Create and call iterator object:
                                        /** @var FlexFormTools $flexObj */
                                        $flexObj = GeneralUtility::makeInstance(FlexFormTools::class);
                                        $this->_callBackParams_keyForTranslationDetails = $key;
                                        $this->_callBackParams_translationXMLArray = GeneralUtility::xml2array($translationRecord[$field]);
                                        if (is_array($translationRecord)) {
                                            $diffsource = unserialize($translationRecord['l18n_diffsource']);
                                            $this->_callBackParams_translationDiffsourceXMLArray = GeneralUtility::xml2array($diffsource[$field]);
                                        }
                                        foreach ($this->previewLanguages as $prevSysUid) {
                                            $this->_callBackParams_previewLanguageXMLArrays[$prevSysUid] = GeneralUtility::xml2array($prevLangRec[$prevSysUid][$field]);
                                        }
                                        $this->_callBackParams_currentRow = $row;
                                        $flexObj->traverseFlexFormXMLData(
                                            $table,
                                            $field,
                                            $row,
                                            $this,
                                            'translationDetails_flexFormCallBackForOverlay'
                                        );
                                    }
                                    $this->detailsOutput['log'][] = 'Mode: useOverlay looking for flexform fields!';
                                } else {
                                    // handle normal fields:
                                    $diffDefaultValue = $diffArray[$field];
                                    $previewLanguageValues = [];
                                    foreach ($this->previewLanguages as $prevSysUid) {
                                        $previewLanguageValues[$prevSysUid] = $prevLangRec[$prevSysUid][$field];
                                    }
                                    // debug($row[$field]);
                                    $this->translationDetails_addField(
                                        $key,
                                        $cfg,
                                        $row[$field],
                                        $translationRecord[$field],
                                        $diffDefaultValue,
                                        $previewLanguageValues,
                                        $row
                                    );
                                }
                            }
                            // elseif ($cfg[
                        }
                        break;
                }
            } // foreach translationModes
        } else {
            $this->detailsOutput['log'][] = 'ERROR: ' . $tInfo;
        }
        return $this->detailsOutput;
    }

    /**
     * Information about translation for an element
     * Will overlay workspace version of record too!
     *
     * @param string $table Table name
     * @param integer $uid Record uid
     * @param integer $sys_language_uid Language uid. If zero, then all languages are selected.
     * @param array $row The record to be translated
     * @param array|string $selFieldList Select fields for the query which fetches the translations of the current record
     * @param integer $previewLanguage
     * @return array | string Array with information. Errors will return string with message.
     * @todo Define visibility
     */
    public function translationInfo(
        $table,
        $uid,
        $sys_language_uid = 0,
        $row = null,
        $selFieldList = '',
        $previewLanguage = 0
    ) {
        if (!$GLOBALS['TCA'][$table] || !$uid) {
            return 'No table "' . $table . '" or no UID value';
        }

        if ($row === null) {
            $row = BackendUtility::getRecordWSOL($table, $uid);
        }
        if (!is_array($row)) {
            return 'Record "' . $table . '_' . $uid . '" was not found';
        }

        if ($row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] > 0 && (int)$row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] !== (int)$previewLanguage) {
            return 'Record "' . $table . '_' . $uid . '" seems to be a translation already (has a language value "'
                . $row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] . '", relation to record "'
                . $row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] . '")';
        }

        if ((int)$row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] !== 0) {
            return 'Record "' . $table . '_' . $uid . '" seems to be a translation already (has a relation to record "'
                . $row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] . '")';
        }

        if (!empty($selFieldList)) {
            $selectFields = GeneralUtility::trimExplode(',', $selFieldList);
        } else {
            $selectFields = [
                'uid',
                $GLOBALS['TCA'][$table]['ctrl']['languageField']
            ];
        }

        $constraints = [];
        $constraintsA = [];
        $constraintsB = [];

        // Look for translations of this record, index by language field value:
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(BackendWorkspaceRestriction::class));

        $constraintsA[] = $queryBuilder->expr()->eq(
            $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
            $queryBuilder->createNamedParameter((int)$uid, \PDO::PARAM_INT)
        );

        $constraintsA[] = $queryBuilder->expr()->eq(
            'pid',
            $queryBuilder->createNamedParameter((int)$row['pid'], \PDO::PARAM_INT)
        );

        if ((int)$sys_language_uid === 0) {
            $constraintsA[] = $queryBuilder->expr()->gt(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
            );
        } else {
            $constraintsA[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter((int)$sys_language_uid, \PDO::PARAM_INT)
            );
        }

        if ($previewLanguage > 0) {
            $constraintsB[] = $queryBuilder->expr()->eq(
                'pid',
                $queryBuilder->createNamedParameter((int)$row['pid'], \PDO::PARAM_INT)
            );
            $constraintsB[] = $queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter((int)$uid, \PDO::PARAM_INT)
            );
            $constraintsB[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
            );
            $constraintsB[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter((int)$previewLanguage, \PDO::PARAM_INT)
            );

            $constraints[] = $queryBuilder->expr()->orX(
                $queryBuilder->expr()->andX(...$constraintsA),
                $queryBuilder->expr()->andX(...$constraintsB)
            );
        } else {
            $constraints = $constraintsA;
        }

        $translationsTemp = $queryBuilder->select(...$selectFields)
            ->from($table)
            ->where(...$constraints)
            ->execute()
            ->fetchAll();

        $translations = [];
        $translations_errors = [];
        foreach ($translationsTemp as $r) {
            if (!isset($translations[$r[$GLOBALS['TCA'][$table]['ctrl']['languageField']]])) {
                $translations[$r[$GLOBALS['TCA'][$table]['ctrl']['languageField']]] = $r;
            } else {
                $translations_errors[$r[$GLOBALS['TCA'][$table]['ctrl']['languageField']]][] = $r;
            }
        }
        return [
            'table' => $table,
            'uid' => $uid,
            'CType' => $row['CType'],
            'sys_language_uid' => $row[$GLOBALS['TCA'][$table]['ctrl']['languageField']],
            'translation_table' => $table,
            'translations' => $translations,
            'excessive_translations' => $translations_errors
        ];
    }

    /**
     * @return array
     */
    protected function getSystemLanguages()
    {
        if (is_null(self::$systemLanguages)) {
            self::$systemLanguages = $this->t8Tools->getSystemLanguages();
        }
        return self::$systemLanguages;
    }

    /**
     * Function checks which translationMode is used. Mainly it checks the FlexForm (FCE) logic and language returns a array with useOverlay | flexformInternalTranslation
     *
     * @param array $tInfo Translation info
     * @param string $table Table name
     * @param array $row Table row
     * @return array
     */
    protected function _detectTranslationModes($tInfo, $table, $row)
    {
        $translationModes = [];
        if ($table === 'pages') {
            $translationModes[] = 'flexformInternalTranslation';
            $this->detailsOutput['log'][] = 'Mode: "flexformInternalTranslation" detected because we have page Record';
        }
        $useOverlay = false;
        if (count($tInfo['translations']) && $tInfo['sys_language_uid'] != -1) {
            $translationModes[] = 'useOverlay';
            $useOverlay = true;
            $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have existing overlayrecord and language is not "ALL"';
        }
        if ($row['CType'] == 'templavoila_pi1' && !$useOverlay) {
            if (($this->includeFceWithDefaultLanguage && $tInfo['sys_language_uid'] == 0) || $tInfo['sys_language_uid'] == -1) {
                $dataStructArray = $this->_getFlexFormMetaDataForContentElement($table, 'tx_templavoila_flex', $row);
                if (is_array($dataStructArray) && $dataStructArray !== false) {
                    if ($dataStructArray['meta']['langDisable']) {
                        if ($dataStructArray['meta']['langDatabaseOverlay'] == 1) {
                            $translationModes[] = 'useOverlay';
                            $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have FCE with langDatabaseOverlay configured';
                        } else {
                            $this->detailsOutput['log'][] = 'Mode: "noTranslation" detected because we have FCE with langDisable';
                        }
                    } elseif ($dataStructArray['meta']['langChildren']) {
                        $translationModes[] = 'flexformInternalTranslation';
                        $this->detailsOutput['log'][] = 'Mode: "flexformInternalTranslation" detected because we have FCE with langChildren';
                    } elseif ($table === 'tt_content' && $row['CType'] === 'fluidcontent_content') {
                        $translationModes[] = 'useOverlay';
                        $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have Fluidcontent content';
                    }
                } else {
                    $this->detailsOutput['log'][] = 'Mode: "noTranslation" detected because we have corrupt Datastructure!';
                }
            } else {
                $this->detailsOutput['log'][] = 'Mode: "noTranslation" detected because we FCE in Default Language and its not cofigured to include FCE in Default language';
            }
        } elseif ($tInfo['sys_language_uid'] == 0 && $tInfo['translation_table']) {
            //no FCE
            $translationModes[] = 'useOverlay';
            $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have a normal record (no FCE) in default language';
        }
        return array_unique($translationModes);
    }

    /**
     * Return meta data of flexform field, or false if no flexform is found
     *
     * @param string $table Name of the table
     * @param string $field Name of the field
     * @param array $row Current row of data
     * @return array|boolean Flexform structure (or false, if not found)
     */
    protected function _getFlexFormMetaDataForContentElement($table, $field, $row)
    {
        $conf = $GLOBALS['TCA'][$table]['columns'][$field];
        $dataStructArray = [];
        $dataStructIdentifier = GeneralUtility::makeInstance(FlexFormTools::class)->getDataStructureIdentifier(
            $conf,
            $table,
            $field,
            $row
        );
        if (!empty($dataStructIdentifier)) {
            $dataStructArray = GeneralUtility::makeInstance(FlexFormTools::class)->parseDataStructureByIdentifier($dataStructIdentifier);
        }
        if (!empty($dataStructArray)) {
            return $dataStructArray;
        } else {
            $dataStructArray = BackendUtility::getFlexFormDS($conf, $row, $table, $field);
            if (is_array($dataStructArray)) {
                return $dataStructArray;
            }
        }
        return false;
    }

    /**
     * Look for flexform field and add to internal translation details
     *
     * @param string $table Table name
     * @param array $row Table row
     * @return void
     */
    protected function _lookForFlexFormFieldAndAddToInternalTranslationDetails($table, $row)
    {

        foreach ($GLOBALS['TCA'][$table]['columns'] as $field => $conf) {
            // For "flex" fieldtypes we need to traverse the structure looking for file and db references of course!
            if ($conf['config']['type'] == 'flex') {
                // We might like to add the filter that detects if record is tt_content/CType is "tx_flex...:"
                // since otherwise we would translate flexform content that might be hidden if say the record had a DS
                // set but was later changed back to "Text w/Image" or so... But probably this is a rare case.
                // Get current data structure to see if translation is needed:
                $dataStructArray = [];
                $dataStructIdentifier = GeneralUtility::makeInstance(FlexFormTools::class)->getDataStructureIdentifier(
                    $conf,
                    $table,
                    $field,
                    $row
                );
                if (!empty($dataStructIdentifier)) {
                    $dataStructArray = GeneralUtility::makeInstance(FlexFormTools::class)->parseDataStructureByIdentifier($dataStructIdentifier);
                }
                $this->detailsOutput['log'][] = 'FlexForm field "' . $field . '": DataStructure status: ' . (!empty($dataStructArray) ? 'OK' : 'Error: ' . $dataStructArray);
                if (!empty($dataStructArray) && !$dataStructArray['meta']['langDisable']) {
                    $this->detailsOutput['log'][] = 'FlexForm Localization enabled, type: ' . ($dataStructArray['meta']['langChildren'] ? 'Inheritance: Continue' : 'Separate: Stop');
                    if ($dataStructArray['meta']['langChildren']) {
                        $currentValueArray = GeneralUtility::xml2array($row[$field]);
                        // Traversing the XML structure, processing files:
                        if (is_array($currentValueArray)) {
                            // Create and call iterator object:
                            /** @var FlexFormTools $flexObj */
                            $flexObj = GeneralUtility::makeInstance(FlexFormTools::class);
                            $flexObj->traverseFlexFormXMLData(
                                $table,
                                $field,
                                $row,
                                $this,
                                'translationDetails_flexFormCallBack'
                            );
                        }
                    }
                } else {
                    $this->detailsOutput['log'][] = 'FlexForm Localization disabled. Nothing to do.';
                }
            }
        }
    }

    /**
     * Creates the record to insert in the index table.
     *
     * @param array $fullDetails Details as fetched (as gotten by ->translationDetails())
     * @param integer $sys_lang The language UID for which this record is made
     * @param integer $pid PID of record
     * @return array Record.
     */
    protected function compileIndexRecord($fullDetails, $sys_lang, $pid)
    {
        $record = [
            'hash' => '',
            'tablename' => $fullDetails['translationInfo']['table'],
            'recuid' => (int)$fullDetails['translationInfo']['uid'],
            'recpid' => $pid,
            'sys_language_uid' => (int)$fullDetails['translationInfo']['sys_language_uid'],
            // can be zero (default) or -1 (international)
            'translation_lang' => $sys_lang,
            'translation_recuid' => (int)$fullDetails['translationInfo']['translations'][$sys_lang]['uid'],
            'workspace' => $this->getBackendUser()->workspace,
            'serializedDiff' => [],
            'flag_new' => 0,
            // Something awaits to get translated => Put to TODO list as a new element
            'flag_unknown' => 0,
            // Status of this is unknown, probably because it has been "localized" but not yet translated from the default language => Put to TODO LIST as a priority
            'flag_noChange' => 0,
            // If only "noChange" is set for the record, all is well!
            'flag_update' => 0,
            // This indicates something to update
        ];
        if (is_array($fullDetails['fields'])) {
            foreach ($fullDetails['fields'] as $key => $tData) {
                if (is_array($tData)) {
                    list(, $uidString, $fieldName, $extension) = explode(':', $key);
                    list($uidValue) = explode('/', $uidString);
                    $noChangeFlag = !strcmp(trim($tData['diffDefaultValue']), trim($tData['defaultValue']));
                    if ($uidValue === 'NEW') {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= '';
                        $record['flag_new']++;
                    } elseif (!isset($tData['diffDefaultValue'])) {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= '<em>No diff available</em>';
                        $record['flag_unknown']++;
                    } elseif ($noChangeFlag) {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= '';
                        $record['flag_noChange']++;
                    } else {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= $this->diffCMP(
                            $tData['diffDefaultValue'],
                            $tData['defaultValue']
                        );
                        $record['flag_update']++;
                    }
                }
            }
        }
        $record['serializedDiff'] = serialize($record['serializedDiff']);
        $record['hash'] = md5($record['tablename'] . ':' . $record['recuid'] . ':' . $record['translation_lang'] . ':' . $record['workspace']);
        return $record;
    }

    /**
     * Returns the Backend User
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Diff-compare markup
     *
     * @param string $old Old content
     * @param string $new New content
     * @return string Marked up string.
     */
    protected function diffCMP($old, $new)
    {
        // Create diff-result:
        /** @var DiffUtility $t3lib_diff_Obj */
        $t3lib_diff_Obj = GeneralUtility::makeInstance(DiffUtility::class);
        return $t3lib_diff_Obj->makeDiffDisplay($old, $new);
    }

    /**
     * Selecting records from a table from a page which are candidates to be translated.
     *
     * @param string $table Table name
     * @param integer $pageId Page id
     * @param integer $previewLanguage
     * @return array Array of records from table (with all fields selected)
     */
    public function getRecordsToTranslateFromTable($table, $pageId, $previewLanguage = 0)
    {
        if (!isset($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
            return [];
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(BackendWorkspaceRestriction::class));

        // Check for disabled field settings
        // print "###".$this->getBackendUser()->uc['moduleData']['xMOD_tx_l10nmgr_cm1']['noHidden']."---";
        if (!empty($this->getBackendUser()->uc['moduleData']['LocalizationManager']['noHidden'])) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $queryBuilder->select('*')
            ->from($table);

        if ($previewLanguage > 0) {
            $constraints = [];
            $constraints[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter($previewLanguage, \PDO::PARAM_INT)
            );

            if (isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                $constraints[] = $queryBuilder->expr()->eq(
                    $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                );
            }

            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter((int)$pageId, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->lte(
                            $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                            $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                        ),
                        $queryBuilder->expr()->andX(...$constraints)
                    )
                )
            );
        } else {
            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter((int)$pageId, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->lte(
                        $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                        $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                    )
                )
            );
        }

        return $queryBuilder->execute()->fetchAll();
    }

    /**
     * Update translation index table based on a "details" record (made by indexDetailsRecord())
     *
     * @param array $rDetails See output of indexDetailsRecord()
     * @param boolean $echo If true, will output log information for each insert
     * @return void
     */
    public function updateIndexTableFromDetailsArray($rDetails, $echo = false)
    {
        if ($rDetails && is_array($rDetails['indexRecord']) && count($rDetails['indexRecord'])) {
            foreach ($rDetails['indexRecord'] as $rIndexRecord) {
                if ($echo) {
                    echo "Inserting " . $rIndexRecord['tablename'] . ':' . $rIndexRecord['recuid']
                        . ':' . $rIndexRecord['translation_lang'] . ':' . $rIndexRecord['workspace'] . chr(10);
                }
                $this->updateIndexTable($rIndexRecord);
            }
        }
    }

    /**
     * Updates translation index table with input record
     *
     * @param array $record Array (generated with ->compileIndexRecord())
     * @return void
     */
    protected function updateIndexTable($record)
    {
        $databaseConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_l10nmgr_index');

        $databaseConnection->delete(
            'tx_l10nmgr_index',
            ['hash' => $record['hash']]
        );

        $databaseConnection->insert('tx_l10nmgr_index', $record);
    }

    /**
     * Flush Index Of Workspace - removes all index records for workspace - useful to nightly build-up of the index.
     *
     * @param int $ws Workspace ID
     * @return void
     */
    public function flushIndexOfWorkspace($ws)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_l10nmgr_index');
        $queryBuilder->delete('tx_l10nmgr_index')
            ->where(
                $queryBuilder->expr()->eq(
                    'workspace',
                    $queryBuilder->createNamedParameter((int)$ws, \PDO::PARAM_INT)
                )
            )
            ->execute();
    }

    /**
     * @param string $table Table name
     * @param int $uid UID
     * @param bool $exec Execution flag
     * @return array
     */
    public function flushTranslations($table, $uid, $exec = false)
    {
        /** @var FlexFormTools $flexToolObj */
        $flexToolObj = GeneralUtility::makeInstance(FlexFormTools::class);
        $TCEmain_data = [];
        $TCEmain_cmd = [];
        // Simply collecting information about indexing on a page to assess what has to be flushed. Maybe this should move to be an API in
        if ($table == 'pages') {
            $items = $this->indexDetailsPage($uid);
        } else {
            $items = [];
            if ($tmp = $this->indexDetailsRecord($table, $uid)) {
                $items[$table][$uid] = $tmp;
            }
        }
        $remove = [];
        if (count($items)) {
            foreach ($items as $tt => $rr) {
                foreach ($rr as $rUid => $rDetails) {
                    if (is_array($rDetails['fullDetails'])) {
                        foreach ($rDetails['fullDetails'] as $infoRec) {
                            $tInfo = $infoRec['translationInfo'];
                            if (is_array($tInfo)) {
                                $flexFormTranslation = $tInfo['sys_language_uid'] == -1 && !count($tInfo['translations']);
                                // Flexforms:
                                if ($flexFormTranslation || $table === 'pages') {
                                    if (is_array($infoRec['fields'])) {
                                        foreach ($infoRec['fields'] as $theKey => $theVal) {
                                            $pp = explode(':', $theKey);
                                            if ($pp[3] && $pp[0] === $tt && (int)$pp[1] === (int)$rUid) {
                                                $remove['resetFlexFormFields'][$tt][$rUid][$pp[2]][] = $pp[3];

                                                if (!is_array($TCEmain_data[$tt][$rUid][$pp[2]])) {
                                                    $TCEmain_data[$tt][$rUid][$pp[2]] = [];
                                                }
                                                $flexToolObj->setArrayValueByPath(
                                                    $pp[3],
                                                    $TCEmain_data[$tt][$rUid][$pp[2]],
                                                    ''
                                                );
                                            }
                                        }
                                    }
                                }
                                // Looking for translations of element in terms of records. Those should be deleted then.
                                if (!$flexFormTranslation && is_array($tInfo['translations'])) {
                                    foreach ($tInfo['translations'] as $translationChildToRemove) {
                                        $remove['deleteRecords'][$tInfo['translation_table']][$translationChildToRemove['uid']] = $translationChildToRemove;
                                        $TCEmain_cmd[$tInfo['translation_table']][$translationChildToRemove['uid']]['delete'] = 1;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $errorLog = '';
        if ($exec) {
            // Now, submitting translation data:
            /** @var DataHandler $tce */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->dontProcessTransformations = true;
            $tce->isImporting = true;
            $tce->start($TCEmain_data, $TCEmain_cmd); // check has been done previously that there is a backend user which is Admin and also in live workspace
            $tce->process_datamap();
            $tce->process_cmdmap();
            $errorLog = $tce->errorLog;
        }
        return [$remove, $TCEmain_cmd, $TCEmain_data, $errorLog];
    }



    /**
     * Returns the "types" configuration parsed into an array for the record, $rec, from table, $table
     *
     * @param string $table Table name (present in TCA)
     * @param array $rec Record from $table
     * @param bool $useFieldNameAsKey If $useFieldNameAsKey is set, then the fieldname is associative keys in the return array, otherwise just numeric keys.
     * @return array|null
     */
    public function getTCAtypes($table, $rec, $useFieldNameAsKey = false)
    {
        if (isset($GLOBALS['TCA'][$table])) {
            // Get type value:
            $fieldValue = $this->getTCAtypeValue($table, $rec);
            $cacheIdentifier = $table . '-type-' . $fieldValue . '-fnk-' . $useFieldNameAsKey;

            // Fetch from first-level-cache if available
            if (isset($this->tcaTableTypeConfigurationCache[$cacheIdentifier])) {
                return $this->tcaTableTypeConfigurationCache[$cacheIdentifier];
            }

            // Get typesConf
            $typesConf = $GLOBALS['TCA'][$table]['types'][$fieldValue] ?? null;
            // Get fields list and traverse it
            $fieldList = explode(',', $typesConf['showitem']);

            // Add subtype fields e.g. for a valid RTE transformation
            // The RTE runs the DB -> RTE transformation only, if the RTE field is part of the getTCAtypes array
            if (isset($typesConf['subtype_value_field'])) {
                $subType = $rec[$typesConf['subtype_value_field']];
                if (isset($typesConf['subtypes_addlist'][$subType])) {
                    $subFields = GeneralUtility::trimExplode(',', $typesConf['subtypes_addlist'][$subType], true);
                    $fieldList = array_merge($fieldList, $subFields);
                }
            }

            // Add palette fields e.g. for a valid RTE transformation
            $paletteFieldList = [];
            foreach ($fieldList as $fieldData) {
                $fieldDataArray = GeneralUtility::trimExplode(';', $fieldData);
                // first two entries would be fieldname and altTitle, they are not used here.
                $pPalette = $fieldDataArray[2] ?? null;
                if ($pPalette
                    && isset($GLOBALS['TCA'][$table]['palettes'][$pPalette])
                    && is_array($GLOBALS['TCA'][$table]['palettes'][$pPalette])
                    && isset($GLOBALS['TCA'][$table]['palettes'][$pPalette]['showitem'])
                ) {
                    $paletteFields = GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$table]['palettes'][$pPalette]['showitem'], true);
                    foreach ($paletteFields as $paletteField) {
                        if ($paletteField !== '--linebreak--') {
                            $paletteFieldList[] = $paletteField;
                        }
                    }
                }
            }
            $fieldList = array_merge($fieldList, $paletteFieldList);
            $altFieldList = [];
            // Traverse fields in types config and parse the configuration into a nice array:
            foreach ($fieldList as $k => $v) {
                $vArray = GeneralUtility::trimExplode(';', $v);
                $fieldList[$k] = [
                    'field' => $vArray[0],
                    'title' => $vArray[1] ?? null,
                    'palette' => $vArray[2] ?? null,
                    'spec' => [],
                    'origString' => $v
                ];
                if ($useFieldNameAsKey) {
                    $altFieldList[$fieldList[$k]['field']] = $fieldList[$k];
                }
            }
            if ($useFieldNameAsKey) {
                $fieldList = $altFieldList;
            }

            // Add to first-level-cache
            $this->tcaTableTypeConfigurationCache[$cacheIdentifier] = $fieldList;

            // Return array:
            return $fieldList;
        }
        return null;
    }

    /**
     * Returns the "type" value of $rec from $table which can be used to look up the correct "types" rendering section in $GLOBALS['TCA']
     * If no "type" field is configured in the "ctrl"-section of the $GLOBALS['TCA'] for the table, zero is used.
     * If zero is not an index in the "types" section of $GLOBALS['TCA'] for the table, then the $fieldValue returned will default to 1 (no matter if that is an index or not)
     *
     * Note: This method is very similar to the type determination of FormDataProvider/DatabaseRecordTypeValue,
     * however, it has two differences:
     * 1) The method in TCEForms also takes care of localization (which is difficult to do here as the whole infrastructure for language overlays is only in TCEforms).
     * 2) The $row array looks different in TCEForms, as in there it's not the raw record but the prepared data from other providers is handled, which changes e.g. how "select"
     * and "group" field values are stored, which makes different processing of the "foreign pointer field" type field variant necessary.
     *
     * @param string $table Table name present in TCA
     * @param array $row Record from $table
     * @throws \RuntimeException
     * @return string Field value
     */
    protected function getTCAtypeValue($table, $row)
    {
        $typeNum = 0;
        if ($GLOBALS['TCA'][$table]) {
            $field = $GLOBALS['TCA'][$table]['ctrl']['type'];
            if (strpos($field, ':') !== false) {
                list($pointerField, $foreignTableTypeField) = explode(':', $field);
                // Get field value from database if field is not in the $row array
                if (!isset($row[$pointerField])) {
                    $localRow = BackendUtility::getRecord($table, $row['uid'], $pointerField);
                    $foreignUid = $localRow[$pointerField];
                } else {
                    $foreignUid = $row[$pointerField];
                }
                if ($foreignUid) {
                    $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$pointerField]['config'];
                    $relationType = $fieldConfig['type'];
                    if ($relationType === 'select') {
                        $foreignTable = $fieldConfig['foreign_table'];
                    } elseif ($relationType === 'group') {
                        $allowedTables = explode(',', $fieldConfig['allowed']);
                        $foreignTable = $allowedTables[0];
                    } else {
                        throw new \RuntimeException(
                            'TCA foreign field pointer fields are only allowed to be used with group or select field types.',
                            1325862240
                        );
                    }
                    $foreignRow = BackendUtility::getRecord($foreignTable, $foreignUid, $foreignTableTypeField);
                    if ($foreignRow[$foreignTableTypeField]) {
                        $typeNum = $foreignRow[$foreignTableTypeField];
                    }
                }
            } else {
                $typeNum = $row[$field];
            }
            // If that value is an empty string, set it to "0" (zero)
            if (empty($typeNum)) {
                $typeNum = 0;
            }
        }
        // If current typeNum doesn't exist, set it to 0 (or to 1 for historical reasons, if 0 doesn't exist)
        if (!isset($GLOBALS['TCA'][$table]['types'][$typeNum]) || !$GLOBALS['TCA'][$table]['types'][$typeNum]) {
            $typeNum = isset($GLOBALS['TCA'][$table]['types']['0']) ? 0 : 1;
        }
        // Force to string. Necessary for eg '-1' to be recognized as a type value.
        $typeNum = (string)$typeNum;
        return $typeNum;
    }
}
