<?php

namespace Sunnysideup\UserformsCsvImport\Tasks;

use SilverStripe\Dev\BuildTask;

class UserFormCSVImportTask extends BuildTask
{

    protected $title = 'UserForm CSV Import Task';

    protected $description = 'Import UserForm submissions from a CSV file';

    private static $segment = 'UserFormCSVImportTask';

    public function run($request) {}
}
