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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Import extends Command
{
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
                'UID of the page used during export. Needs configuration depth to be set to "current page" Default = 0'
            )
            ->addOption('string', 's', InputOption::VALUE_OPTIONAL, 'XML string to import.')
            ->addOption(
                'task',
                't',
                InputOption::VALUE_OPTIONAL,
                "The values can be:\n importString = Import a XML string\n importFile = Import a XML file\n preview = Generate a preview of the source from a XML string\n"
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
    }
}
