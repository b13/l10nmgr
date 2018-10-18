<?php
namespace Localizationteam\L10nmgr\Model;

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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Function for generating preview links during import
 *
 * @author Daniel Zielinski <d.zielinski@L10Ntech.de>
 * @package TYPO3
 * @subpackage tx_l10nmgr
 */
class MkPreviewLinkService
{
    /**
     * @var array
     */
    protected $_errorMsg = [];

    /**
     * @var int
     */
    protected $sysLang;

    /**
     * @var array
     */
    protected $pageIds;

    /**
     * @var int
     */
    protected $workspaceId;

    /**
     * MkPreviewLinkService constructor.
     * @param int $t3_workspaceId
     * @param int $t3_sysLang
     * @param array $pageIds
     */
    public function __construct($t3_workspaceId, $t3_sysLang, $pageIds)
    {
        $this->sysLang = $t3_sysLang;
        $this->pageIds = $pageIds;
        $this->workspaceId = $t3_workspaceId;
    }

    /**
     * Generate single source preview link for service
     *
     * @param string $baseUrl
     * @param int $srcLang
     * @return string
     */
    public function mkSingleSrcPreviewLink($baseUrl, $srcLang)
    {
        $ttlHours = (int)$this->getBackendUser()->getTSConfigVal('options.workspaces.previewLinkTTLHours');
        $ttlHours = ($ttlHours ? $ttlHours : 24 * 2);
        $params = 'id=' . $this->pageIds[0] . '&L=' . $srcLang . '&ADMCMD_previewWS=' . $this->workspaceId;
        $previewUrl = $baseUrl . 'index.php?ADMCMD_prev=' . $this->compilePreviewKeyword(
            $params,
            $this->getBackendUser()->user['uid'],
            60 * 60 * $ttlHours
        );
        return $previewUrl;
    }

    /**
     * Generate single target preview link for CLI
     *
     * Returns the Backend User
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Generate list of preview links for backend or email
     *
     * @param string $baseUrl
     * @param string $serverlink
     * @return string
     */
    public function mkSinglePreviewLink($baseUrl, $serverlink)
    {
        $ttlHours = (int)$this->getBackendUser()->getTSConfigVal('options.workspaces.previewLinkTTLHours');
        $ttlHours = ($ttlHours ? $ttlHours : 24 * 2);
        //no_cache=1 ???
        $params = 'id=' . $this->pageIds[0] . '&L=' . $this->sysLang . '&ADMCMD_previewWS=' . $this->workspaceId . '&serverlink=' . $serverlink;
        $previewUrl = $baseUrl . 'index.php?ADMCMD_prev=' . $this->compilePreviewKeyword(
            $params,
            $this->getBackendUser()->user['uid'],
            60 * 60 * $ttlHours
        );
        return $previewUrl;
    }

    /**
     * @return array
     */
    public function mkPreviewLinks()
    {
        $previewUrls = array();
        foreach ($this->pageIds as $pageId) {
            $ttlHours = (int)$this->getBackendUser()->getTSConfigVal('options.workspaces.previewLinkTTLHours');
            $ttlHours = ($ttlHours ? $ttlHours : 24 * 2);
            $params = 'id=' . $pageId . '&L=' . $this->sysLang . '&ADMCMD_previewWS=' . $this->workspaceId;
            $previewUrls[$pageId] = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . 'index.php?ADMCMD_prev=' . $this->compilePreviewKeyword(
                $params,
                $this->getBackendUser()->user['uid'],
                60 * 60 * $ttlHours
            );
        }
        return $previewUrls;
    }

    /**
     * @param array $previewLinks
     * @return string
     */
    public function renderPreviewLinks($previewLinks)
    {
        $out = '<ol>';
        foreach ($previewLinks as $key => $previewLink) {
            $out .= '<li>' . $key . ': <a href="' . $previewLink . '" target="_new">' . $previewLink . '</a></li>';
        }
        $out .= '</ol>';
        return $out;
    }

    /**
     * Set preview keyword, eg:
     * $previewUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL').'index.php?ADMCMD_prev='.$this->compilePreviewKeyword('id='.$pageId.'&L='.$language.'&ADMCMD_view=1&ADMCMD_editIcons=1&ADMCMD_previewWS='.$this->workspace, $GLOBALS['BE_USER']->user['uid'], 120);
     *
     * @todo for sys_preview:
     * - Add a comment which can be shown to previewer in frontend in some way (plus maybe ability to write back, take other action?)
     * - Add possibility for the preview keyword to work in the backend as well: So it becomes a quick way to a certain action of sorts?
     *
     * @param string $getVarsStr Get variables to preview, eg. 'id=1150&L=0&ADMCMD_view=1&ADMCMD_editIcons=1&ADMCMD_previewWS=8'
     * @param string $backendUserUid 32 byte MD5 hash keyword for the URL: "?ADMCMD_prev=[keyword]
     * @param int $ttl Time-To-Live for keyword
     * @param int|null $fullWorkspace Which workspace to preview. Workspace UID, -1 or >0. If set, the getVars is ignored in the frontend, so that string can be empty
     * @return string Returns keyword to use in URL for ADMCMD_prev=
     */
    public function compilePreviewKeyword($getVarsStr, $backendUserUid, $ttl = 172800, $fullWorkspace = null)
    {
        $fieldData = [
            'keyword' => md5(uniqid(microtime(), true)),
            'tstamp' => $GLOBALS['EXEC_TIME'],
            'endtime' => $GLOBALS['EXEC_TIME'] + $ttl,
            'config' => serialize([
                'fullWorkspace' => $fullWorkspace,
                'getVars' => $getVarsStr,
                'BEUSER_uid' => $backendUserUid
            ])
        ];
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_preview')
            ->insert(
                'sys_preview',
                $fieldData
            );

        return $fieldData['keyword'];
    }
}
