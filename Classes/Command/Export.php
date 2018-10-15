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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Export extends Command
{
    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $this->setDescription('Export the translations as file')
            ->setHelp('With this command you can Export translation')
            ->addOption('check-exports', null, InputOption::VALUE_NONE, 'Check for already exported content')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, "UIDs of the localization manager configurations to be used for export. Comma seperated values, no spaces.\nDefault is EXTCONF which means values are taken from extension configuration.")
            ->addOption('forcedSourceLanguage', 'f', InputOption::VALUE_OPTIONAL, 'UID of the already translated language used as overlaid source language during export.')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, "Format for export of translatable data can be:\n CATXML = XML for translation tools (default)\n EXCEL = Microsoft XML format")
            ->addOption('hidden', null, InputOption::VALUE_NONE, 'Do not export hidden contents')
            ->addOption('srcPID', 'p', InputOption::VALUE_OPTIONAL, 'UID of the page used during export. Needs configuration depth to be set to "current page" Default = 0')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'UIDs for the target languages used during export. Comma seperated values, no spaces. Default is 0. In that case UIDs are taken from extension configuration.')
            ->addOption('updated', 'u', InputOption::VALUE_NONE, 'Export only new/updated contents')
            ->addOption('workspace', 'w', InputOption::VALUE_OPTIONAL, 'UID of the workspace used during export. Default = 0')
            ;
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
    }
}
