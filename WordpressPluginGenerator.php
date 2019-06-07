<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Cocur\Slugify\Slugify;

class WordpressPluginGenerator extends Command
{

    private $name = null;
    private $slug = null;
    private $slugCapslock = null;
    private $description = null;
    private $directory = null;
    private $author = null;
    private $email = null;
    private $io = null;
    private $helper = null;
    
    private $validExtensions = ['php', 'json', 'tpl', 'css', 'js'];
    private $fileSizeLimit = 1024; //kb

    protected function configure()
    {
        $this->setName("Plugin:Generator")
             ->setDescription("Wordpress Plugin Base Generator");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        
        $this->io = new SymfonyStyle($input, $output);
        unset($input, $output);
        
        if(!class_exists('ZipArchive')) {
            $this->io->error('Unfortunately you don\'t have "ZipArchive" available so it\'s not possible to go ahead.');
            $this->io->note('Formore details see https://www.php.net/manual/en/class.ziparchive.php');
            exit();
        }
    
        $this->io->ask('Plugin\'s name*', null, function ($name) {
            if (is_null($name)) {
                throw new \RuntimeException('You must type a name.');
            } else {
                $this->name = $name;
            }
        });
        
        $this->io->ask('Plugin\'s description*', null, function ($description) {
            if (is_null($description)) {
                throw new \RuntimeException('You must type a description.');
            } else {
                $this->description = $description;
            }
        });
        
        $this->slug = $this->io->ask('Plugin\'s slug', null);
        if(is_null($this->slug) || empty($this->slug)) {
            $this->slug = $this->name;
        }
        $this->slug = (new Slugify())->slugify($this->slug);
        
        $this->io->ask('Plugin author\'s name*', null, function ($name) {
            if (is_null($name)) {
                throw new \RuntimeException('You must type a author name.');
            } else {
                $this->name = $name;
            }
        });
        
        $this->io->ask('Plugin author\'s email*', null, function ($email) {
            if (is_null($email)) {
                throw new \RuntimeException('You must type an email.');
            } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException("{$email} is not a valid email address");
            } else {
                $this->email = $email;
            }
        });
        
        $this->io->ask('Directory to create the plugin*', null, function ($directory) {
            if (is_null($directory)) {
                throw new \RuntimeException('You must type the directory.');
            } else if(!is_dir($directory)){
                throw new \RuntimeException('The directory does not exist.');
            } else {
                if(substr($directory, strlen($directory)-1) != DIRECTORY_SEPARATOR) {
                    $directory .= DIRECTORY_SEPARATOR;
                }
                $checkWrite = file_put_contents($directory.'test.txt', 'just a test');
                if($checkWrite!=11) {
                    throw new \RuntimeException('The directory is not writable!');
                } else{
                    unlink($directory.'test.txt');
                }
                
                $this->generateProjectFolderName($directory);
                
            }
        });
        
        $this->io->note('*** Starting generation of the plugin ***');
        $this->copyBaseFiles();
        
        $this->io->newLine();
        $this->io->newLine();
        
        $this->io->note('*** Setting up files definitions ***');
        $this->scanFiles($this->directory);
        
        $this->io->newLine();
        $this->io->newLine();
        
        $this->io->note('*** Installing dependencies(composer) ***');
        $this->runComposer();
        
        $this->io->success('+++++ Success! Enjoy your new plugin =) +++++');

    }
    
    private function runComposer()
    {
        
        $this->io->writeln('- Downloading Composer. It can take some time');
        $wget = new Process(sprintf('wget getcomposer.org/composer.phar -O %s/composer.phar', $this->directory, $this->directory));
        $wget->run();

        if(!$wget->isSuccessful()) {
            $this->io->error('Whoops, something went wrong... Not possible to downloaded composer in the project folder - ERRIC03');
            $this->io->note("The plugin was generated but still needs to run composer to install the dependencies of the project");
            return;
        }
        
        $install = new Process(sprintf('cd %s && php composer.phar install', $this->directory));
        $install->run();

        if(!$install->isSuccessful()) {
            $this->io->error('Whoops, something went wrong... Not possible to run composer in the project folder - ERRRC04');
            $this->io->note("The plugin was generated but still needs to run composer to install the dependencies of the project");
        } else{
            $this->io->writeln('- Packages succesfully installed');
        }
        
        unlink($this->directory . DIRECTORY_SEPARATOR . 'composer.phar');

    }
    
    private function generateProjectFolderName($directory)
    {
        
        $slug = $this->slug;
        $count = 1;
        while(is_dir($directory . $slug . DIRECTORY_SEPARATOR)) {
            $slug = $this->slug . '-' . $count;
            $count++;
        }
        
        $this->slug = $slug;
        $this->directory = $directory . $this->slug;
        
    }
    
    private function copyBaseFiles()
    {
        $this->io->writeln('- Creating the base project folder');
        
        if(!mkdir($this->directory)) {
            $this->io->error('Whoops, something went wrong... Impossible to create the project folder on the destination directory. - ERRCP01');
        }
        
        $this->io->writeln('- Coping the base files');
        $zip = new \ZipArchive;
        $res = $zip->open(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'wordpress-plugin-base.zip');
        if ($res === TRUE) {
          $zip->extractTo($this->directory);
          $zip->close();
          $this->io->note("==== Base files successfully copied to the destination directory. ====");
        } else {
          $this->io->error('Whoops, something went wrong... Impossible to unzipbase file to the project folder . - ERRUF02');
          rmdir($this->directory);
          exit();
        }
        
        // rename main plugin file
        rename($this->directory . DIRECTORY_SEPARATOR . "wordpress-plugin-base.php", $this->directory . DIRECTORY_SEPARATOR . "{$this->slug}.php");
        
    }
    
    private function scanFiles($dir)
    { 
   
        $cdir = scandir($dir); 
        foreach ($cdir as $key => $value)  { 
            if (!in_array($value, ['.', '..'])) { 
                if (is_dir($dir . DIRECTORY_SEPARATOR . $value)) { 
                    $this->scanFiles($dir . DIRECTORY_SEPARATOR . $value); 
                } else { 
                    $this->makeReplacements($dir . DIRECTORY_SEPARATOR . $value);
                } 
            } 
        }
        
    }
    
    private function makeReplacements($file)
    {
        $this->io->writeln(' - ' . $file);
        
        $fileSize = round(filesize($file) / 1024, 0); // kilobytes
        $fileExtension = pathinfo($file, PATHINFO_EXTENSION);
        
        if(is_file($file)) {
            
            if(!in_array($fileExtension, $this->validExtensions)) {
                if(!$this->io->confirm("This file extension is different of the validated extension. Would you like to continue with this file?", true)) {
                    return;
                }
            }
            
            if($fileSize > $this->fileSizeLimit) {
                if(!$this->io->confirm("This file size({$fileSize}kb) is bigger than the limit({$this->fileSizeLimit}). Would you like to continue with this file?", true)) {
                    return;
                }
            }
            
            $content = file_get_contents($file);
            
            $replaces = [
                    'generator-name' => $this->name,
                    'generator-description' => $this->description,
                    'generator-author-name' => $this->author,
                    'generator-author-email' => $this->email,
                    'generator-slug' => $this->slug,
                    'generator-slug-capslock' => str_replace('-','_', strtoupper($this->slug))
                ];
            
            foreach($replaces as $key=>$value) {
                $content = str_replace('{{' . $key . '}}', $value, $content);
            }
            
            $indicator = fopen($file, 'w+');
            fwrite($indicator, $content);
            fclose($indicator);
            
            
        } else {
            $this->io->warning('Not possible to set up, verify this file if you have any problem with the plugin.');
        }
    }

    private function generate2()
    {
        // return $this->io->note("<info>name: {$this->name} - slug: {$this->slug}.</info>");
    
    // agora é pegar e gerar este caralho!
    
    $scanned_directory = array_diff(scandir($this->directory), array('..', '.'));
    print_r($scanned_directory);
    
    exit();
    
    
          $this->output->writeln("<info>Genereted.</info>");
            $io = new SymfonyStyle($this->input, $this->output);
          $io->newLine();
          $io->note('Lorem ipsum dolor sit amet note');
          $io->caution('Lorem ipsum dolor sit amet caution ');
          /*$io->progressStart(10);
          sleep(2);
          $io->progressStart(20);
          sleep(2);
          $io->progressStart(30);
          sleep(2);
          $io->progressStart(40);
          sleep(2);
          $io->progressStart(50);
          sleep(2);
          $io->progressStart(60);
          sleep(2);
          $io->progressStart(70);
          sleep(2);
          $io->progressStart(80);
          sleep(2);
          $io->progressStart(90);
          sleep(2);
          $io->progressAdvance();
          sleep(1);
          $io->progressAdvance();
          sleep(1);
          $io->progressAdvance();
          sleep(1);
          $io->progressAdvance();
          sleep(1);
          $io->progressStart(100);
          sleep(2);
          $io->progressFinish();*/
          
          $io->ask('Number of workers to start', 1, function ($number) {
                if (!is_numeric($number)) {
                    throw new \RuntimeException('You must type a number.');
                }
            
                $this->output->writeln("The number is $number");
            });
          
          $io->askHidden('What is your password?', function ($password) {
                if (empty($password)) {
                    throw new \RuntimeException('Password cannot be empty.');
                }
            
                 $this->output->writeln("The pw is $password");
            });
          
          $aaa = $io->confirm('Restart the web server?', true);
          $this->output->writeln("The aaa is $aaa");
          
          $io->success('Lorem ipsum dolor sit amet success');
          
          $io->warning('Lorem ipsum dolor sit amet warning');
          
          $io->error('Lorem ipsum dolor sit amet error');

           return $this->output->writeln("<info>aaaa.</info>");
    }
    
}
