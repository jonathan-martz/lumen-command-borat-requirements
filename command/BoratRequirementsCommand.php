<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;

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
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle(Request $request)
    {
        $this->info('Job started');
    }
}
