<?php namespace Faulker\EloquentExport;

use Illuminate\Support\ServiceProvider;

/**
 * User: winterfaulk
 * Date: 1/27/17
 * Time: 4:48 PM
 */
class EloquentExportServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * The console commands.
     */
    protected $commands = [
        'Faulker\EloquentExport\EloquentExportCommand'
    ];

    public function boot()
    {
        $this->publishes([
            __DIR__.'/eloquent-export.php' => config_path('eloquent-export.php'),
        ], 'config');
    }

    public function register()
    {
        $this->commands($this->commands);
    }

    public function providers()
    {
        return ['EloquentExport'];
    }
}