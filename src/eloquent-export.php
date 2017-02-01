<?php
/**
 * Created by PhpStorm.
 * User: winterfaulk
 * Date: 1/27/17
 * Time: 4:13 PM
 */

return [
    'profiles' => [
        'profile_name' => [
            'model'     => \Name\Space\To\Profile\Starting\Model::class,
            'relations' => [
                'relation.subrelation' => \Name\Space\To\Model::class,
            ],
        ],
    ],
    // Eloquent keys to ignore
    'ignore' => [
        'pivot', // In many cases removing this will cause errors.
    ],
];