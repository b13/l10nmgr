<?php

namespace Localizationteam\L10nmgr\Command;

/***************************************************************
 * Copyright notice
 * (c) 2008 Daniel Zielinski (d.zielinski@l10ntech.de)
 * (c) 2018 B13
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

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\View\CatXmlView;
use Localizationteam\L10nmgr\View\ExcelXmlView;
use Localizationteam\L10nmgr\View\ExportViewInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

class Export extends L10nCommand
{

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $this->setDescription('Export the translations as file')
            ->setHelp('With this command you can Export translation')
            ->addOption('check-exports', null, InputOption::VALUE_NONE, 'Check for already exported content')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_OPTIONAL,
                "UIDs of the localization manager configurations to be used for export. Comma seperated values, no spaces.\nDefault is EXTCONF which means values are taken from extension configuration.",
                'EXTCONF'
            )
            ->addOption(
                'forcedSourceLanguage',
                'f',
                InputOption::VALUE_OPTIONAL,
                'UID of the already translated language used as overlaid source language during export.'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                "Format for export of translatable data can be:\n CATXML = XML for translation tools (default)\n EXCEL = Microsoft XML format",
                'CATXML'
            )
            ->addOption('hidden', null, InputOption::VALUE_NONE, 'Do not export hidden contents')
            ->addOption(
                'srcPID',
                'p',
                InputOption::VALUE_OPTIONAL,
                'UID of the page used during export. Needs configuration depth to be set to "current page" Default = 0',
                0
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_OPTIONAL,
                'UIDs for the target languages used during export. Comma seperated values, no spaces. Default is 0. In that case UIDs are taken from extension configuration.'
            )
            ->addOption('updated', 'u', InputOption::VALUE_NONE, 'Export only new/updated contents')
            ->addOption(
                'workspace',
                'w',
                InputOption::VALUE_OPTIONAL,
                'UID of the workspace used during export. Default = 0',
                0
            );
    }

    /**
     * Executes the command for straigthening content elements
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $error = false;
        $time_start = microtime(true);

        $extConf = $this->getExtConf();

        // get format (CATXML,EXCEL)
        $format = $input->getOption('format');

        // get l10ncfg command line takes precedence over extConf
        $l10ncfg = $input->getOption('config');

        if ($l10ncfg !== 'EXTCONF' && !empty($l10ncfg)) {
            //export single
            $l10ncfgs = explode(',', $l10ncfg);
        } elseif (!empty($extConf['l10nmgr_cfg'])) {
            //export multiple
            $l10ncfgs = explode(',', $extConf['l10nmgr_cfg']);
        } else {
            $output->writeln('<error>' . $this->getLanguageService()->getLL('error.no_l10ncfg.msg') . '</error>');
            $error = true;
        }

        // get target languages
        $tlang = $input->getOption('target') ?? '0';
        if ($tlang !== '0') {
            //export single
            $tlangs = explode(',', $tlang);
        } elseif (!empty($extConf['l10nmgr_tlangs'])) {
            //export multiple
            $tlangs = explode(',', $extConf['l10nmgr_tlangs']);
        } else {
            $output->writeln('<error>' . $this->getLanguageService()->getLL('error.target_language_id.msg') . '</error>');
            $error = true;
        }

        // get workspace ID
        $wsId = $input->getOption('workspace') ?? '0';
        // todo does workspace exits?
        if (MathUtility::canBeInterpretedAsInteger($wsId) === false) {
            $output->writeln('<error>' . $this->getLanguageService()->getLL('error.workspace_id_int.msg') . '</error>');
            $error = true;
        }

        $msg = '';

        // to
        // Set workspace to the required workspace ID from CATXML:
        $this->getBackendUser()->setWorkspace($wsId);

        if ($error) {
            return;
        }
        foreach ($l10ncfgs as $l10ncfg) {
            if (MathUtility::canBeInterpretedAsInteger($l10ncfg) === false) {
                $output->writeln('<error>' . $this->getLanguageService()->getLL('error.l10ncfg_id_int.msg') . '</error>');
                return;
            }
            foreach ($tlangs as $tlang) {
                if (MathUtility::canBeInterpretedAsInteger($tlang) === false) {
                    $output->writeln('<error>' . $this->getLanguageService()->getLL('error.target_language_id_integer.msg') . '</error>');
                    return;
                }
                try {
                    $msg .= $this->exportXML($l10ncfg, $tlang, $format, $input, $output);
                } catch (Exception $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    return;
                }
            }
        }

        // Send email notification if set
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        $output->writeln($msg . LF);
        $output->writeln(sprintf($this->getLanguageService()->getLL('export.process.duration.message'), $time) . LF);
    }

    /**
     * exportCATXML which is called over cli
     *
     * @param int             $l10ncfg ID of the configuration to load
     * @param int             $tlang   ID of the language to translate to
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return string An error message in case of failure
     * @throws Exception
     */
    protected function exportXML($l10ncfg, $tlang, $format, $input, $output)
    {
        $error = '';
        // Load the configuration
        $lConf = $this->getExtConf();
        /** @var L10nConfiguration $l10nmgrCfgObj */
        $l10nmgrCfgObj = GeneralUtility::makeInstance(L10nConfiguration::class);
        $l10nmgrCfgObj->load($l10ncfg);
        $sourcePid = $input->getOption('srcPID') ?? 0;
        $l10nmgrCfgObj->setSourcePid($sourcePid);
        if ($l10nmgrCfgObj->isLoaded()) {
            if ($format == 'CATXML') {
                /** @var ExportViewInterface $l10nmgrGetXML */
                $l10nmgrGetXML = GeneralUtility::makeInstance(CatXmlView::class, $l10nmgrCfgObj, $tlang);
            } elseif ($format == 'EXCEL') {
                /** @var ExportViewInterface $l10nmgrGetXML */
                $l10nmgrGetXML = GeneralUtility::makeInstance(ExcelXmlView::class, $l10nmgrCfgObj, $tlang);
            } else {
                throw new Exception("Wrong format. Use 'CATXML' or 'EXCEL'");
            }
            // Check  if sourceLangStaticId is set in configuration and set setForcedSourceLanguage to this value
            if ($l10nmgrCfgObj->getData('sourceLangStaticId') && ExtensionManagementUtility::isLoaded('static_info_tables')) {
                $forceLanguage = $this->getStaticLangUid($l10nmgrCfgObj->getData('sourceLangStaticId'));
                $l10nmgrGetXML->setForcedSourceLanguage($forceLanguage);
            }
            $forceLanguage = $input->getOption('forcedSourceLanguage') ?? 0;
            if ($forceLanguage) {
                $l10nmgrGetXML->setForcedSourceLanguage($forceLanguage);
            }
            $onlyChanged = $input->getOption('updated');
            if ($onlyChanged) {
                $l10nmgrGetXML->setModeOnlyChanged();
            }
            $hidden = $input->getOption('hidden');
            if ($hidden) {
                $l10nmgrGetXML->setModeNoHidden();
            }
            // If the check for already exported content is enabled, run the ckeck.
            $checkExportsCli = $input->getOption('check-exports');
            $checkExports = $l10nmgrGetXML->checkExports();
            if ($checkExportsCli && !$checkExports) {
                $output->writeln('<error>' . $this->getLanguageService()->getLL('export.process.duplicate.title') . ' ' . $this->getLanguageService()->getLL('export.process.duplicate.message') . LF . '</error>');
                $output->writeln('<error>' . $l10nmgrGetXML->renderExportsCli() . LF . '</error>');
            } else {
                // Save export to XML file
                $xmlFileName = PATH_site . $l10nmgrGetXML->render();
                $l10nmgrGetXML->saveExportInformation();
                // If email notification is set send export files to responsible translator
                if ($lConf['enable_notification'] == 1) {
                    if (empty($lConf['email_recipient'])) {
                        $output->writeln('<error>' . $this->getLanguageService()->getLL('error.email.repient_missing.msg') . '</error>');
                    }
                    // ToDo: make email configuration run again
                    // $this->emailNotification($xmlFileName, $l10nmgrCfgObj, $tlang);
                } else {
                    $output->writeln('<error>' . $this->getLanguageService()->getLL('error.email.notification_disabled.msg') . '</error>');
                }
                // If FTP option is set upload files to remote server
                if ($lConf['enable_ftp'] == 1) {
                    if (file_exists($xmlFileName)) {
                        $error .= $this->ftpUpload($xmlFileName, $l10nmgrGetXML->getFileName());
                    } else {
                        $output->writeln('<error>' . $this->getLanguageService()->getLL('error.ftp.file_not_found.msg') . '</error>');
                    }
                } else {
                    $output->writeln('<error>' . $this->getLanguageService()->getLL('error.ftp.disabled.msg') . '</error>');
                }
                if ($lConf['enable_notification'] == 0 && $lConf['enable_ftp'] == 0) {
                    $output->writeln(sprintf(
                        $this->getLanguageService()->getLL('export.file_saved.msg'),
                        $xmlFileName
                    ));
                }
            }
        } else {
            $error .= $this->getLanguageService()->getLL('error.l10nmgr.object_not_loaded.msg') . "\n";
        }
        return $error;
    }

    /**
     * The function ftpUpload puts an export on a remote FTP server for further processing
     *
     * @param string $xmlFileName Path to the file to upload
     * @param string $filename    Name of the file to upload to
     *
     * @return string Error message
     */
    protected function ftpUpload($xmlFileName, $filename)
    {
        $error = '';
        $lConf = $this->getExtConf();
        $connection = ftp_connect($lConf['ftp_server']) or die('Connection failed');
        if ($connection) {
            if (@ftp_login($connection, $lConf['ftp_server_username'], $lConf['ftp_server_password'])) {
                if (ftp_put($connection, $lConf['ftp_server_path'] . $filename, $xmlFileName, FTP_BINARY)) {
                    ftp_close($connection) or die("Couldn't close connection");
                } else {
                    $error .= sprintf(
                            $this->getLanguageService()->getLL('error.ftp.connection.msg'),
                            $lConf['ftp_server_path'],
                            $filename
                        ) . "\n";
                }
            } else {
                $error .= sprintf(
                        $this->getLanguageService()->getLL('error.ftp.connection_user.msg'),
                        $lConf['ftp_server_username']
                    ) . "\n";
                ftp_close($connection) or die("Couldn't close connection");
            }
        } else {
            $error .= $this->getLanguageService()->getLL('error.ftp.connection_failed.msg');
        }
        return $error;
    }

    /**
     * @param $sourceLangStaticId
     * @return int
     */
    protected function getStaticLangUid($sourceLangStaticId)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_language');
        $result = $queryBuilder->select('uid')
            ->from('sys_language')
            ->where(
                $queryBuilder->expr()->eq(
                    'static_lang_isocode',
                    $sourceLangStaticId
                )
            )
            ->execute()
            ->fetch();
        return $result['uid'];
    }

    /**
     * fixme
     *
     * The function emailNotification sends an email with a translation job to the recipient specified in the extension config.
     *
     * @param string            $xmlFileName   Name of the XML file
     * @param L10nConfiguration $l10nmgrCfgObj L10N Manager configuration object
     * @param int               $tlang         ID of the language to translate to
     */
    protected function emailNotification($xmlFileName, $l10nmgrCfgObj, $tlang)
    {
        // Get source & target language ISO codes
        $sourceStaticLangArr = BackendUtility::getRecord(
            'static_languages',
            $l10nmgrCfgObj->l10ncfg['sourceLangStaticId'],
            'lg_iso_2'
        );
        $targetStaticLang = BackendUtility::getRecord('sys_language', $tlang, 'static_lang_isocode');
        $targetStaticLangArr = BackendUtility::getRecord(
            'static_languages',
            $targetStaticLang['static_lang_isocode'],
            'lg_iso_2'
        );
        $sourceLang = $sourceStaticLangArr['lg_iso_2'];
        $targetLang = $targetStaticLangArr['lg_iso_2'];
        // Construct email message
        /** @var t3lib_htmlmail $email */
        $email = GeneralUtility::makeInstance('t3lib_htmlmail');
        $email->start();
        $email->useQuotedPrintable();
        $email->subject = sprintf(
            $this->getLanguageService()->getLL('email.suject.msg'),
            $sourceLang,
            $targetLang,
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']
        );
        if (empty($this->getBackendUser()->user['email']) || empty($this->getBackendUser()->user['realName'])) {
            $email->from_email = $this->lConf['email_sender'];
            $email->from_name = $this->lConf['email_sender_name'];
            $email->replyto_email = $this->lConf['email_sender'];
            $email->replyto_name = $this->lConf['email_sender_name'];
        } else {
            $email->from_email = $this->getBackendUser()->user['email'];
            $email->from_name = $this->getBackendUser()->user['realName'];
            $email->replyto_email = $this->getBackendUser()->user['email'];
            $email->replyto_name = $this->getBackendUser()->user['realName'];
        }
        $email->organisation = $this->lConf['email_sender_organisation'];
        $message = [
            'msg1' => $this->getLanguageService()->getLL('email.greeting.msg'),
            'msg2' => '',
            'msg3' => sprintf(
                $this->getLanguageService()->getLL('email.new_translation_job.msg'),
                $sourceLang,
                $targetLang,
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']
            ),
            'msg4' => $this->getLanguageService()->getLL('email.info.msg'),
            'msg5' => $this->getLanguageService()->getLL('email.info.import.msg'),
            'msg6' => '',
            'msg7' => $this->getLanguageService()->getLL('email.goodbye.msg'),
            'msg8' => $email->from_name,
            'msg9' => '--',
            'msg10' => $this->getLanguageService()->getLL('email.info.exportef_file.msg'),
            'msg11' => $xmlFileName,
        ];
        if ($this->lConf['email_attachment']) {
            $message['msg3'] = sprintf(
                $this->getLanguageService()->getLL('email.new_translation_job_attached.msg'),
                $sourceLang,
                $targetLang,
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']
            );
        }
        $msg = implode(chr(10), $message);
        $email->addPlain($msg);
        if ($this->lConf['email_attachment']) {
            $email->addAttachment($xmlFileName);
        }
        $email->send($this->lConf['email_recipient']);
    }
}
