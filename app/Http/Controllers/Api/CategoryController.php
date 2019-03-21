<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Category;

class CategoryController extends Controller
{
    public function index()
    {
        //  Category Instance
        $data = ( new Category() )->initiateGetAll();
        $success = $data['success'];
        $response = $data['response'];

        //  If the categories were found successfully
        if ($success) {
            //  If this is a success then we have the paginated list of categories
            $categories = $response;

            //  Action was executed successfully
            return oq_api_notify($categories, 200);
        }

        //  If the data was not a success then return the response
        return $response;
    }
}
