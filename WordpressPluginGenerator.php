<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Cocur\Slugify\Slugify;

class WordpressPluginGenerator extends Command
{
    /**
     * @var string
     */
    private string $name;

    /**
     * @var string
     */
    private string $slug;

    /**
     * @var string
     */
    private string $composerName;

    /**
     * @var string
     */
    private string $description;

    /**
     * @var string
     */
    private string $directory;

    /**
     * @var string
     */
    private string $author;

    /**
     * @var string
     */
    private string $email;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var int kb
     */
    private const FILE_LIMIT_SIZE = 1024;

    /**
     * @var string[]
     */
    private array $replaceTags;

    /**
     * @var bool
     */
    private bool $isComposerInstalled;

    /**
     * @const string[]
     */
    private const VALID_EXTENSIONS = ['php', 'json', 'tpl', 'css', 'js', 'html', 'txt', 'md'];

    /**
     * @const string[]
     */
    private const IGNORED_EXTENSIONS = ['map', 'editorconfig', 'gitignore', 'jshintrc', 'npmignore', 'yml', 'bowerrc', 'lock'];

    /**
     * @const string
     */
    private const DEFAULT_PLUGIN_DIRECTORY = '/var/www/html';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('Plugin:Generator')
             ->setDescription('Wordpress Plugin Base Generator');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        unset($input, $output);

        if (!class_exists('ZipArchive')) {
            $this->io->error('Unfortunately you don\'t have "ZipArchive" available so it\'s not possible to go ahead.');
            $this->io->note('For more details see https://www.php.net/manual/en/class.ziparchive.php');
            exit();
        }

        $process = new Process([
            'bower',
            '-v'
        ]);

        $process->run();
        if (!$process->isSuccessful()) {
            $this->io->error('Unfortunately you don\'t have "Bower" available so it\'s not possible to go ahead.');
            $this->io->note('For more details see https://bower.io');
            exit();
        }

        $process = new Process([
            'composer',
            '--version'
        ]);
        $process->run();
        $this->isComposerInstalled = $process->isSuccessful();

        $this->io->ask('Plugin\'s name*', null, function ($pluginName) {
            if (is_null($pluginName)) {
                throw new \RuntimeException('You must type a name.');
            } else {
                $this->name = $pluginName;
            }
        });

        $this->io->ask('A valid composer.json name for the plugin*', null, function ($composerName) {
            if (is_null($composerName)) {
                throw new \RuntimeException('You must type a composer.json name.');
            } elseif (!preg_match("^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*^", $composerName)) {
                throw new \RuntimeException(
                    'Does not match the regex pattern ^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$'
                );
            } else {
                $this->composerName = $composerName;
            }
        });

        $this->io->ask('Plugin\'s description*', null, function ($description) {
            if (is_null($description)) {
                throw new \RuntimeException('You must type a description.');
            } else {
                $this->description = $description;
            }
        });

        $this->slug = $this->io->ask('Plugin\'s slug', $this->name);
        $this->slug = (new Slugify())->slugify($this->slug);

        $this->io->ask('Plugin author\'s name*', null, function ($authorName) {
            if (is_null($authorName)) {
                throw new \RuntimeException('You must type a author name.');
            } else {
                $this->author = $authorName;
            }
        });

        $this->io->ask('Plugin author\'s email*', null, function ($email) {
            if (is_null($email)) {
                throw new \RuntimeException('You must type an email.');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException("{$email} is not a valid email address");
            } else {
                $this->email = $email;
            }
        });

        $this->io->ask(
            'Directory to create the plugin',
            self::DEFAULT_PLUGIN_DIRECTORY,
            function ($directory) {
                if (is_null($directory)) {
                    throw new \RuntimeException('You must type the directory.');
                } elseif (!is_dir($directory)) {
                    throw new \RuntimeException('The directory does not exist.');
                } else {
                    if (substr($directory, strlen($directory) - 1) != DIRECTORY_SEPARATOR) {
                        $directory .= DIRECTORY_SEPARATOR;
                    }
                    $testFileName = hash('sha256', 'wordpress-plugin-generator-test' . mt_rand(1, time()));
                    $checkWrite = @file_put_contents("{$directory}/{$testFileName}", '');
                    if ($checkWrite === false) {
                        throw new \RuntimeException('The directory is not writable!');
                    } else {
                        unlink("{$directory}/{$testFileName}");
                    }

                    $this->generateProjectFolderName($directory);
                }
            }
        );

        $this->io->note('*** Starting generation of the plugin ***');
        $this->copyBaseFiles();

        $this->io->newLine();
        $this->io->newLine();

        $this->replaceTags = [
            'generator-name' => $this->name,
            'generator-composer-name' => $this->composerName,
            'generator-description' => $this->description,
            'generator-author-name' => $this->author,
            'generator-author-email' => $this->email,
            'generator-slug' => $this->slug,
            'generator-year' => $this->slug,
            'generator-slug-capslock' => str_replace('-', '_', strtoupper($this->slug))
        ];

        $this->io->note('*** Setting up files definitions ***');
        $this->scanFiles($this->directory);

        $this->io->newLine();
        $this->io->newLine();

        $this->io->note('*** Installing dependencies(composer) ***');
        $this->runComposer();

        $this->io->newLine();
        $this->io->newLine();

        $this->io->note('*** Installing dependencies(bower) ***');
        $this->runBower();

        $this->io->success('+++++ Success! Enjoy your new plugin =) +++++');

        return Command::SUCCESS;
    }

    /**
     * Download composer.phar to install backend dependencies
     * if composer is not available
     */
    private function downloadComposer(): void
    {
        $this->io->writeln('- Downloading Composer. It can take some time');

        if (
        !file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'composer.phar',
            file_get_contents('https://getcomposer.org/composer.phar')
        )
        ) {
            $this->io->error(
                'Whoops, something went wrong... Not possible to downloaded composer in the project folder - ERRRC04'
            );
            $this->io->note(
                'The plugin was generated but still needs to run composer to install the dependencies of the project'
            );
            return;
        }

        $this->io->writeln('- Composer downloaded!');
    }

    /**
     * Run composer to install backend dependencies
     *
     * @return void
     */
    private function runComposer(): void
    {
        if (!$this->isComposerInstalled) {
            $this->downloadComposer();
            $command = [
                'php',
                'composer.phar',
                'install'
            ];
        } else {
            $command = [
                'composer',
                'install'
            ];
        }

        $this->io->writeln('- Running Composer Install. It can take some time');

        $process = new Process(
            $command,
            $this->directory
        );

        $process->run();
        if (!$process->isSuccessful()) {
            $this->io->error(
                'Whoops, something went wrong... Not possible to run composer in the project folder ' .
                $process->getErrorOutput()
            );
            $this->io->note(
                'The plugin was generated but still needs to run composer to install the dependencies of the project'
            );
        } else {
            $this->io->writeln('- Packages successfully installed');
        }

        if (!$this->isComposerInstalled) {
            unlink($this->directory . DIRECTORY_SEPARATOR . 'composer.phar');
        }
    }

    /**
     * Runs bower to install frontend dependencies
     *
     * @return void
     */
    private function runBower(): void
    {
        $this->io->writeln('- Running Bower install. It can take some time');

        $process = new Process(
            [
                'bower',
                '-allow-root',
                'install'
            ],
            $this->directory
        );

        $process->run();
        if (!$process->isSuccessful()) {
            $this->io->error(
                'Whoops, something went wrong... Not possible to run bower install in the project folder ' .
                $process->getErrorOutput()
            );
            $this->io->note(
                'The plugin was generated but still needs to run bower to install the dependencies of the project'
            );
        } else {
            $this->io->writeln('- Packages successfully installed');
        }
    }

    /**
     * Create a folder for the project in the path
     * set up by the user. If the path already exists
     * it adds "-{num}" in front of it.
     *
     * @param $directory
     *
     * @return void
     */
    private function generateProjectFolderName($directory): void
    {
        $slug = $this->slug;
        $count = 1;
        while (is_dir($directory . $slug . DIRECTORY_SEPARATOR)) {
            $slug = $this->slug . '-' . $count;
            $count++;
        }

        $this->slug = $slug;
        $this->directory = $directory . $this->slug;
    }

    /**
     * Open a Zip file with the base MVC project
     * and put in the place set up by the user
     *
     * @return void
     */
    private function copyBaseFiles(): void
    {
        $this->io->writeln('- Creating the base project folder');

        if (!mkdir($this->directory)) {
            $this->io->error(
                'Whoops, something went wrong... ' .
                'Impossible to create the project folder on the destination directory. - ERRCP01'
            );
        }

        $this->io->writeln('- Coping the base files');
        $zip = new \ZipArchive();
        $res = $zip->open(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'wordpress-plugin-base.zip');
        if ($res === true) {
            $zip->extractTo($this->directory);
            $zip->close();
            $this->io->note('==== Base files successfully copied to the destination directory. ====');
        } else {
            $this->io->error(
                'Whoops, something went wrong... Impossible to unzipbase file to the project folder . - ERRUF02'
            );
            rmdir($this->directory);
            exit();
        }

        // rename main plugin file
        rename(
            $this->directory . DIRECTORY_SEPARATOR . 'wordpress-plugin-base.php',
            $this->directory . DIRECTORY_SEPARATOR . "{$this->slug}.php"
        );
    }

    /**
     * Recursive method which runs file by file in the base
     * project folder calling the replacement method for them.
     *
     * @param $dir
     *
     * @return void
     */
    private function scanFiles($dir): void
    {
        $cDir = scandir($dir);
        foreach ($cDir as $key => $value) {
            if (!in_array($value, ['.', '..'])) {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $value)) {
                    $this->scanFiles($dir . DIRECTORY_SEPARATOR . $value);
                } else {
                    $this->makeReplacements($dir . DIRECTORY_SEPARATOR . $value);
                }
            }
        }
    }

    /**
     * Get all information entered by the user and
     * fill them where needed
     *
     * @param $file
     *
     * @return void
     */
    private function makeReplacements($file): void
    {
        $this->io->writeln(' - ' . $file);

        $fileSize = round(filesize($file) / 1024); // kilobytes
        $fileExtension = pathinfo($file, PATHINFO_EXTENSION);

        if (is_file($file)) {
            if (in_array($fileExtension, self::IGNORED_EXTENSIONS)) {
                $this->io->note(' Skipping the file as its extension is in the ignored list');
                return;
            } elseif (!in_array($fileExtension, self::VALID_EXTENSIONS)) {
                if (
                !$this->io->confirm(
                    ' This file extension is different of the validated extensions. ' .
                    'Would you like to continue with this file?',
                    true
                )
                ) {
                    return;
                }
            }

            if ($fileSize > self::FILE_LIMIT_SIZE) {
                if (
                !$this->io->confirm(
                    "This file size({$fileSize}kb) is bigger than the limit(" . self::FILE_LIMIT_SIZE . 'kb). ' .
                    'Would you like to continue with this file?',
                    true
                )
                ) {
                    return;
                }
            }

            $content = file_get_contents($file);
            foreach ($this->replaceTags as $key => $value) {
                $content = str_replace('{{' . $key . '}}', $value, $content);
            }

            $indicator = fopen($file, 'w+');
            fwrite($indicator, $content);
            fclose($indicator);
        } else {
            $this->io->warning('Not possible to set up. File not found.');
        }
    }
}
