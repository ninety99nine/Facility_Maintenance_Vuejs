<?php

namespace App\Http\Controllers\Api;

use App\Invoice;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = auth('api')->user();

        //  We start with no invoices
        $invoices = [];

        //  Query data
        $type = request('model', 'branch');      //  e.g) company, branch
        $model_id = request('modelId');          //  The id of the client/supplier for getting related invoices

        /*  First thing is first, we need to understand one of 9 scenerios, Either we want:
         *
         *  1) Only invoices for a related COMPANY of the authenticated user (NO STEPS)
         *  2) Only invoices for a related BRANCH of the authenticated user (NO STEPS)
         *  3) Only invoices for a related CLIENT of the authenticated user (NO STEPS)
         *  4) Only invoices for a related CONTRACTOR of the authenticated user (NO STEPS)
         *  5) Only invoices in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given COMPANY of the authenticated user
         *  6) Only invoices in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given BRANCH of the authenticated user
         *  7) Only invoices in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given CLIENT of the authenticated user
         *  8) Only invoices in their respective steps e.g) Open, Pending, Closed, e.t.c...
         *     for a given CONTRACTOR of the authenticated user
         *  9) All invoices in the system e.g) If SuperAdmin needs access to all data
         *
         *  Once we have those invoices we will determine whether we want any of the following
         *
         *  1) All invoices aswell as the trashed ones
         *  2) Only invoices that are trashed
         *  3) Only invoices that are not trashed
         *
         *  After this we will perform our filters, e.g) where, orderby, e.t.c
         *
         */

        /*  User Company specific invoices
         *  If the user indicated that they want invoices related to their company,
         *  then get the invoices related to the authenticated users company.
         *  They must indicate using the query "model" set to "company".
         */
        if ($type == 'company') {
            /**************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW COMPANY INVOICES    *
            /**************************************************************/

            $invoices = $user->companyBranch->company->invoices();

        /*  User Branch specific invoices
         *  If the user indicated that they want invoices related to their branch,
         *  then get the invoices related to the authenticated users branch.
         *  They must indicate using the query "model" set to "branch".
         */
        } elseif ($type == 'branch') {
            /**************************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW BRANCH INVOICES    *
            /**************************************************************/

            $invoices = $user->companyBranch->invoices();

        /*  Client specific invoices
         *  If the user indicated that they want invoices related to a specific client,
         *  then get the invoices related to that client. They must indicate using the
         *  query "model" set to "client" and "model_id" to the company unique id.
         */
        } elseif ($type == 'all') {
            /***********************************************************
            *  CHECK IF THE USER IS AUTHORIZED TO VIEW ALL INVOICES    *
            /**********************************************************/

            /*  ALL INVOICES
            *  If the user wants all the invoices in the system, they must indicate
            *  using the query "all" set to "1". This is normaly used by authorized
            *  superadmins to access all invoice resources in the system.
            */

            /*   Create a new invoice instance that can be used to retrieve all invoices
             */
            $invoices = new Invoice();
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

        $order_join = 'invoices';

        try {
            //  Get all and trashed
            if (request('withtrashed') == 1) {
                //  Run query
                $invoices = $invoices->withTrashed()->advancedFilter(['order_join' => $order_join]);
            //  Get only trashed
            } elseif (request('onlytrashed') == 1) {
                //  Run query
                $invoices = $invoices->onlyTrashed()->advancedFilter(['order_join' => $order_join]);
            //  Get all except trashed
            } else {
                //  Run query
                $invoices = $invoices->advancedFilter(['order_join' => $order_join]);
            }

            //  If we have any invoices so far
            if (count($invoices)) {
                //  Eager load other relationships wanted if specified
                if (request('connections')) {
                    $invoices->load(oq_url_to_array(request('connections')));
                }
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  Action was executed successfully
        return oq_api_notify($invoices, 200);
    }

    public function show($invoice_id)
    {
        try {
            //  Get all and trashed
            if (request('withtrashed') == 1) {
                //  Run query
                $invoice = Invoice::withTrashed()->where('id', $invoice_id)->first();
            //  Get only trashed
            } elseif (request('onlytrashed') == 1) {
                //  Run query
                $invoice = Invoice::onlyTrashed()->where('id', $invoice_id)->first();
            //  Get all except trashed
            } else {
                //  Run query
                $invoice = Invoice::where('id', $invoice_id)->first();
            }

            //  If we have any invoice so far
            if (count($invoice)) {
                //  Eager load other relationships wanted if specified
                if (request('connections')) {
                    $invoice->load(oq_url_to_array(request('connections')));
                }

                return $invoice;
            }
        } catch (\Exception $e) {
            return oq_api_notify_error('Query Error', $e->getMessage(), 404);
        }

        //  No resource found
        return oq_api_notify_no_resource();
    }

    public function update(Request $request, $invoice_id)
    {
        //  Current authenticated user
        $user = auth('api')->user();

        //  Query data
        $model_Type = request('model');                      //  Associated model e.g) invoice
        $modelId = request('modelId');                      //  The id of the associated model
        $invoice = request('invoice');

        /*******************************************************
         *   CHECK IF USER HAS PERMISSION TO CREATE INVOICE    *
         ******************************************************/

        /*********************************************
         *   VALIDATE INVOICE INFORMATION            *
         ********************************************/

        if (!empty($invoice)) {
            try {
                //  Update the invoice
                $invoice = Invoice::where('id', $invoice_id)->update([
                    'status' => $invoice['status'],
                    'heading' => $invoice['heading'],
                    'reference_no_title' => $invoice['reference_no_title'],
                    'reference_no_value' => $invoice['reference_no_value'],
                    'created_date_title' => $invoice['created_date_title'],
                    'created_date_value' => $invoice['created_date_value'],
                    'expiry_date_title' => $invoice['expiry_date_title'],
                    'expiry_date_value' => $invoice['expiry_date_value'],
                    'sub_total_title' => $invoice['sub_total_title'],
                    'sub_total_value' => $invoice['sub_total_value'],
                    'grand_total_title' => $invoice['grand_total_title'],
                    'grand_total_value' => $invoice['grand_total_value'],
                    'currency_type' => json_encode($invoice['currency_type'], JSON_FORCE_OBJECT),
                    'calculated_taxes' => json_encode($invoice['calculated_taxes'], JSON_FORCE_OBJECT),
                    'invoice_to_title' => $invoice['invoice_to_title'],
                    'customized_company_details' => json_encode($invoice['customized_company_details'], JSON_FORCE_OBJECT),
                    'customized_client_details' => json_encode($invoice['customized_client_details'], JSON_FORCE_OBJECT),
                    'client_id' => $invoice['customized_client_details']['id'],
                    'table_columns' => json_encode($invoice['table_columns'], JSON_FORCE_OBJECT),
                    'items' => json_encode($invoice['items'], JSON_FORCE_OBJECT),
                    'notes' => json_encode($invoice['notes'], JSON_FORCE_OBJECT),
                    'colors' => json_encode($invoice['colors'], JSON_FORCE_OBJECT),
                    'footer' => $invoice['footer'],
                    'trackable_type' => $model_Type,
                    'trackable_id' => $modelId,
                    'company_branch_id' => $user->companyBranch->id,
                    'company_id' => $user->companyBranch->company->id,
                ]);

                $status = 'updated';

                //  If the invoice was created/updated successfully
                if ($invoice) {
                    //  refetch the updated invoice
                    $invoice = Invoice::find($invoice_id);

                    return $invoice;

                    //  Record activity of a invoice created
                    $invoiceCreatedActivity = oq_saveActivity($invoice, $user, ['type' => $status, 'data' => $invoice]);

                    //  Record activity of a invoice authourized
                    $invoiceAuthourizedActivity = oq_saveActivity($invoice, $user, ['type' => 'authourized', 'data' => $invoice]);
                } else {
                    //  Record activity of a failed invoice during creation
                    $invoiceCreatedActivity = oq_saveActivity(null, $user, ['type' => 'fail', 'message' => 'invoice update failed']);
                }

                //  If the invoice was updated successfully
                if ($invoice) {
                    //  Action was executed successfully
                    return oq_api_notify($invoice, 200);
                }
            } catch (\Exception $e) {
                return oq_api_notify_error('Query Error', $e->getMessage(), 404);
            }
        } else {
            //  No resource found
            oq_api_notify_no_resource();
        }
    }

    public function store(Request $request)
    {
        //  Current authenticated user
        $user = auth('api')->user();

        //  Query data
        $model_Type = request('model');                      //  Associated model e.g) invoice
        $modelId = request('modelId');                      //  The id of the associated model
        $invoice = request('invoice');

        /*******************************************************
         *   CHECK IF USER HAS PERMISSION TO CREATE INVOICE    *
         ******************************************************/

        /*********************************************
         *   VALIDATE INVOICE INFORMATION            *
         ********************************************/

        //  Create the invoice
        $invoice = \App\Invoice::create([
            'status' => $invoice['status'],
            'heading' => $invoice['heading'],
            'reference_no_title' => $invoice['reference_no_title'],
            'reference_no_value' => $invoice['reference_no_value'],
            'created_date_title' => $invoice['created_date_title'],
            'created_date_value' => $invoice['created_date_value'],
            'expiry_date_title' => $invoice['expiry_date_title'],
            'expiry_date_value' => $invoice['expiry_date_value'],
            'sub_total_title' => $invoice['sub_total_title'],
            'sub_total_value' => $invoice['sub_total_value'],
            'grand_total_title' => $invoice['grand_total_title'],
            'grand_total_value' => $invoice['grand_total_value'],
            'currency_type' => $invoice['currency_type'],
            'calculated_taxes' => $invoice['calculated_taxes'],
            'invoice_to_title' => $invoice['invoice_to_title'],
            'customized_company_details' => $invoice['customized_company_details'],
            'customized_client_details' => $invoice['customized_client_details'],
            'client_id' => $invoice['customized_client_details']['id'],
            'table_columns' => $invoice['table_columns'],
            'items' => $invoice['items'],
            'notes' => $invoice['notes'],
            'colors' => $invoice['colors'],
            'footer' => $invoice['footer'],
            'trackable_type' => $model_Type,
            'trackable_id' => $modelId,
            'company_branch_id' => $user->companyBranch->id,
            'company_id' => $user->companyBranch->company->id,
        ]);

        $status = 'created';

        //  If the invoice was created/updated successfully
        if ($invoice) {
            //  Update the reference no
            $invoiceNumber = str_pad($invoice->id, 3, '0', STR_PAD_LEFT);
            $invoice->update(['reference_no_value' => $invoiceNumber]);

            //  re-retrieve the instance to get all of the fields in the table.
            $invoice = $invoice->fresh();

            //  Record activity of a invoice created
            $invoiceCreatedActivity = oq_saveActivity($invoice, $user, ['type' => $status, 'data' => $invoice]);

            //  Record activity of a invoice authourized
            $invoiceAuthourizedActivity = oq_saveActivity($invoice, $user, ['type' => 'authourized', 'data' => $invoice]);
        } else {
            //  Record activity of a failed invoice during creation
            $invoiceCreatedActivity = oq_saveActivity(null, $user, ['type' => 'fail', 'message' => 'invoice creation failed']);
        }

        //  return created invoice
        return oq_api_notify($invoice, 201);
    }
}
