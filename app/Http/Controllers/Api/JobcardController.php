<?php

namespace App\Http\Controllers\Api;

use DB;
use Auth;
use App\Jobcard;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class JobcardController extends Controller
{
    public function index(Request $request)
    {
        $user = auth('api')->user();

        //  We start with no jobcards
        $jobcards = [];

        //  Query data
        $type = request('model', 'branch');      //  e.g) company, branch, client or contractor
        $model_id = request('modelId');          //  The id of the client/contractor for getting related jobcards

        /*  First thing is first, we need to understand one of 9 scenerios, Either we want:
         *
         *  1) Only jobcards for a related COMPANY of the authenticated user (NO STEPS)
         *  2) Only jobcards for a related BRANCH of the authenticated user (NO STEPS)
         *  3) Only jobcards for a related CLIENT of the authenticated user (NO STEPS)
         *  4) Only jobcards for a related CONTRACTOR of the authenticated user (NO STEPS)
         *  5) Only jobcards in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given COMPANY of the authenticated user
         *  6) Only jobcards in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given BRANCH of the authenticated user
         *  7) Only jobcards in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given CLIENT of the authenticated user
         *  8) Only jobcards in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given CONTRACTOR of the authenticated user
         *  9) All jobcards in the system e.g) If SuperAdmin needs access to all data
         *
         *  Once we have those jobcards we will determine whether we want any of the following
         *
         *  1) All jobcards aswell as the trashed ones
         *  2) Only jobcards that are trashed
         *  3) Only jobcards that are not trashed
         *
         *  After this we will perform our filters, e.g) where, orderby, e.t.c
         *
         */

        /*  User Company specific jobcards
         *  If the user indicated that they want jobcards related to their company,
         *  then get the jobcards related to the authenticated users company.
         *  They must indicate using the query "model" set to "company".
         */
        if ($type == 'company') {
            /**************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW COMPANY JOBCARDS    *
            /**************************************************************/

            $jobcards = $user->companyBranch->company->jobcards();

        /*  User Branch specific jobcards
         *  If the user indicated that they want jobcards related to their branch,
         *  then get the jobcards related to the authenticated users branch.
         *  They must indicate using the query "model" set to "branch".
         */
        } elseif ($type == 'branch') {
            /**************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW BRANCH JOBCARDS    *
            /**************************************************************/

            $jobcards = $user->companyBranch->jobcards();

        /*  Client specific jobcards
         *  If the user indicated that they want jobcards related to a specific client,
         *  then get the jobcards related to that client. They must indicate using the
         *  query "model" set to "client" and "model_id" to the company unique id.
         */
        } elseif ($type == 'client') {
            /**************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW CLIENT JOBCARDS    *
            /**************************************************************/

            //  Check if the user specified the model_id
            if (empty($model_id)) {
                //  No model_id specified error
                return oq_api_notify_error('include client id', null, 404);
            }

            $jobcards = Jobcard::where('client_id', $model_id);

        /*  Contractor specific jobcards
         *  If the user indicated that they want jobcards related to a specific contractor,
         *  then get the jobcards related to that contractor. They must indicate using the
         *  query "model" set to "contractor" and "model_id" to the company unique id.
         */
        } elseif ($type == 'contractor') {
            /***************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW CONTRACTOR JOBCARDS *
            /***************************************************************/

            //  Check if the user specified the model_id
            if (empty($model_id)) {
                //  No model_id specified error
                return oq_api_notify_error('include contractor id', null, 404);
            }

            $jobcards = Jobcard::whereHas('contractorsList', function ($query) use ($model_id) {
                $query->where('contractor_id', $model_id);
            });

        /*  ALL JOBCARDS
         *  If the user wants all the jobcards in the system, they must indicate
         *  using the query "model" set to "all". This is normaly used by authorized
         *  superadmins to access all jobcard resources in the system.
         */
        } elseif ($type == 'all') {
            /***********************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW ALL JOBCARDS    *
            /**********************************************************/

            /*  ALL JOBCARDS
            *  If the user wants all the jobcards in the system, they must indicate
            *  using the query "all" set to "1". This is normaly used by authorized
            *  superadmins to access all jobcard resources in the system.
            */

            /*   Create a new jobcard instance that can be used to retrieve all jobcards
             */
            $jobcards = new Jobcard();
        }

        /*  Now lets see if the user indicated if they want the jobcards according to their
         *  step allocations. We will check if we have a step indicated in the query.
         *  If so, then lets get the users company template specifically used for defining
         *  the process steps for jobcards. Once we have the template, we need to query
         *  jobcards in the specified step.
         *
         */

        if (!empty(request('step'))) {
            try {
                /*  This is how we get the jobcard step allocation template
                 *  It must be of "type" equal to "jobcard", and "selected" equal to "1"
                 */
                $jobcardTemplateId = $user->companyBranch->company->formTemplate
                                          ->where('type', 'jobcard')
                                          ->where('selected', 1)
                                          ->first()
                                          ->id;

                //  Check if we have jobcards
                if (count($jobcards)) {
                    //  We create a new jobcard instance
                    $jobcards = new Jobcard();
                }

                /*  Filter only to the jobcards beloging to the specified step.
                 */
                $jobcards = $jobcards->whereHas('statusLifecycle', function ($query) use ($jobcardTemplateId) {
                    $query->where('step', request('step'))
                          ->where('form_template_id', $jobcardTemplateId);
                });
            } catch (\Exception $e) {
                return oq_api_notify_error('Query Error', $e->getMessage(), 404);
            }
        }

        /*  To avoid sql order_by error for ambigious fields e.g) created_at
         *  we must specify the order_join.
         *
         *  Order joins help us when using the "advancedFilter()" method. Usually
         *  we need to specify the joining table so that the system is not confused
         *  by similar column names that exist on joining tables. E.g) the column
         *  "created_at" can exist in multiple table and the system might not know
         *  whether the "order_by" is for table_1 created_at or table 2 created_at.
         *  By specifying this we end up with "table_1.created_at"
         *
         *  If we don't have any special order_joins, lets default it to nothing
         */

        $order_join = 'jobcards';

        try {
            //  Get all and trashed
            if (request('withtrashed') == 1) {
                //  Run query
                $jobcards = $jobcards->withTrashed()->advancedFilter(['order_join' => $order_join]);
            //  Get only trashed
            } elseif (request('onlytrashed') == 1) {
                //  Run query
                $jobcards = $jobcards->onlyTrashed()->advancedFilter(['order_join' => $order_join]);
            //  Get all except trashed
            } else {
                //  Run query
                $jobcards = $jobcards->advancedFilter(['order_join' => $order_join]);
            }

            //  If we have any jobcards so far
            if (count($jobcards)) {
                //  Eager load other relationships wanted if specified
                if (request('connections')) {
                    $jobcards->load(oq_url_to_array(request('connections')));
                }
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  Action was executed successfully
        return oq_api_notify($jobcards, 200);
    }

    public function getLifecycleStages(Request $request)
    {
        $user = auth('api')->user();

        //  We start with no allocations
        $allocations = [];

        /*  We need to check if the user is authorized to retrieve jobcard allocations
        */

        //  if () {
        /***********************************************************
        *  CHECK IF THE USER IS AUTHORIZED TO GET ALLOCATIONS      *
        /**********************************************************/
        //  }

        /*  COMPANY JOBCARD ALLOCATIONS
         *  Get the company form template currently in use
         */
        $formTemplate = $user->companyBranch->company->formTemplate->where('selected', 1)->first();

        try {
            // Run query
            $allocations = $formTemplate->formAllocations()
                ->select(DB::raw('COUNT(step) count, step'))
                ->groupBy('step')
                ->get();

            $allocations = [
                'template' => $formTemplate->form_template,
                'allocations' => $allocations,
            ];
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  Action was executed successfully
        return oq_api_notify($allocations, 200);
    }

    public function getLifecycle(Request $request, $jobcard_id)
    {
        $user = auth('api')->user();

        //  Check if the jobcard exists
        $jobcard = Jobcard::find($jobcard_id);

        if (!count($jobcard)) {
            //  API Response
            if (oq_viaAPI($request)) {
                //  No resource found
                oq_api_notify_no_resource();
            }
        }

        /*  We need to check if the user is authorized to view the jobcard lifecycle
         */

        //  if () {
        /***********************************************************
        *  CHECK IF THE USER IS AUTHORIZED TO VIEW LIFECYCLE       *
        /**********************************************************/
        //  }

        try {
            //  Run query
            $lifecycle = $jobcard->statusLifecycle->first();

            //  If we have any lifecycle so far
            if (count($lifecycle)) {
                //  Eager load other relationships wanted if specified
                if (request('connections')) {
                    $lifecycle->load(oq_url_to_array(request('connections')));
                }
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  Action was executed successfully
        return oq_api_notify($lifecycle, 200);

        if (count($lifecycle)) {
            //  return lifecycle
            return oq_api_notify($lifecycle, 200);
        } else {
            //  No resource found
            oq_api_notify_no_resource();
        }
    }

    public function updateLifecycle(Request $request, $jobcard_id)
    {
        $user = auth('api')->user();

        //  Check if the jobcard exists
        $jobcard = Jobcard::find($jobcard_id);

        //  Check if the updated lifecycle template was provided
        if (empty(request('template'))) {
            //  API Response
            if (oq_viaAPI($request)) {
                return oq_api_notify([
                    'message' => 'Include the updated lifecycle',
                ], 422);
            }
        }

        //  Check if the next step was provided
        if (empty(request('nextStep'))) {
            //  API Response
            if (oq_viaAPI($request)) {
                return oq_api_notify([
                    'message' => 'Include the next lifecycle step',
                ], 422);
            }
        }

        if (!count($jobcard)) {
            //  API Response
            if (oq_viaAPI($request)) {
                //  No resource found
                oq_api_notify_no_resource();
            }
        }

        /*  We need to check if the user is authorized to update the jobcard lifecycle
         */

        //  if () {
        /***********************************************************
        *  CHECK IF THE USER IS AUTHORIZED TO UPDATE LIFECYCLE     *
        /**********************************************************/
        //  }

        try {
            //  Run query
            $lifecycle = $jobcard->statusLifecycle()->update([
                'template' => json_encode(request('template')),
                'step' => request('nextStep'),
            ]);

            //  If the lifecycle was updated successfully
            if ($lifecycle) {
                $updatedLifecycle = $jobcard->statusLifecycle;

                //  Action was executed successfully
                return oq_api_notify($updatedLifecycle, 200);
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  Action was executed successfully
        return oq_api_notify($lifecycle, 200);

        if (count($lifecycle)) {
            //  return lifecycle
            return oq_api_notify($lifecycle, 200);
        } else {
            //  No resource found
            oq_api_notify_no_resource();
        }
    }

    public function show($jobcard_id)
    {
        try {
            //  Get all and trashed
            if (request('withtrashed') == 1) {
                //  Run query
                $jobcard = Jobcard::withTrashed()->where('id', $jobcard_id)->first();
            //  Get only trashed
            } elseif (request('onlytrashed') == 1) {
                //  Run query
                $jobcard = Jobcard::onlyTrashed()->where('id', $jobcard_id)->first();
            //  Get all except trashed
            } else {
                //  Run query
                $jobcard = Jobcard::where('id', $jobcard_id)->first();
            }

            //  If we have any jobcard so far
            if (count($jobcard)) {
                //  Eager load other relationships wanted if specified
                if (request('connections')) {
                    return $jobcard->load(oq_url_to_array(request('connections')));
                }
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  No resource found
        return oq_api_notify_no_resource();
    }

    public function contractors($jobcard_id)
    {
        $user = auth('api')->user();

        //  We start with no contractors
        $contractors = [];

        //  Check if the jobcard exists
        $jobcard = Jobcard::find($jobcard_id);

        if (!count($jobcard)) {
            //  API Response
            if (oq_viaAPI($request)) {
                //  No resource found
                oq_api_notify_no_resource();
            }
        }

        /*  We need to check if the user is authorized to view the jobcard contractors
         */

        //  if () {
        /***********************************************************
        *  CHECK IF THE USER IS AUTHORIZED TO VIEW CONTRACTORS     *
        /**********************************************************/
        //  }

        //  If we don't have any special order_joins, lets default it to nothing
        $order_join = 'jobcard_contractors';

        try {
            //  Run query, dont paginate to return relationship instance
            $contractors = $jobcard->contractorsList()->advancedFilter(['order_join' => $order_join]);

            //  If we have any jobcards so far
            if (count($contractors)) {
                //  Eager load other relationships wanted if specified
                if (request('connections')) {
                    $contractors->load(oq_url_to_array(request('connections')));
                }
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  Action was executed successfully
        return oq_api_notify($contractors, 200);
    }

    public function store(Request $request)
    {
        //  Current authenticated user
        $user = auth('api')->user();

        /*  Validate and Create the new jobcard and associated branch and upload related documents
         *  [e.g logo, jobcard profile, other documents]. Update recent activities
         *
         *  @param $request - The request parameters used to create a new jobcard
         *  @param $user - The user creating the jobcard
         *
         *  @return Validator - If validation failed
         *  @return jobcard - If successful
         */
        $response = oq_createOrUpdateJobcard($request, null, $user);

        //  If the validation did not pass
        if (oq_failed_validation($response)) {
            //  Return validation errors with an alert or json response if API request
            return oq_failed_validation_return($request, $response);
        }

        //  return created jobcard
        return oq_api_notify($response, 201);
    }

    public function update(Request $request, $jobcard_id)
    {
        //  Get the jobcard, even if trashed
        $jobcard = Jobcard::withTrashed()->where('id', $jobcard_id)->first();

        //  Check if we don't have a resource
        if (!count($jobcard)) {
            //  No resource found
            return oq_api_notify_no_resource();
        }

        /*  Validate and Update the jobcard and upload related documents
         *  [e.g jobcard images or files]. Update recent activities
         *
         *  @param $request - The request with all the parameters to update
         *  @param $jobcard - The jobcard we are updating
         *  @param $user - The user updating the jobcard
         *
         *  @return Validator - If validation failed
         *  @return jobcard - If successful
         */
        //  Current authenticated user
        $user = auth('api')->user();

        $response = oq_createOrUpdateJobcard($request, $jobcard, $user);

        //  If the validation did not pass
        if (oq_failed_validation($response)) {
            //  Return validation errors with an alert or json response if API request
            return oq_failed_validation_return($request, $response);
        }

        //  return updated jobcard
        return oq_api_notify($response, 200);
    }

    public function removeClient($jobcard_id)
    {
        try {
            //  Run query
            $jobcard = Jobcard::find($jobcard_id);
            $jobcard->client_id = null;
            $jobcard->save();
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        if ($jobcard) {
            //  Action was executed successfully
            return response()->json(null, 204);
        }

        //  No resource found
        return oq_api_notify_no_resource();
    }

    public function removeContractor($jobcard_id, $contractor_id)
    {
        try {
            //  Run query
            $jobcard = Jobcard::find($jobcard_id);
            $jobcard->contractorsList()->detach($contractor_id);
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        if ($jobcard) {
            //  Action was executed successfully
            return response()->json(null, 204);
        }

        //  No resource found
        return oq_api_notify_no_resource();
    }

    public function delete($jobcard_id)
    {
        //  Get the jobcard, even if trashed
        $jobcard = Jobcard::withTrashed()->find($jobcard_id);

        //  Check if we have a resource
        if (!count($jobcard)) {
            //  No resource found
            return oq_api_notify_no_resource();
        }

        //  If the param "permanent" is set to true
        if (request('permanent') == 1) {
            //  Permanently delete jobcard
            $jobcard->forceDelete();
        } else {
            //  Soft delete (trash) the jobcard
            $jobcard->delete();
        }

        return response()->json(null, 204);
    }
}