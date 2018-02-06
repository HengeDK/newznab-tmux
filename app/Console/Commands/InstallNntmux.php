<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Settings;
use Illuminate\Console\Command;
use App\Extensions\util\Versions;
use Symfony\Component\Process\Process;

class InstallNntmux extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install NNTmux';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws \Symfony\Component\Process\Exception\LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function handle()
    {
        if (! \defined('NN_INSTALLER')) {
            \define('NN_INSTALLER', true);
        }

        $error = false;

        if (env('DB_SYSTEM') !== 'mysql') {
            $this->error('Invalid database system. Must be: mysql ; Not: '.env('DB_SYSTEM'));
            $error = true;
        }

        if (! (new Settings())->isDbVersionAtLeast(NN_MINIMUM_MARIA_VERSION) || ! (new Settings())->isDbVersionAtLeast(NN_MINIMUM_MYSQL_VERSION)) {
            $this->error('Version of MariaDB/MySQL used is lower than required version: '.NN_MINIMUM_MARIA_VERSION.PHP_EOL.' Please update your install of Mariadb/MySQL');
            $error = true;
        }

        if (! $error) {
            if ($this->confirm('Are you sure you want to install NNTmux? This will wipe your database!!')) {
                if (file_exists(NN_ROOT.'_install/install.lock')) {
                    if ($this->confirm('Do you want to remove install.lock file so you can continue with install?')) {
                        $this->info('Removing install.lock file so we can continue with install process');
                        $remove = new Process('rm _install/install.lock');
                        $remove->setTimeout(600);
                        $remove->run(function ($type, $buffer) {
                            if (Process::ERR === $type) {
                                echo 'ERR > '.$buffer;
                            } else {
                                echo $buffer;
                            }
                        });
                    } else {
                        $this->info('Not removing install.lock, stopping install process');
                        exit;
                    }
                }
                $this->info('Migrating tables and seeding them with initial data');
                if (env('APP_ENV') !== 'production') {
                    $process = new Process('php artisan migrate:fresh --seed');
                    $process->setTimeout(600);
                    $process->run(function ($type, $buffer) {
                        if (Process::ERR === $type) {
                            echo 'ERR > '.$buffer;
                        } else {
                            echo $buffer;
                        }
                    });
                } else {
                    $process = new Process('php artisan migrate:fresh --force');
                    $process->setTimeout(600);
                    $process->run(function ($type, $buffer) {
                        if (Process::ERR === $type) {
                            echo 'ERR > '.$buffer;
                        } else {
                            echo $buffer;
                        }
                    });

                    $process2 = new Process('php artisan fixtures:up all');
                    $process2->setTimeout(600);
                    $process2->run(function ($type, $buffer) {
                        if (Process::ERR === $type) {
                            echo 'ERR > '.$buffer;
                        } else {
                            echo $buffer;
                        }
                    });
                }

                if ($this->updatePatch()) {
                    $paths = $this->updatePaths();
                    if ($paths !== false) {
                        $sql1 = Settings::query()->where('setting', '=', 'nzbpath')->update(['value' => $paths['nzb_path']]);
                        $sql2 = Settings::query()->where('setting', '=', 'tmpunrarpath')->update(['value' => $paths['unrar_path']]);
                        $sql3 = Settings::query()->where('setting', '=', 'coverspath')->update(['value' => $paths['covers_path']]);
                        if ($sql1 === null || $sql2 === null || $sql3 === null) {
                            $error = true;
                        } else {
                            $this->info('Settings table updated successfully');
                        }
                    }
                }

                if (! $error && $this->addAdminUser()) {
                    @file_put_contents(NN_ROOT.'_install/install.lock', '');
                    $this->info('Generating application key');
                    $process = new Process('php artisan key:generate --force');
                    $process->setTimeout(600);
                    $process->run(function ($type, $buffer) {
                        if (Process::ERR === $type) {
                            echo 'ERR > '.$buffer;
                        } else {
                            echo $buffer;
                        }
                    });
                    $this->info('NNTmux installation completed successfully');
                    exit();
                }

                $this->error('NNTmux installation failed. Fix reported problems and try again');
            } else {
                $this->info('Stopping install process');
                exit;
            }
        }
    }

    /**
     * @return bool
     */
    protected function updatePatch(): bool
    {
        $ver = new Versions();
        $patch = $ver->getSQLPatchFromFile();
        $updateSettings = false;
        if ($patch > 0) {
            $updateSettings = Settings::query()->where(['section' => '', 'subsection' => '', 'name' => 'sqlpatch'])->update(['value' => $patch]);
        }

        // If it all worked, continue the install process.
        if ($updateSettings !== false) {
            $this->info('Database updated successfully');

            return true;
        }

        $this->error('Could not update sqlpatch to '.$patch.' for your database.');

        return false;
    }

    /**
     * @return array|bool
     * @throws \RuntimeException
     */
    protected function updatePaths()
    {
        $covers_path = NN_RES.'covers'.DS;
        $nzb_path = NN_RES.'nzb'.DS;
        $tmp_path = NN_RES.'tmp'.DS;
        $unrar_path = $tmp_path.'unrar'.DS;

        $nzbPathCheck = is_writable($nzb_path);
        if ($nzbPathCheck === false) {
            $this->warn($nzb_path.' is not writable. Please fix folder permissions');

            return false;
        }

        $lastChar = substr($nzb_path, \strlen($nzb_path) - 1);
        if ($lastChar !== '/') {
            $nzb_path .= '/';
        }

        if (! file_exists($unrar_path)) {
            $this->info('Creating missing '.$unrar_path.' folder');
            if (! @mkdir($unrar_path) && ! is_dir($unrar_path)) {
                throw new \RuntimeException('Unable to create '.$unrar_path.' folder');
            }
            $this->info('Folder '.$unrar_path.' successfully created');
        }
        $unrarPathCheck = is_writable($unrar_path);
        if ($unrarPathCheck === false) {
            $this->warn($unrar_path.' is not writable. Please fix folder permissions');

            return false;
        }

        $lastChar = substr($unrar_path, \strlen($unrar_path) - 1);
        if ($lastChar !== '/') {
            $unrar_path .= '/';
        }

        $coversPathCheck = is_writable($covers_path);
        if ($coversPathCheck === false) {
            $this->warn($covers_path.' is not writable. Please fix folder permissions');

            return false;
        }

        $lastChar = substr($covers_path, \strlen($covers_path) - 1);
        if ($lastChar !== '/') {
            $covers_path .= '/';
        }

        return [
            'nzb_path' => $nzb_path,
            'covers_path' => $covers_path,
            'unrar_path' => $unrar_path,
        ];
    }

    /**
     * @return bool
     */
    protected function addAdminUser(): bool
    {
        if (env('ADMIN_USER') === '' || env('ADMIN_PASS') === '' || env('ADMIN_EMAIL') === '') {
            $this->error('Admin user data cannot be empty! Please edit .env file and fill in admin user details and run this script again!');
            exit();
        }

        $this->info('Adding admin user to database');
        try {
            User::add(env('ADMIN_USER'), env('ADMIN_PASS'), env('ADMIN_EMAIL'), 2, '', '', '', '');
        } catch (\Exception $e) {
            $this->error('Unable to add admin user!');

            return false;
        }

        return true;
    }
}