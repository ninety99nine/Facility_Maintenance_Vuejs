<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\AdvancedFilter\Dataviewer;
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::morphMap([
    'jobcard' => 'App\Jobcard',
]);

class Lifecycle extends Model
{
    use Dataviewer;

    protected $casts = [
        'stages' => 'array',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'stages', 'default', 'type', 'company_branch_id', 'company_id',
    ];

    /**
     * Get all of the jobcards that are assigned this lifecycle.
     */
    public function jobcards()
    {
        return $this->morphedByMany('App\Jobcard', 'trackable', 'lifecycle_allocations');
    }

    public function owningCompany()
    {
        return $this->belongsTo('App\Company', 'company_id');
    }
}
