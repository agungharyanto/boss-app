<?php

namespace App\Models\Scopes;

use App\Support\ResellerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ResellerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ResellerContext::class);

        if ($context->hasReseller()) {
            $builder->where($model->qualifyColumn('reseller_id'), $context->reseller()->id);
        }
    }
}
