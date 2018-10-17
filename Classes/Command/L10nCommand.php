<?php
declare(strict_types = 1);

namespace Localizationteam\L10nmgr\Command;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the  GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class L10nCommand
 */
class L10nCommand extends Command
{
    /**
     * @var LanguageService
     */
    private $languageService;

    /**
     * The function loadExtConf loads the extension configuration.
     *
     * @return array
     */
    protected function getExtConf()
    {
        return empty($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr'])
            ? unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['l10nmgr'])
            : $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr'];
    }

    /**
     * getter for LanguageService object
     *
     * @return LanguageService $languageService
     */
    protected function getLanguageService()
    {
        if (!$this->languageService instanceof LanguageService) {
            $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
            $fileRef = 'EXT:l10nmgr/Resources/Private/Language/Cli/locallang.xml';
            $this->languageService->includeLLFile($fileRef);
            $this->languageService->init('');
        }

        return $this->languageService;
    }

    /**
     * Gets the current backend user.
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }
}
