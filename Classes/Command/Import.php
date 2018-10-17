<?php

namespace Localizationteam\L10nmgr\Command;

/***************************************************************
 * Copyright notice
 * (c) 2008 Daniel Zielinski (d.zielinski@l10ntech.de)
 * (c) 2011 Francois Suter (typo3@cobweb.ch)
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

use Localizationteam\L10nmgr\Model\CatXmlImportManager;
use Localizationteam\L10nmgr\Model\L10nBaseService;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\MkPreviewLinkService;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Model\TranslationDataFactory;
use Localizationteam\L10nmgr\Zip;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Import extends L10nCommand
{
    /**
     * @var array Extension's configuration as from the EM
     */
    protected $extensionConfiguration = [];

    /**
     * @var int ID of the language being handled
     */
    protected $sysLanguage;
    /**
     * @var int ID of the forced source language being handled
     */
    protected $previewLanguage;
    /**
     * @var string Path to temporary de-archiving directory, to be removed after import
     */
    protected $directoryToCleanUp;
    /**
     * @var array List of files that were imported, with additional information, used for reporting after import
     */
    protected $filesImported = [];

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $this->setDescription('Import the translations as file')
            ->setHelp('With this command you can import translation')
            ->addOption(
                'file',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to the file to import. Can be XML or ZIP archive. If both XML string and import file are not defined, will import from FTP server (if defined).'
            )
            ->addOption('importAsDefaultLanguage', 'd', InputOption::VALUE_NONE, 'Import as default language')
            ->addOption('preview', 'p', InputOption::VALUE_NONE, 'Preview flag')
            ->addOption('server', null, InputOption::VALUE_OPTIONAL, 'Server link for the preview URL.')
            ->addOption(
                'srcPID',
                'P',
                InputOption::VALUE_OPTIONAL,
                'UID of the page used during export. Needs configuration depth to be set to "current page" Default = 0',
                0
            )
            ->addOption('string', 's', InputOption::VALUE_OPTIONAL, 'XML string to import.')
            ->addOption(
                'task',
                't',
                InputOption::VALUE_OPTIONAL,
                "The values can be:\n importString = Import a XML string\n importFile = Import a XML file\n preview = Generate a preview of the source from a XML string",
                'importString'
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
        $start = microtime(true);
        // Load the extension's configuration
        $this->extensionConfiguration = $this->getExtConf();

        // Parse the command-line arguments
        $callParameters = $this->initializeCallParameters($input, $output);

        $msg = '';

        try {
            switch ($callParameters['task']) {
                case 'importString':
                case 'preview':
                    // Get workspace id from CATXML
                    // Continue if found, else exit script execution

                    $wsId = $this->getWsIdFromCATXML($callParameters['string']);
                    // Set workspace to the required workspace ID from CATXML:
                    $this->getBackendUser()->setWorkspace($wsId);
                    if ($callParameters['task'] == 'importString') {
                        $msg = $this->importCATXML($callParameters);
                    } else {
                        $msg = $this->previewSource($callParameters['string']);
                    }
                    break;
                case 'importFile':
                    $this->importXMLFile($callParameters);
                    $msg = "\n\nImport was successful.\n";
                    break;
            }
        } catch (Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return;
        }
        // Calculate duration and output result message
        $end = microtime(true);
        $time = $end - $start;
        $output->writeln($msg);
        $output->writeln(sprintf($this->getLanguageService()->getLL('import.process.duration.message'), $time));
        // Send reporting mail
//        $this->sendMailNotification();
    }

    /**
     * This method reads the command-line arguments and prepares a list of call parameters
     * It takes care of backwards-compatibility with the old way of calling the import script
     *
     * @return array
     */
    protected function initializeCallParameters(InputInterface $input, OutputInterface $output)
    {

        // Get the task parameter from either the new or the old input style
        // The default is in the configure()

        if ($input->getOption('task') === 'importString' || $input->getOption('task') === 'importFile' || $input->getOption('task') === 'preview') {
            $callParameters['task'] = $input->getOption('task');
        } else {
            $output->writeln('<error> The task is not set correctly</error>');
            //todo what is running here
        }

        // Get the preview flag
        $callParameters['preview'] = $input->getOption('preview');

        // Get the XML string
        $callParameters['string'] = stripslashes($input->getOption('string'));

        // Get the path to XML or ZIP file
        $callParameters['file'] = $input->getOption('file');

        // Get the server link for preview
        $callParameters['server'] = $input->getOption('server');
        // Import as default language
        $callParameters['importAsDefaultLanguage'] = $input->getOption('importAsDefaultLanguage');
        // Source PID
        $callParameters['sourcePid'] = $input->getOption('srcPID');

        return $callParameters;
    }

    /**
     * Get workspace ID from XML (quick & dirty)
     *
     * @param string $xml XML string to parse
     *
     * @return int ID of the workspace to import to
     * @throws \TYPO3\CMS\Core\Exception
     * @throws Exception
     */
    protected function getWsIdFromCATXML($xml)
    {
        preg_match('/<t3_workspaceId>([^<]+)/', $xml, $matches);
        if (!empty($matches)) {
            return $matches[1];
        }
        throw new Exception('No workspace id found', 1322475562);
    }

    /**
     * Imports a CATXML string
     *
     * @param $callParameters
     * @return string Output
     * @throws Exception
     */
    protected function importCATXML($callParameters)
    {
        $out = '';
        /** @var L10nBaseService $service */
        $service = GeneralUtility::makeInstance(L10nBaseService::class);
        if ($callParameters['importAsDefaultLanguage']) {
            $service->setImportAsDefaultLanguage(true);
        }
        /** @var TranslationDataFactory $factory */
        $factory = GeneralUtility::makeInstance(TranslationDataFactory::class);
        /** @var CatXmlImportManager $importManager */
        $importManager = GeneralUtility::makeInstance(
            CatXmlImportManager::class,
            '',
            $this->sysLanguage,
            $callParameters['string']
        );
        // Parse and check XML, load header data
        if ($importManager->parseAndCheckXMLString() === false) {
            $tmp = var_export($importManager->headerData, true);
            $tmp = str_replace("\n", '', $tmp);
            $error = $tmp . $this->getLanguageService()->getLL('import.manager.error.parsing.xmlstring.message');
            throw new Exception($error);
        }
        // Find l10n configuration record
        /** @var L10nConfiguration $l10ncfgObj */
        $l10ncfgObj = GeneralUtility::makeInstance(L10nConfiguration::class);
        $l10ncfgObj->load($importManager->headerData['t3_l10ncfg']);
        $l10ncfgObj->setSourcePid($callParameters['sourcePid']);
        $status = $l10ncfgObj->isLoaded();
        if ($status === false) {
            throw new Exception('l10ncfg not loaded! Exiting...');
        }
        //Do import...
        $this->sysLanguage = $importManager->headerData['t3_sysLang']; //set import language to t3_sysLang from XML
        if ($importManager->headerData['t3_sourceLang'] === $importManager->headerData['t3_targetLang']) {
            $this->previewLanguage = $this->sysLanguage;
        }
        //Delete previous translations
        $importManager->delL10N($importManager->getDelL10NDataFromCATXMLNodes($importManager->getXmlNodes()));
        //Make preview links
        if ($callParameters['preview']) {
            $pageIds = [];
            if (empty($importManager->headerData['t3_previewId'])) {
                $pageIds = $importManager->getPidsFromCATXMLNodes($importManager->getXmlNodes());
            } else {
                $pageIds[0] = $importManager->headerData['t3_previewId'];
            }
            /** @var MkPreviewLinkService $mkPreviewLinks */
            $mkPreviewLinks = GeneralUtility::makeInstance(
                MkPreviewLinkService::class,
                $importManager->headerData['t3_workspaceId'],
                $importManager->headerData['t3_sysLang'],
                $pageIds
            );
            $previewLink = $mkPreviewLinks->mkSinglePreviewLink(
                $importManager->headerData['t3_baseURL'],
                $callParameters['server']
            );
            $out .= $previewLink;
        }
        /** @var $translationData TranslationData */
        $translationData = $factory->getTranslationDataFromCATXMLNodes($importManager->getXMLNodes());
        $translationData->setLanguage($this->sysLanguage);
        $translationData->setPreviewLanguage($this->previewLanguage);
        unset($importManager);
        $service->saveTranslation($l10ncfgObj, $translationData);
        if (empty($out)) {
            $out = 1;
        } //Means OK if preview = 0
        return $out;
    }

    /**
     * Previews the source to import
     *
     * @return string Result output
     * @throws Exception
     */
    protected function previewSource($stringParameter)
    {
        $out = '';
        $error = '';
        /** @var CatXmlImportManager $importManager */
        $importManager = GeneralUtility::makeInstance(
            CatXmlImportManager::class,
            '',
            $this->sysLanguage,
            $stringParameter
        );
        // Parse and check XML, load header data
        if ($importManager->parseAndCheckXMLString() === false) {
            $tmp = var_export($importManager->headerData, true);
            $tmp = str_replace("\n", '', $tmp);
            $error .= $tmp;
            $error .= $this->getLanguageService()->getLL('import.manager.error.parsing.xmlstring.message');
            throw new Exception($error);
        }
        $pageIds = $importManager->getPidsFromCATXMLNodes($importManager->getXmlNodes());
        /** @var MkPreviewLinkService $mkPreviewLinks */
        $mkPreviewLinks = GeneralUtility::makeInstance(
            MkPreviewLinkService::class,
            $importManager->headerData['t3_workspaceId'],
            $importManager->headerData['t3_sysLang'],
            $pageIds
        );
        //Only valid if source language = default language (id=0)
        $previewLink = $mkPreviewLinks->mkSingleSrcPreviewLink($importManager->headerData['t3_baseURL'], 0);
        $out .= $previewLink;

        // Output
        return $out;
    }

    /**
     * Imports data from one or more XML files
     * Several files may be contained in a ZIP archive
     *
     * @return string Result output
     * @throws Exception
     */
    protected function importXMLFile($callParameters)
    {
        $out = '';
        $xmlFilesArr = $this->gatherAllFiles($callParameters['file']);

        if (empty($xmlFilesArr)) {
            throw new Exception("\nNo files to import! Either point to a file using the --file option or define a FTP server to get the files from");
        }

        foreach ($xmlFilesArr as $xmlFile) {
            try {
                $xmlFileHead = $this->getXMLFileHead($xmlFile);
                // Set workspace to the required workspace ID from CATXML:
                $this->getBackendUser()->setWorkspace($xmlFileHead['t3_workspaceId'][0]['XMLvalue']);
                // Set import language to t3_sysLang from XML
                $this->sysLanguage = $xmlFileHead['t3_sysLang'][0]['XMLvalue'];
                if ($xmlFileHead['t3_sourceLang'][0]['XMLvalue'] === $xmlFileHead['t3_targetLang'][0]['XMLvalue']) {
                    $this->previewLanguage = $this->sysLanguage;
                }
                /** @var L10nBaseService $service */
                $service = GeneralUtility::makeInstance(L10nBaseService::class);
                if ($callParameters['importAsDefaultLanguage']) {
                    $service->setImportAsDefaultLanguage(true);
                }
                /** @var TranslationDataFactory $factory */
                $factory = GeneralUtility::makeInstance(TranslationDataFactory::class);
                // Relevant processing of XML Import with the help of the Importmanager
                /** @var CatXmlImportManager $importManager */
                $importManager = GeneralUtility::makeInstance(
                    CatXmlImportManager::class,
                    $xmlFile,
                    $this->sysLanguage,
                    ''
                );
                if ($importManager->parseAndCheckXMLFile() === false) {
                    $out .= "\n\n" . $importManager->getErrorMessages();
                } else {
                    // Find l10n configuration record
                    /** @var L10nConfiguration $l10ncfgObj */
                    $l10ncfgObj = GeneralUtility::makeInstance(L10nConfiguration::class);
                    $l10ncfgObj->load($importManager->headerData['t3_l10ncfg']);
                    $l10ncfgObj->setSourcePid($callParameters['sourcePid']);
                    $status = $l10ncfgObj->isLoaded();
                    if ($status === false) {
                        throw new Exception("l10ncfg not loaded! Exiting...\n");
                    }
                    // Delete previous translations
                    $importManager->delL10N($importManager->getDelL10NDataFromCATXMLNodes($importManager->getXmlNodes()));
                    // Make preview links
                    if ($callParameters['preview']) {
                        $pageIds = [];
                        if (empty($importManager->headerData['t3_previewId'])) {
                            $pageIds = $importManager->getPidsFromCATXMLNodes($importManager->getXmlNodes());
                        } else {
                            $pageIds[0] = $importManager->headerData['t3_previewId'];
                        }
                        /** @var MkPreviewLinkService $mkPreviewLinks */
                        $mkPreviewLinks = GeneralUtility::makeInstance(
                            MkPreviewLinkService::class,
                            $importManager->headerData['t3_workspaceId'],
                            $importManager->headerData['t3_sysLang'],
                            $pageIds
                        );
                        $previewLink = $mkPreviewLinks->mkSinglePreviewLink(
                            $importManager->headerData['t3_baseURL'],
                            $callParameters['server']
                        );
                        $out .= $previewLink;
                    }
                    /** @var $translationData TranslationData */
                    $translationData = $factory->getTranslationDataFromCATXMLNodes($importManager->getXMLNodes());
                    $translationData->setLanguage($this->sysLanguage);
                    $translationData->setPreviewLanguage($this->previewLanguage);
                    unset($importManager);
                    $service->saveTranslation($l10ncfgObj, $translationData);
                    // Store some information about the imported file
                    // This is used later for reporting by mail
                    $this->filesImported[$xmlFile] = [
                        'workspace' => $xmlFileHead['t3_workspaceId'][0]['XMLvalue'],
                        'language' => $xmlFileHead['t3_targetLang'][0]['XMLvalue'],
                        'configuration' => $xmlFileHead['t3_l10ncfg'][0]['XMLvalue'],
                    ];
                }
            } catch (Exception $e) {
                if ($e->getCode() == 1390394945) {
                    $errorMessage = $e->getMessage();
                } else {
                    $errorMessage = 'Badly formatted file (' . $e->getMessage() . ')';
                }
                $out .= "\n\n" . $xmlFile . ': ' . $errorMessage;
                // Store the error message for later reporting by mail
                $this->filesImported[$xmlFile] = [
                    'error' => $errorMessage,
                ];
            }
        }

        // Clean up after import
        $this->importCleanUp();

        // Means Error
        if (!empty($out)) {
            throw new Exception($out);
        }
    }

    /**
     * Gather all the files to be imported, depending on the call parameters
     *
     * @param $file
     * @return array List of files to import
     * @throws Exception
     */
    protected function gatherAllFiles($file)
    {
        $files = [];
        // If no file path was given, try to gather files from FTP
        if (empty($file)) {
            if (!empty($this->extensionConfiguration['ftp_server'])) {
                $files = $this->getFilesFromFtp();
            }
            // Get list of files to import from given command-line parameter
        } else {
            $fileInformation = pathinfo($file);
            // Unzip file if *.zip
            if ($fileInformation['extension'] == 'zip') {
                /** @var Zip $unzip */
                $unzip = GeneralUtility::makeInstance(Zip::class);
                $unzipResource = $unzip->extractFile($file);
                // Process extracted files if file type = xml => IMPORT
                $files = $this->checkFileType($unzipResource['fileArr'], 'xml');
                // Store the temporary directory's path for later clean up
                $this->directoryToCleanUp = $unzipResource['tempDir'];
            } elseif ($fileInformation['extension'] == 'xml') {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Gets all available XML or ZIP files from the FTP server
     *
     * @throws Exception
     * @return array List of files, as local paths
     */
    protected function getFilesFromFtp()
    {
        $files = [];
        // First try connecting and logging in
        $connection = ftp_connect($this->extensionConfiguration['ftp_server']);
        if ($connection === false) {
            throw new Exception('Could not connect to FTP server', 1322489458);
        }
        if (@ftp_login(

            $connection,

            $this->extensionConfiguration['ftp_server_username'],
            $this->extensionConfiguration['ftp_server_password']

        )
        ) {
            ftp_pasv($connection, true);
            // If a path was defined, change directory to this path
            if (!empty($this->extensionConfiguration['ftp_server_downpath'])) {
                $result = ftp_chdir($connection, $this->extensionConfiguration['ftp_server_downpath']);
                if ($result === false) {
                    throw new Exception(
                        'Could not change to directory: ' . $this->extensionConfiguration['ftp_server_downpath'],
                        1322489723
                    );
                }
            }
            // Get list of files to download from current directory
            $filesToDownload = ftp_nlist($connection, '');
            // If there are any files, loop on them
            if ($filesToDownload != false) {
                // Check that download directory exists
                $downloadFolder = 'uploads/tx_l10nmgr/jobs/in/';
                $downloadPath = PATH_site . $downloadFolder;
                if (!is_dir(GeneralUtility::getFileAbsFileName($downloadPath))) {
                    GeneralUtility::mkdir_deep(PATH_site . $downloadFolder);
                }
                foreach ($filesToDownload as $aFile) {
                    // Ignore current directory and reference to upper level
                    if ($aFile != '.' && $aFile != '..') {
                        $fileInformation = pathinfo($aFile);
                        // Download only XML or ZIP files
                        if ($fileInformation['extension'] == 'xml' || $fileInformation['extension'] == 'zip') {
                            $savePath = $downloadPath . $aFile;
                            // Get each file and save them to temporary directory
                            $result = ftp_get($connection, $savePath, $aFile, FTP_BINARY);
                            if ($result) {
                                // If the file is XML, list it for usage as is
                                if ($fileInformation['extension'] == 'xml') {
                                    $files[] = $savePath;
                                } else {
                                    /** @var Zip $unzip */
                                    $unzip = GeneralUtility::makeInstance(Zip::class);
                                    $unzipResource = $unzip->extractFile($savePath);
                                    // Process extracted files if file type = xml => IMPORT
                                    $archiveFiles = $this->checkFileType($unzipResource['fileArr'], 'xml');
                                    $files = array_merge($files, $archiveFiles);
                                    // Store the temporary directory's path for later clean up
                                    $this->directoryToCleanUp = $unzipResource['tempDir'];
                                }
                                // Remove the file from the FTP server
                                $result = ftp_delete($connection, $aFile);
                                // If deleting failed, register error message
                                // (don't throw exception as this does not need to interrupt the process)
                                if (!$result) {
                                    throw new Exception('Could not remove file ' . $aFile . 'from FTP server');
                                }
                                // If getting the file failed, register error message
                                // (don't throw exception as this does not need to interrupt the process)
                            } else {
                                throw new Exception('Problem getting file ' . $aFile . 'from server or saving it locally');
                            }
                        }
                    }
                }
            }
        } else {
            ftp_close($connection);
            throw new Exception('Could not log into to FTP server', 1322489527);
        }

        return $files;
    }

    /**
     * Check file types from a list of files
     *
     * @param array  $files Array of files to be checked
     * @param string $ext   File extension to be tested for
     *
     * @return array Files that passed test
     */
    protected function checkFileType($files, $ext)
    {
        $passed = [];
        foreach ($files as $file) {
            if (preg_match('/' . $ext . '$/', $file)) {
                $passed[] = $file;
            }
        }
        return $passed;
    }

    /**
     * Extracts the header of a CATXML file
     *
     * @param string $filepath Path to the file
     *
     * @return bool
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getXMLFileHead($filepath)
    {
        $getURLReport = [];
        $fileContent = GeneralUtility::getUrl($filepath, 0, false, $getURLReport);
        if ($getURLReport['error']) {
            throw new Exception(
                "File or URL cannot be read.\n \\TYPO3\\CMS\\Core\\Utility\\GeneralUtility::getURL() error code: " . $getURLReport['error'] . "\n \\TYPO3\\CMS\\Core\\Utility\\GeneralUtility::getURL() message: “" . $getURLReport['message'] . '”',
                1390394945
            );
        }
        // For some reason PHP chokes on incoming &nbsp; in XML!
        $xmlNodes = GeneralUtility::xml2tree(str_replace('&nbsp;', '&#160;', $fileContent), 3);
        if (!is_array($xmlNodes)) {
            throw new Exception(
                $this->getLanguageService()->getLL('import.manager.error.parsing.xml2tree.message') . $xmlNodes,
                1322480030
            );
        }
        $headerInformationNodes = $xmlNodes['TYPO3L10N'][0]['ch']['head'][0]['ch'];
        if (!is_array($headerInformationNodes)) {
            throw new Exception(
                $this->getLanguageService()->getLL('import.manager.error.missing.head.message'),
                1322480056
            );
        }
        return $headerInformationNodes;
    }

    /**
     * Cleans up after the import process, as needed
     */
    protected function importCleanUp()
    {
        // Clean up directory into which ZIP archives were uncompressed, if any
        if (!empty($this->directoryToCleanUp)) {
            /** @var Zip $unzip */
            $unzip = GeneralUtility::makeInstance(Zip::class);
            $unzip->removeDir($this->directoryToCleanUp);
        }
    }

    /**
     * This method is unused
     *
     * Sends reporting mail about which files were imported
     */
    protected function sendMailNotification()
    {
        return;
        // Send mail only if notifications are active and at least one file was imported
        if ($this->extensionConfiguration['enable_notification'] && count($this->filesImported) > 0) {
            // If at least a recipient is indeed defined, proceed with sending the mail
            $recipients = GeneralUtility::trimExplode(',', $this->extensionConfiguration['email_recipient_import']);
            if (count($recipients) > 0) {
                // First of all get a list of all workspaces and all l10nmgr configurations to use in the reporting
                $workspaces = $this->getDatabaseConnection()->exec_SELECTgetRows(
                    'uid,title',
                    'sys_workspace',
                    '',
                    '',
                    '',
                    '',
                    'uid'
                );
                $l10nConfigurations = $this->getDatabaseConnection()->exec_SELECTgetRows(
                    'uid,title',
                    'tx_l10nmgr_cfg',
                    '',
                    '',
                    '',
                    '',
                    'uid'
                );
                // Start assembling the mail message
                $message = sprintf(
                        $this->getLanguageService()->getLL('import.mail.intro'),
                        date('d.m.Y H:i:s', $GLOBALS['EXEC_TIME']),
                        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']
                    ) . "\n\n";
                foreach ($this->filesImported as $file => $fileInformation) {
                    if (isset($fileInformation['error'])) {
                        $status = $this->getLanguageService()->getLL('import.mail.error');
                        $message .= '[' . $status . '] ' . sprintf(
                                $this->getLanguageService()->getLL('import.mail.file'),
                                $file
                            ) . "\n";
                        $message .= "\t" . sprintf(
                                $this->getLanguageService()->getLL('import.mail.import.failed'),
                                $fileInformation['error']
                            ) . "\n";
                    } else {
                        $status = $this->getLanguageService()->getLL('import.mail.ok');
                        $message .= '[' . $status . '] ' . sprintf(
                                $this->getLanguageService()->getLL('import.mail.file'),
                                $file
                            ) . "\n";
                        // Get the workspace's name and add workspace information
                        if ($fileInformation['workspace'] == 0) {
                            $workspaceName = 'LIVE';
                        } else {
                            if (isset($workspaces[$fileInformation['workspace']])) {
                                $workspaceName = $workspaces[$fileInformation['workspace']]['title'];
                            } else {
                                $workspaceName = $this->getLanguageService()->getLL('import.mail.workspace.unknown');
                            }
                        }
                        $message .= "\t" . sprintf(
                                $this->getLanguageService()->getLL('import.mail.workspace'),
                                $workspaceName,
                                $fileInformation['workspace']
                            ) . "\n";
                        // Add language information
                        $message .= "\t" . sprintf(
                                $this->getLanguageService()->getLL('import.mail.language'),
                                $fileInformation['language']
                            ) . "\n";
                        // Get configuration's name and add configuration information
                        if (isset($l10nConfigurations[$fileInformation['configuration']])) {
                            $configurationName = $l10nConfigurations[$fileInformation['configuration']]['title'];
                        } else {
                            $configurationName = $this->getLanguageService()->getLL('import.mail.l10nconfig.unknown');
                        }
                        $message .= "\t" . sprintf(
                                $this->getLanguageService()->getLL('import.mail.l10nconfig'),
                                $configurationName,
                                $fileInformation['configuration']
                            ) . "\n";
                    }
                }
                // Add signature
                $message .= "\n\n" . $this->getLanguageService()->getLL('email.goodbye.msg');
                $message .= "\n" . $this->extensionConfiguration['email_sender_name'];
                $subject = sprintf(
                    $this->getLanguageService()->getLL('import.mail.subject'),
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']
                );
                // Instantiate the mail object, set all necessary properties and send the mail
                /** @var MailMessage $mailObject */
                $mailObject = GeneralUtility::makeInstance('\TYPO3\CMS\Core\Mail\MailMessage');
                $mailObject->setFrom([$this->extensionConfiguration['email_sender'] => $this->extensionConfiguration['email_sender_name']]);
                $mailObject->setTo($recipients);
                $mailObject->setSubject($subject);
                $mailObject->setFormat('text/plain');
                $mailObject->setBody($message);
                $mailObject->send();
            }
        }
    }

    /**
     * Get DatabaseConnection instance - $GLOBALS['TYPO3_DB']
     *
     * This method should be used instead of direct access to
     * $GLOBALS['TYPO3_DB'] for easy IDE auto completion.
     *
     * @return DatabaseConnection
     * @deprecated since TYPO3 v8, will be removed in TYPO3 v9
     */
    protected function getDatabaseConnection()
    {
        GeneralUtility::logDeprecatedFunction();
        return $GLOBALS['TYPO3_DB'];
    }
}
