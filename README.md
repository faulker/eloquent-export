# Laravel Eloquent Export Tool
Give the ability to build profiles/configurations to be used by a artisan command to export data from the database using Eloquent models.

---

**This has only been tested on Laravel 5.1 but it should work on higher versions**

---

### Use case:
* Export a user and all there data from a production database and import it into a local database for bug testing/repairing.
* Backup subset of data so that you can easily restore it after testing new features or changes to the data stored in the database.

## Composer Install

Add the fallowing to your `composer.json`
```
{
    "require": {
        "faulker/eloquent-export": "dev-master"
    }
}
```

Add the service provider to `config/app.php`

```
Faulker\EloquentExport\EloquentExportServiceProvider::class,
```

Publish the default config file `config/eloquent-export.php`

```
php artisan vender:publish
```


### 5.1 Notes

Laravel 5.1 dosen't support the ability of getting a list of a Model's casted columns so you will have to add the `Faulker\EloquentExport\EloquentExportTrait` trait to any Model that has `JSON` or `array` casted columns.

```
use Faulker\EloquentExport\EloquentExportTrait;

class MyModel extends Models
{
    use EloquentExportTrait;
    ...
}
```

## Profile Creation

### Profile Structure

```
'profile_name' => [
    'model'     => \Name\Space\Root\Model::class,
    'relations' => [
        '[relation]'                  => \Name\Space\Relation\Model::class,
        '[relation].[child_relation]' => \Name\Space\ChildRelation\Model::class,
    ],
],
```

**Example**
```
'user_posts' => [
    'model'     => \Name\Space\EloquentUser::class, // User model (root model)
    'relations' => [
        'posts'         => \Name\Space\Posts::class, // Posts model
        'posts.comment' => \Name\Space\Comments::class, // Comments model
    ],
],
```

The above profile will export a user, all their posts, and all comments for each post.

## Usage

### Base arguments

```
php artisan export:eloquent [profile] [path_to_file] [--id=] [--import]
```

* [profile] - Name of a profile you have created in the `config/eloquent-export.php` file.
* [path_to_file] - Export/Import file. 
* [--id=] - Primary ID ($primaryKey) of the root model data you want to export. Exp. if you are exporting a user then it would be the user's ID.
* [--import] - Import the data, if not set then data will be exported from the database.

### Example Usage

Export using the `user_posts` profile:

```
php artisan export:eloquent user_posts /tmp/export/user_posts.json --id=34342
```

* The output file is in JSON format

---

Import using the `user_posts` profile:

```
php artisan export:eloquent user_posts /tmp/export/user_posts.json --import
```

