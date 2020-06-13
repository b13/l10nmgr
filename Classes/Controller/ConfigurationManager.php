<?php
namespace Localizationteam\L10nmgr\Controller;

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
 * Module 'L10N Manager' for the 'l10nmgr' extension.
 *
 * @author Kasper Skårhøj <kasperYYYY@typo3.com>
 */
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translation management tool
 *
 * @authorKasper Skaarhoj <kasperYYYY@typo3.com>
 * @authorJo Hasenau <info@cybercraft.de>
 * @packageTYPO3
 * @subpackage tx_l10nmgr
 */
class ConfigurationManager extends BaseModule
{
    var $pageinfo;

    /**
     * @var array Cache of the page details already fetched from the database
     */
    protected $pageDetails = array();
    /**
     * @var array Cache of the language records already fetched from the database
     */
    protected $languageDetails = array();
    /**
     * ModuleTemplate Container
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;
    /**
     * The name of the module
     *
     * @var string
     */
    protected $moduleName = 'web_ConfigurationManager';
    /**
     * @var IconFactory
     */
    protected $iconFactory;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);
        $this->getLanguageService()->includeLLFile('EXT:l10nmgr/Resources/Private/Language/Modules/ConfigurationManager/locallang.xlf');
        $this->MCONF = [
            'name' => $this->moduleName,
        ];
    }

    /**
     * Injects the request object for the current request or subrequest
     * Then checks for module functions that have hooked in, and renders menu etc.
     *
     * @param ServerRequestInterface $request the current request
     * @param ResponseInterface $response
     * @return ResponseInterface the response with the content
     */
    public function mainAction(ServerRequestInterface $request)
    {
        $GLOBALS['SOBE'] = $this;
        $this->init();
        // Checking for first level external objects
        $this->checkExtObj();
        // Checking second level external objects
        $this->checkSubExtObj();
        $this->main();
        $this->moduleTemplate->setContent($this->content);
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * Initializes the Module
     *
     * @return void
     */
    public function init()
    {
        $this->getBackendUser()->modAccess($this->MCONF);
        parent::init();
    }

    /**
     * Main function of the module. Write the content to $this->content
     * If you chose "web" as main module, you will need to consider the $this->id parameter which will contain the uid-number of the page clicked in the page tree
     *
     * @return void
     */
    public function main()
    {
        // NOTE: this module uses the same template as the CM1 module
        $this->moduleTemplate->setForm('<form action="" method="POST">');
        // Get the actual content
        $this->content = $this->moduleContent();
    }

    /**
     * Generates and returns the content of the module
     *
     * @return string HTML to display
     */
    protected function moduleContent()
    {
        $content = '';
        $content .= $this->moduleTemplate->header($this->getLanguageService()->getLL('general.title'));
        // Get the available configurations
        $l10nConfigurations = $this->getAllConfigurations();
        // No configurations, issue a simple message
        if (count($l10nConfigurations) == 0) {
            $content .= '<div>' . nl2br($this->getLanguageService()->getLL('general.no_date')) . '</div>';
            // List all configurations
        } else {
            $content .= '<div><h2 class="uppercase">' . $this->getLanguageService()->getLL('general.list.configuration.manager') . '</h2>' . nl2br($this->getLanguageService()->getLL('general.description.message')) . '</div>';
            $content .= '<div><h2 class="uppercase">' . $this->getLanguageService()->getLL('general.list.configuration.title') . '</h2></div>';
            $content .= '<div class="table-fit"><table class="table table-striped table-hover">';
            // Assemble the header row
            $content .= '<thead>';
            $content .= '<tr>';
            $content .= '<th nowrap="nowrap" class="col-info">' . $this->getLanguageService()->getLL('general.list.headline.info.title') . '</th>';
            $content .= '<th nowrap="nowrap" class="col-title">' . $this->getLanguageService()->getLL('general.list.headline.title.title') . '</th>';
            $content .= '<th nowrap="nowrap" class="col-path">' . $this->getLanguageService()->getLL('general.list.headline.path.title') . '</th>';
            $content .= '<th nowrap="nowrap" class="col-depth">' . $this->getLanguageService()->getLL('general.list.headline.depth.title') . '</th>';
            $content .= '<th class="col-tables">' . $this->getLanguageService()->getLL('general.list.headline.tables.title') . '</th>';
            $content .= '<th class="col-exclude">' . $this->getLanguageService()->getLL('general.list.headline.exclude.title') . '</th>';
            $content .= '<th class="col-include">' . $this->getLanguageService()->getLL('general.list.headline.include.title') . '</th>';
            $content .= '<th class="col-incfcewithdefaultlanguage">' . $this->getLanguageService()->getLL('general.list.headline.incfcewithdefaultlanguage.title') . '</th>';
            $content .= '</tr>';
            $content .= '</thead>';
            $content .= '<tbody>';
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            foreach ($l10nConfigurations as $record) {
                $content .= '<tr class="db_list_normal">';
                $content .= '<td>' . $this->iconFactory->getIconForRecord('tx_l10nmgr_cfg', $record, Icon::SIZE_SMALL)->render() . '</td>';
                $content .= '<td><a href="' . $uriBuilder->buildUriFromRoute('LocalizationManager',
                        array(
                            'id' => $record['pid'],
                            'srcPID' => $this->id,
                            'exportUID' => $record['uid'],
                        )) . '">' . $record['title'] . '</a>' . '</td>';
                // Get the full page path
                // If very long, make sure to still display the full path
                $pagePath = BackendUtility::getRecordPath($record['pid'], '1', 20, 50);
                $path = (is_array($pagePath)) ? $pagePath[1] : $pagePath;
                $content .= '<td>' . $path . '</td>';
                $content .= '<td>' . $record['depth'] . '</td>';
                $content .= '<td>' . $record['tablelist'] . '</td>';
                $content .= '<td>' . $record['exclude'] . '</td>';
                $content .= '<td>' . $record['include'] . '</td>';
                $content .= '<td>' . $record['incfcewithdefaultlanguage'] . '</td>';
                $content .= '</tr>';
            }
            $content .= '</tbody></table></div>';
        }
        return $content;
    }

    /**
     * Returns all l10nmgr configurations to which the current user has access, based on page permissions
     *
     * @return array List of l10nmgr configurations
     */
    protected function getAllConfigurations()
    {
        // Read all l10nmgr configurations from the database
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_l10nmgr_cfg');
        $configurations = $queryBuilder->select('*')
            ->from('tx_l10nmgr_cfg')
            ->orderBy('title')
            ->execute()
            ->fetchAll();
        // Filter out the configurations which the user is allowed to see, base on the page access rights
        $pagePermissionsClause = $this->getBackendUser()->getPagePermsClause(1);
        $allowedConfigurations = array();
        foreach ($configurations as $row) {
            if (BackendUtility::readPageAccess($row['pid'], $pagePermissionsClause) !== false) {
                $allowedConfigurations[] = $row;
            }
        }
        return $allowedConfigurations;
    }

    /**
     * Returns the details of a given page record, possibly from cache if already fetched earlier
     *
     * @param int $uid Id of a page
     *
     * @return array Page record from the database
     */
    protected function getPageDetails($uid)
    {
        $uid = (int)$uid;
        if (isset($this->pageDetails[$uid])) {
            $record = $this->pageDetails[$uid];
        } else {
            $record = BackendUtility::getRecord('pages', $uid);
            $this->pageDetails[$uid] = $record;
        }
        return $record;
    }
}
