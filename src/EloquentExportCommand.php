<?php

namespace Faulker\EloquentExport;

use Carbon\Carbon;
use Illuminate\Console\Command;
use DB;

/**
 * Class EloquentExportCommand
 *
 * todo: Move code out of the command
 * todo: Test on versions of Laravel other then 5.1
 *
 * @package Faulker\EloquentExport
 */
class EloquentExportCommand extends Command
{
    protected $data;
    protected $user;
    protected $hash;
    protected $bar;
    protected $profile;
    protected $ignore;
    protected $pivot   = [];
    protected $runLast = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'export:eloquent
                            {profile : Profile to export}
                            {file : Export or import file}
                            {--id= : ID of the data you wish to export}
                            {--import : Import }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export any part of a eloquent and its relations';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $profile       = strtolower($this->argument('profile'));
        $this->profile = config('eloquent-export.profiles.'.$profile);
        $this->ignore  = config('eloquent-export.ignore');

        if ($this->option('import')) {
            // -------------------
            //       Import
            // -------------------
            $this->info('Starting import...');

            // Import the file into $this->data
            $this->importFile();

            // Build a hash array of all the model associations
            $this->buildRelationModalHash($this->profile);

            // Insert/Update the data in the database
            $this->buildRows($this->profile['model'], $this->data);

            if (! empty($this->runLast)) {
                foreach ($this->runLast as $data) {
                    $this->buildRows($data['model'], $data['row']);
                }
            }

            // Handle pivots
            if (! empty($this->pivot)) {
                $this->buildPivots();
            }

            $this->info('Finished importing data!');
        } else {
            // -------------------
            //       Export
            // -------------------
            if (empty($this->option('id'))) {
                $this->warn('--id not set please set it');

                return false;
            }
            // Export
            $this->info('Starting export...');

            $with       = array_keys($this->profile['relations']);
            $modelClass = $this->profile['model'];
            $model      = new $modelClass;

            // Get the primary column
            $primaryKey = $model->getKeyName();
            $output     = $model->with($with)
                                ->where($primaryKey, $this->option('id'))
                                ->first();

            $this->exportFile($output);

            $this->info("Export finished!");
            $this->warn($this->argument('file'));
        }

        return true;
    }

    /**
     * Build the rows and insert them into the database or update them in the database
     *
     * @param      $model
     * @param      $data
     * @param null $parentModel
     * @param null $parentId
     *
     * @return bool
     */
    private function buildRows($model, $data, $parentModel = null, $parentId = null)
    {
        $row   = [];
        $model = new $model;

        if (! empty($data)) {
            if (isset($data['pivot'])) {
                $relation = $this->hash['byClass'][get_class($model)];

                $this->pivot[] = [
                    'relation'     => $relation,
                    'parent_id'    => $parentId,
                    'parent_model' => $parentModel,
                    'pivot_data'   => $data['pivot']
                ];
            }

            foreach ($data as $key => $value) {
                // Is the key part of the ignore list
                if (! $this->ignore($key)) {
                    $primaryKey = $model->getKeyName();

                    // Is it a relation and if so then traverse into the relation
                    if ($this->notRelation($model, $key)) {
                        // Check if the primary key exists in the data if not then it is assume
                        // multiple rows are being inserted into the database and handle accordingly
                        if (isset($data[$primaryKey])) {
                            $row[$key] = $value;
                        } else {
                            // Multiple entries
                            $this->buildRows($model, $value, $parentModel, $parentId);
                        }
                    } else {
                        if (empty($parentModel) || strcasecmp(get_class($model), get_class($parentModel)) !== 0) {
                            $parentModel = $model;
                            $parentId    = $data[$primaryKey];
                        }

                        if (! empty($value)) {
                            $this->buildRows($this->hash['byRelation'][$key], $value, $parentModel, $parentId);
                        }
                    }
                }
            }

            return $this->saveRow($model, $row);
        }
    }

    /**
     * Handle pivots
     */
    private function buildPivots()
    {
        foreach ($this->pivot as $pivot) {
            $pivotData      = $pivot['pivot_data'];
            $pivotParentKey = array_search($pivot['parent_id'], $pivotData);
            $relation       = $pivot['relation'];
            unset($pivotData[$pivotParentKey]);

            $parentRelation = $pivot['parent_model']->find($pivot['parent_id']);

            $parentRelation->$relation()
                           ->sync($pivotData, false);
        }
    }

    /**
     * Save the created row
     *
     * @param $model
     * @param $row
     *
     * @return bool
     */
    private function saveRow($model, $row)
    {
        if (empty($row)) {
            return false;
        }

        $existing    = $this->checkIfExists($model, $row);
        $primaryKey  = $model->getKeyName();
        $dateColumns = $model->getDates();
        $table       = $model->getTable();
        $castColumns = method_exists($model, 'getCasts') ? $model->getCasts() : [];

        // Handle casts columns such as dates and JSON
        if (isset($row[$primaryKey])) {
            foreach ($row as $key => $value) {
                if (in_array($key, $dateColumns)) {
                    $value = is_array($value) ? $value['date'] : $value;
                    if (! empty($value)) {
                        $row[$key] = Carbon::parse($value);
                    }
                } elseif (isset($castColumns[$key])) {
                    switch ($castColumns[$key]) {
                        case 'array':
                        case 'json':
                            $row[$key] = json_encode($value);
                            break;
                    }
                } elseif (! $this->notRelation($model, $key)) {
                    $this->buildRows($model, $value);
                }

                $method = 'set'.ucfirst($key).'Attribute';
                if (method_exists($model, $method)) {
                    $model->$method($value);
                    $row[$key] = $model->getAttributes()[$key];
                }
            }

            try {
                if ($existing === false) {
                    DB::table($table)
                      ->insert($row);
                } else {
                    DB::table($table)
                      ->where($primaryKey, $row[$primaryKey])
                      ->update($row);
                }
            }
            catch (\Exception $e) {
                switch ($e->getCode()) {
                    case '23503':
                        $this->runLast[] = [
                            'model' => $model,
                            'row'   => $row
                        ];
                        break;
                    default:
                        die($e);
                }
            }
        }

        return true;
    }

    /**
     * Check to see if key is a relation of the model or a column
     *
     * @param $model
     * @param $key
     *
     * @return bool
     */
    private function notRelation($model, $key)
    {
        return ($model->isFillable($key) || ! isset($this->hash['byRelation'][$key]));
    }

    /**
     * Check to see if key is in the ignore list
     *
     * @param $key
     *
     * @return bool
     */
    private function ignore($key)
    {
        return in_array($key, $this->ignore, true);
    }

    /**
     * Check to see if the data already exists in the database.
     * Used to determine if the data should be inserted or updated.
     *
     * @param $model
     * @param $row
     *
     * @return bool
     */
    private function checkIfExists($model, $row)
    {
        $primaryKey = $model->getKeyName();
        $table      = $model->getTable();

        // Query the database using the model to see if the data already exists
        return DB::table($table)
                 ->where($primaryKey, $row[$primaryKey])
                 ->count() > 0;
    }

    /**
     * Build an array of all the relations for reference
     *
     * @param $profile
     */
    private function buildRelationModalHash($profile)
    {
        $relations = $profile['relations'];

        foreach ($relations as $relation => $relationModel) {
            $model         = new $profile['model'];
            $relationArray = explode('.', $relation);

            foreach ($relationArray as $key => $subrelation) {
                // Get the relation class
                $model = $model->$subrelation()
                               ->getRelated();

                $snakeSubrelation = snake_case($subrelation);

                // Add to hash table
                $this->hash['byRelation'][$snakeSubrelation] = $model;
                $this->hash['byClass'][get_class($model)]    = $subrelation;
            }
        }
    }

    /**
     * Export the file
     *
     * @param $data
     */
    private function exportFile($data)
    {
        $file = $this->argument('file');

        // Create directory structure if it doesn't currently exist
        if (strpos($file, '/') !== false) {
            $pathArray = explode('/', $file);
            $dirArray  = $this->arrayRemoveEnd($pathArray);
            $dir       = implode('/', $dirArray);
            if (! file_exists($dir)) {
                mkdir($dir);
            }
        } elseif (strpos($file, '\\') !== false) {
            $pathArray = explode('\\', $file);
            $dirArray  = $this->arrayRemoveEnd($pathArray);
            $dir       = implode('\\', $dirArray);
            if (! file_exists($dir)) {
                mkdir($dir);
            }
        }

        // Create file and save data to it
        file_put_contents($file, $data->toJson());
    }

    /**
     * Import the file and decode its JSON
     */
    private function importFile()
    {
        $file = $this->argument('file');

        if (file_exists($file)) {
            $data       = file_get_contents($file);
            $this->data = json_decode($data, true);
        } else {
            die('File doesn\'t exist');
        }
    }

    /**
     * Helper function that will remove the last entry in a array
     *
     * @param $array
     *
     * @return mixed
     */
    private function arrayRemoveEnd($array)
    {
        end($array);
        $endKey = key($array);
        unset($array[$endKey]);
        reset($array);

        return $array;
    }
}
