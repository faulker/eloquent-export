<?php

namespace Faulker\EloquentExport;

trait EloquentExportTrait
{
    public function getCasts()
    {
        if(isset($this->casts)) {
            return $this->casts;
        }

        return [];
    }
}