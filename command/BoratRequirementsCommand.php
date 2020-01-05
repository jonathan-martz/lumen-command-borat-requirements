<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use function json_decode;

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
                        $this->errorPackageInsertFailed($key);
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

            if(!empty($composer['rls -laequire'])) {
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
            throw new Exception('Github Token or Username is missing.');
        }

        curl_setopt($ch, CURLOPT_USERPWD, config('github.username') . ':' . config('github.token'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'flagbit rockt');
        $output = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($output);

        // @todo prevent moved permanently error

        if(empty($data->download_url)) {
            $this->errorDownloadUrl($package);
            return false;
        }

        return json_decode(file_get_contents($data->download_url));
    }

    /**
     * @param string $name
     */
    public function errorPackageInsertFailed(string $name)
    {
        $this->error['package-insert-failed']++;
        $check = DB::table('borat_error')->where('package', '=', $name)->where('reason', '=', 'package-insert-failed');
        if($check !== 0) {
            $insert = [
                'package' => $name,
                'reason' => 'package-insert-failed'
            ];
            DB::table('borat_error')->insert($insert);
        }
    }

    /**
     * @param array $package
     */
    public function errorDownloadUrl(array $package)
    {
        $this->error['download-url-missing']++;
        $check = DB::table('borat_error')->where('package', '=', $package['fullname'])->where('reason', '=', 'download-url-missing');
        if($check !== 0) {
            $insert = [
                'package' => $package['fullname'],
                'reason' => 'download-url-missing'
            ];
            DB::table('borat_error')->insert($insert);
        }
    }
}
