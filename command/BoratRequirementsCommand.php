<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * @var int
     */
    private $start = 0;

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
     * @throws Exception
     */
    public function addPackages(array $packages, string $type)
    {
        foreach($packages as $key => $item) {
            if(!$this->isPhp($key) && !$this->isHhvm($key) && !$this->isExt($key) && !$this->isLib($key) && !$this->isBlacklisted($key)) {
                $package = DB::table('packages')->where('fullname', '=', $key);

                if($package->count() === 0) {
                    $tmp = explode('/', $key);

                    $insert = [
                        'repo' => 'git@github.com:' . $tmp[0] . '/' . $tmp[1] . '.git',
                        'fullname' => $key,
                        'vendor' => $tmp[0],
                        'module' => $tmp[1],
                        'type' => 'proxy'
                    ];

                    $result = DB::table('packages')->insert($insert);

                    if(!$result) {
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
        $this->start = time();
        $packages = DB::table('packages');

        foreach($packages->get() as $key => $package) {
            try {
                $composer = (array)$this->getComposerJson((array)$package);

                if(time() - $this->start > 50) {
                    throw new Exception('Timeout limit reached (50)');
                }

                if(!empty($composer['require'])) {
                    $this->addPackages((array)$composer['require'], $package->type);
                }

                if(!empty($composer['require-dev'])) {
                    $this->addPackages((array)$composer['require-dev'], $package->type);
                }
            }
            catch(Exception $e) {
                $this->error($e->getMessage());
                return;
            }
        }
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

        if(!empty($data->message)) {
            if($data->message == 'Not Found') {
                $this->errorNotFound($package);
                return false;
            }

            if($data->message == 'Moved Permanently') {
                $this->errorMovedPermanently($package);
                return false;
            }

            if(strpos($data->message, 'API rate limit exceeded') === 0) {
                $this->errorRateLimit($package);
                throw new Exception('Rate Limit reached.');
            }
        }

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
        $check = DB::table('borat_error')->where('package', '=', $name)->where('reason', '=', 'package-insert-failed');
        if($check->count() === 0) {
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
        $check = DB::table('borat_error')->where('package', '=', $package['fullname'])->where('reason', '=', 'download-url-missing');
        if($check === 0) {
            $insert = [
                'package' => $package['fullname'],
                'reason' => 'download-url-missing'
            ];
            DB::table('borat_error')->insert($insert);
        }
    }

    /**
     * @param $name
     * @return bool
     */
    public function tryFallbackPackagist($name)
    {
        $url = 'https://packagist.org/search.json?q=' . $name;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);

        $data = (array)json_decode($output);

        if(count($data['results']) > 0) {
            foreach($data['results'] as $key => $package) {
                if($package->name === $name) {
                    $fullname = str_replace('https://github.com/', '', $package->repository);
                    $tmp = explode('/', $fullname);

                    $insert = [
                        'repo' => 'git@github.com:' . $tmp[0] . '/' . $tmp[1] . '.git',
                        'fullname' => $fullname,
                        'vendor' => $tmp[0],
                        'module' => $tmp[1],
                        'type' => 'proxy'
                    ];
                    DB::table('packages')->insert($insert);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array $package
     */
    public function errorNotFound(array $package)
    {
        $check = DB::table('borat_error')->where('package', '=', $package['fullname'])
            ->where('reason', '=', 'not-found');
        if($check->count() === 0) {

            $fallback = $this->tryFallbackPackagist($package['fullname']);

            if(!$fallback) {
                $insert = [
                    'package' => $package['fullname'],
                    'reason' => 'not-found'
                ];
                DB::table('borat_error')->insert($insert);
            }
        }
    }

    /**
     * @param array $package
     */
    public function errorMovedPermanently(array $package)
    {
        $check = DB::table('borat_error')->where('package', '=', $package['fullname'])
            ->where('reason', '=', 'moved-permanently');
        if($check->count() === 0) {
            $insert = [
                'package' => $package['fullname'],
                'reason' => 'moved-permanently'
            ];
            DB::table('borat_error')->insert($insert);
        }
    }

    /**
     * @param array $package
     */
    public function errorRateLimit(array $package)
    {
        $check = DB::table('borat_error')->where('package', '=', $package['fullname'])
            ->where('reason', '=', 'rate-limit');
        if($check->count() === 0) {
            $insert = [
                'package' => $package['fullname'],
                'reason' => 'rate-limit'
            ];
            DB::table('borat_error')->insert($insert);
        }
    }
}
