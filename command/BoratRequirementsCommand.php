<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use function json_decode;
use function json_encode;

/**
 * Class BoratRequirementsCommand
 * @package App\Console\Commands
 */
class BoratRequirementsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'borat:requirements';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add requirements to database';
    /**
     * @var array
     */
    private $error = [
        'download-url-missing' => 0
    ];
    /**
     * @var array
     */
    private $count = [
        'package-add' => 0
    ];

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
     * @param string $key
     * @return bool
     */
    public function isLib(string $key)
    {
        return strpos($key, 'lib-') === 0;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function isExt(string $key)
    {
        return strpos($key, 'ext-') === 0;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function isPhp(string $key)
    {
        return $key === 'php';
    }

    /**
     * @param string $key
     * @return bool
     */
    public function isHhvm(string $key)
    {
        return $key === 'hhvm';
    }

    /**
     * @param string $key
     * @return bool
     */
    public function isBlacklisted(string $key)
    {
        $blacklist = ['composer-plugin-api'];

        if(in_array($key, $blacklist)) {
            return true;
        }

        return false;
    }

    /**
     * @param array $packages
     * @param string $type
     */
    public function addPackages(array $packages, string $type)
    {
        foreach($packages as $key => $item) {
            if(!$this->isPhp($key) && !$this->isHhvm($key) && !$this->isExt($key) && !$this->isLib($key) && !$this->isBlacklisted($key)) {
                $package = DB::table('packages')->where('fullname', '=', $key);

                if($package->count() === 0) {
                    $tmp = explode('/', $key);

                    if(empty($tmp[1])) {
                        var_dump($key);
                        die();
                    }

                    $insert = [
                        'repo' => 'git@github.com:' . $tmp[0] . '/' . $tmp[1] . '.git',
                        'fullname' => $key,
                        'vendor' => $tmp[0],
                        'module' => $tmp[1],
                        'type' => 'proxy'
                    ];

                    $result = DB::table('packages')->insert($insert);

                    if($result) {
                        $this->count['package-add']++;
                    }
                    else {
                        $this->sendMailInsertFailed($key);
                    }
                }
            }
        }
    }

    /**
     * @param Request $request
     * @throws Exception
     */
    public function handle(Request $request)
    {
        // @todo limit the execute time to 30sec
        $packages = DB::table('packages');

        foreach($packages->get() as $key => $package) {
            $composer = (array)$this->getComposerJson((array)$package);

            if(!empty($composer['require'])) {
                $this->addPackages((array)$composer['require'], $package->type);
            }

            if(!empty($composer['require-dev'])) {
                $this->addPackages((array)$composer['require-dev'], $package->type);
            }
        }

        if($this->error['download-url-missing'] !== 0) {
            $this->error('Erorrs: Download Url (' . $this->error['download-url-missing'] . ')');
        }
        $this->info('Packaged added: (' . $this->count['package-add'] . ')');
    }

    /**
     * @param array $package
     * @return bool|mixed
     * @throws Exception
     */
    public function getComposerJson(array $package)
    {
        $url = 'https://api.github.com/repos/' . $package['vendor'] . '/' . $package['module'] . '/contents/composer.json';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if(config('github.token') == null || config('github.username') == null) {
            $filename = Storage::disk('local')->getDriver()->getAdapter()->getPathPrefix() . 'token-warning.json';

            if(!file_exists($filename)) {
                $this->sendMail('Borat Requirements Command', 'Github Token or Username is missing.');
                file_put_contents($filename, time());
            }
            throw new Exception('Github Token or Username is missing.');
        }
        // @todo remove token!!!!
        curl_setopt($ch, CURLOPT_USERPWD, config('github.username') . ':' . config('github.token'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'flagbit rockt');
        $output = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($output);

        // @todo prevent moved permanently error

        if(empty($data->download_url)) {
            // @todo get real url from packagist api ?
            $this->sendMailDownloadUrl((array)$data, $package);
            return false;
        }

        return json_decode(file_get_contents($data->download_url));
    }

    /**
     * @param string $name
     */
    public function sendMailInsertFailed(string $name)
    {
        $this->sendMail('Package failed to insert', 'Package name: ' . $name);
    }

    /**
     * @param array $data
     * @param array $package
     */
    public function sendMailDownloadUrl(array $data, array $package)
    {
        $filename = Storage::disk('local')->getDriver()->getAdapter()->getPathPrefix() . str_replace('/', '-', $package['fullname']) . '.json';

        $this->error['download-url-missing']++;
        if(!file_exists($filename)) {
            $this->sendMail(
                'Package download url missing',
                'Package name: ' . json_encode($data) . ' ' . PHP_EOL . '' . json_encode($package)
            );
            file_put_contents($filename, time() . ' ' . $package['fullname']);
        }
    }

    /**
     * @param string $title
     * @param $message
     */
    public function sendMail(string $title, string $message)
    {
        // @todo replace mails with own table and admin page and controller to check
        mail(config('mail.from.address'), $title, $message);
    }
}
