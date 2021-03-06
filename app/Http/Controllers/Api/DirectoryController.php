<?php

namespace App\Http\Controllers\Api;

use App\Directory;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DirectoryController extends Controller
{
    public function index(Request $request)
    {
        $user = auth('api')->user();

        //  We start with no jobcards
        $companies = [];

        //  Query data
        $association = request('association', 'company');     //  e.g) company, branch
        $type = request('type');                              //  e.g) client or supplier
        $kind = request('kind', 'user');                      //  e.g) company or user

        /*  First thing is first, we need to understand one of 3 scenerios, Either we want:
         *
         *  1) Only company directory for a related COMPANY of the authenticated user
         *  2) Only company directory for a related BRANCH of the authenticated user
         *  3) Only client directory for a related COMPANY of the authenticated user
         *  4) Only client directory for a related BRANCH of the authenticated user
         *  5) Only supplier directory for a related COMPANY of the authenticated user
         *  6) Only supplier directory for a related BRANCH of the authenticated user
         *
         *  Once we have those companies we will determine whether we want any of the following
         *
         *  1) All companies aswell as the trashed ones
         *  2) Only companies that are trashed
         *  3) Only companies that are not trashed
         *
         *  After this we will perform our filters, e.g) where, orderby, e.t.c
         *
         */

        /*  User Company specific directory
         *  If the user indicated that they want company directory listings in
         *  relation to their company. They must indicate using the query
         *  "model" set to "company".
         */
        if ($association == 'company') {
            /******************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW COMPANY DIRECTORIES    *
            /*****************************************************************/

            $model = $user->companyBranch->company()->first();

        /*  User Branch specific directory
         *  If the user indicated that they want company directory listings in
         *  relation to their branch. They must indicate using the query
         *  "model" set to "branch".
         */
        } elseif ($association == 'branch') {
            /******************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW BRANCH DIRECTORIES     *
            /*****************************************************************/

            $model = $user->companyBranch()->first();

        /*  For ALL directories
         *  If the user indicated that they all drirectories. They must indicate using the
         *  query "model" set to "all".
         */
        } elseif ($association == 'all') {
            /******************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW ALL DIRECTORIES        *
            /*****************************************************************/

            $companies = new Company();
        }

        if ($association != 'all') {
            if ($kind == 'company') {
                /*  If user indicated to only return client dierctories
                */
                if ($type == 'client') {
                    $companies = $model->companyClients();

                /*  If user indicated to only return supplier dierctories
                */
                } elseif ($type == 'supplier') {
                    $companies = $model->companySuppliers();

                /*  If user did not indicate any specific group
                */
                } else {
                    $companies = $model->companyDirectory();
                }
            } elseif ($kind == 'user') {
                /*  If user indicated to only return client dierctories
                */
                if ($type == 'client') {
                    $companies = $model->userClients();

                /*  If user indicated to only return supplier dierctories
                */
                } elseif ($type == 'supplier') {
                    $companies = $model->userSuppliers();

                /*  If user did not indicate any specific group
                */
                } else {
                    $companies = $model->userDirectory();
                }
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

        if ($kind == 'company') {
            $order_join = 'companies';
        } elseif ($kind == 'user') {
            $order_join = 'users';
        }

        try {
            //  Get all and trashed
            if (request('withtrashed') == 1) {
                //  Run query
                $companies = $companies->withTrashed()->advancedFilter(['order_join' => $order_join]);
            //  Get only trashed
            } elseif (request('onlytrashed') == 1) {
                //  Run query
                $companies = $companies->onlyTrashed()->advancedFilter(['order_join' => $order_join]);
            //  Get all except trashed
            } else {
                //  Run query
                $companies = $companies->advancedFilter(['order_join' => $order_join]);
            }

            //  If we have any companies so far
            if ($companies) {
                //  Eager load other relationships wanted if specified
                if (request('connections')) {
                    $companies->load(oq_url_to_array(request('connections')));
                }
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  Action was executed successfully
        return oq_api_notify($companies, 200);
    }
}
