<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\v1\Rate;
use App\Models\v1\Branch;
use App\Models\v1\Bureau;
use App\Models\v1\Worker;
use App\Models\v1\Currency;
use App\Models\v1\Passcode;
use Illuminate\Http\Request;
use App\Mail\admin\PassCodeMail;
use App\Models\v1\Administrator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Api\v1\LogController;

class AdminController extends Controller
{
    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'admin_phone_number';
    }


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION REGISTES AN ADMIN AND PROVIDES THEM WITH AN ACCESS TOKEN
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function register(Request $request)
    {

        $validatedData = $request->validate([
            "admin_surname" => "bail|required|max:55",
            "admin_firstname" => "bail|required|max:55",
            "admin_othernames" => "bail|max:55",
            "admin_phone_number" => "bail|required|regex:/(0)[0-9]{9}/|min:10|max:10",
            "admin_email" => "bail|email|required|max:100",
            "admin_pin" => "bail|required|confirmed|min:4|max:8",
            "password" => "bail|required|confirmed|min:8|max:30",
            "admin_scope" => "bail|required"
        ]);

        $validatedData["admin_pin"] = Hash::make($request->admin_pin);
        $validatedData["password"] = bcrypt($request->password);
        $validatedData["admin_flagged"] = false;

        $administrator = Administrator::create($validatedData);

        $accessToken = $administrator->createToken("authToken", [$validatedData["admin_scope"]])->accessToken;

        return response(["administrator" => $administrator, "access_token" => $accessToken]);
    }


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION PROVIDES A REGISTERED ADMIN WITH AN ACCESS TOKEN
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    
    public function login(Request $request)
    {
        $log_controller = new LogController();
        $passcode_controller = new PasscodeController();

        $login_data = $request->validate([
            "admin_phone_number" => "required|regex:/(0)[0-9]{9}/",
            "password" => "required"
        ]);

        if (!auth()->attempt($login_data)) {
            $log_controller->save_log("administrator", $request->admin_phone_number, "Login Admin", "1st-layer login failed");
            return response(["status" => "fail", "message" => "Invalid Credentials"]);
        }

        if (auth()->user()->admin_flagged) {
            $log_controller->save_log("administrator", $request->admin_phone_number, "Login Admin", "1st-layer login failed because admin is flagged");
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $accessToken = auth()->user()->createToken("authToken", [auth()->user()->admin_scope])->accessToken;

        $log_controller->save_log("administrator", $request->admin_phone_number, "Login Admin", "1st-layer login successful");

        $passcode = $passcode_controller->generate_passcode();

        $email_data = array(
            'pass_code' => $passcode,
            'time' => date("F j, Y, g:i a")
        );

        $passcode_controller->save_passcode("administrator", auth()->user()->admin_id, strval($passcode));

        Mail::to(auth()->user()->admin_email)->send(new PassCodeMail($email_data));
        $log_controller->save_log("administrator", $request->admin_phone_number, "Login Admin", "Passcode sent for verification");

        return response([
            "status" => "success",
            "admin_firstname" => auth()->user()->admin_firstname,
            "admin_surname" => auth()->user()->admin_surname,
            "access_token" => $accessToken
        ]);
    }

/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION REVOKES AN ADMIN'S ACCESS TOKEN
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function logout(Request $request)
    {
        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }
        $request->user()->token()->revoke();
        return response(["status" => "success", "message" => "Logged out successfully"]);
    }


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION RESENDS THE PASSCODE USED FOR THE SECOND LAYER LOGIN VERIFICATION
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function resend_passcode(Request $request)
    {
        $log_controller = new LogController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        if (auth()->user()->admin_flagged) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Login Admin", "Resend passcode failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $passcode = Passcode::where([
            'user_id' => auth()->user()->admin_id,
            'user_type' => "administrator",
            'used' => false
        ])
            ->orderBy('passcode_id', 'desc')
            ->take(1)
            ->get();

        if (isset($passcode[0]["user_id"]) && $passcode[0]["user_id"] == auth()->user()->admin_id) {
            Mail::to(auth()->user()->admin_email)->send(new PassCodeMail(['pass_code' => $passcode[0]["passcode"], 'time' => date("F j, Y, g:i a")]));
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Login Admin", "Passcode re-sent for verification");
            return response(["status" => "success", "message" => "Passcode re-sent successfully"]);
        } else {
            return response(["status" => "fail", "message" => "Failed to send passcode. Restart login."]);
        }
    }


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION VERIFIES THE PASSCODE ENTERED
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function verify_passcode(Request $request)
    {
        $log_controller = new LogController();
        $passcode_controller = new PasscodeController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        if (auth()->user()->admin_flagged) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Login Admin", "Passcode verification failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $request->validate([
            "passcode" => "bail|required|max:5"
        ]);

        $passcode = Passcode::where([
            'user_id' => auth()->user()->admin_id,
            'user_type' => "administrator",
            'passcode' => $request->passcode,
            'used' => false
        ])
            ->orderBy('passcode_id', 'desc')
            ->take(1)
            ->get();


        if (isset($passcode[0]["user_id"]) && $passcode[0]["user_id"] == auth()->user()->admin_id) {
            $passcode_controller->update_passcode($passcode[0]["passcode_id"], $passcode[0]["user_type"], $passcode[0]["user_id"], $passcode[0]["passcode"], true);
            return response(["status" => "success", "message" => "Verification successful"]);
        } else {
            return response(["status" => "fail", "message" => "Verification failed. Try with the correct passcode and if this continues, restart login."]);
        }
    }



/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION ADD A NEW CURRENCY TO THE DATABASE
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function add_currency(Request $request)
    {
        $log_controller = new LogController();
        $currency_controller = new CurrencyController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }
    
        if (!$request->user()->tokenCan('add-currency')) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to add currency");
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        $request->validate([
            "currency_full_name" => "bail|required|max:100",
            "currency_abbreviation" => "bail|required|max:3",
            "currency_symbol" => "bail|required|max:20",
            "admin_pin" => "bail|required|min:4|max:8",
        ]);

        if (auth()->user()->admin_flagged) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", "Addition of currency failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        if (!Hash::check($request->admin_pin, auth()->user()->admin_pin)) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", "Addition of currency failed because of incorrect pin");
            return response(["status" => "fail", "message" => "Incorrect pin."]);
        }

        if (Currency::where('currency_abbreviation', '=', $request->currency_abbreviation)->exists()) {
            return response(["status" => "fail", "message" => "Currency already exists. Try editing it instead"]);
        } else {
            $currency_controller->add_currency($request->currency_full_name, $request->currency_abbreviation, $request->currency_symbol, auth()->user()->admin_id);
            $log_text = "New currency added. Name: " . $request->currency_full_name . ". SHORT-NAME: " . $request->currency_abbreviation;
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", $log_text);
            return response(["status" => "success", "message" => "Currency added successfully"]);
        }
    }


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS THE LIST OF ALL THE CURRENCIES
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function get_all_currencies(Request $request)
    {
        $log_controller = new LogController();
        $currency_controller = new CurrencyController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }
    
        if (!$request->user()->tokenCan('view-currencies')) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to view all currencies");
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        if (auth()->user()->admin_flagged) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", "Fetching all currencies failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $currencies =  $currency_controller->get_all_currencies();

        return response(["status" => "success", "message" => "Operation successful", "data" => $currencies]);
    }


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS ONE CURRENCY
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function get_one_currency(Request $request)
    {

        $log_controller = new LogController();
        $currency_controller = new CurrencyController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }
    
        if (!$request->user()->tokenCan('get-one-currency')) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to view one currency");
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        $request->validate([
            "currency_id" => "bail|required|integer",
        ]);

        if (auth()->user()->admin_flagged) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", "Getting one currency failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $this_currency = $currency_controller->get_currency("currency_id", $request->currency_id);
        return response(["status" => "success", "message" => "Operation successful", "data" => $this_currency]);
            
    }

    /*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION SEARCHES FOR CURRENCIES USING A KEYWORD
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function search_for_currency(Request $request)
{

    $log_controller = new LogController();
    $currency_controller = new CurrencyController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (!$request->user()->tokenCan('get-one-currency')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to search for currencies");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    $request->validate([
        "kw" => "bail|required",
    ]);

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", "Searching for currencies failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    $like_keyword = '%' . $request->kw . '%';

    $where_array = array(
        ['currency_full_name', 'LIKE', $like_keyword],
    ); 
    $orwhere_array = array(
        ['currency_abbreviation', 'LIKE', $like_keyword],
    ); 

    $currencies = $currency_controller->search_for_currencies($where_array, $orwhere_array);
    return response(["status" => "success", "message" => "Operation successful", "data" => $currencies]);
        
}


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION ADD A NEW CURRENCY TO THE DATABASE
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
    public function edit_currency(Request $request)
    {
        $log_controller = new LogController();
        $currency_controller = new CurrencyController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }
    
        if (!$request->user()->tokenCan('update-currency')) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to update currency");
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        $request->validate([
            "currency_id" => "bail|required|integer",
            "currency_full_name" => "bail|required|max:100",
            "currency_abbreviation" => "bail|required|max:3",
            "currency_symbol" => "bail|required|max:20",
            "currency_flagged" => "bail|required|integer|max:1",
            "admin_pin" => "bail|required|min:4|max:8",
        ]);

        if (auth()->user()->admin_flagged) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", "Currency editing failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        if (!Hash::check($request->admin_pin, auth()->user()->admin_pin)) {
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", "Currency editing failed because of incorrect pin");
            return response(["status" => "fail", "message" => "Incorrect pin."]);
        }

        if (Currency::where('currency_id', '=', $request->currency_id)->exists()) {
            $log_text = "Currency updated. Name: " . $request->currency_full_name . ". SHORT-NAME: " . $request->currency_abbreviation;
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Currencies Admin", $log_text);
            $currency_controller->update_currency($request->currency_id, $request->currency_full_name, $request->currency_abbreviation, $request->currency_symbol, $request->currency_flagged, auth()->user()->admin_id);
            return response(["status" => "success", "message" => "Currency updated successfully"]);
        } else {
            return response(["status" => "fail", "message" => "Currency does not exists."]);
        }
    }


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION ADD A NEW RATE TO THE DATABASE
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function add_rate(Request $request)
{
    $log_controller = new LogController();
    $currency_controller = new CurrencyController();
    $rate_controller = new RateController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }
    
    if (!$request->user()->tokenCan('add-rate')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to add rate");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    $request->validate([
        "currency_from_id" => "bail|required|integer",
        "currency_to_id" => "bail|required|integer",
        "rate" => "bail|required|regex:/[\d]{1,2}.[\d]{2}/",
        "admin_pin" => "bail|required|min:4|max:8",
    ]);

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Addition of rate failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    if (!Hash::check($request->admin_pin, auth()->user()->admin_pin)) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Addition of rate failed because of incorrect pin");
        return response(["status" => "fail", "message" => "Incorrect pin."]);
    }

    $currency_from = $currency_controller->get_currency("currency_id", $request->currency_from_id);
    $currency_to = $currency_controller->get_currency("currency_id", $request->currency_to_id);
    
    if($currency_from[0]->currency_abbreviation == "" || $currency_to[0]->currency_abbreviation == ""){
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Addition of rate failed because one of the currencies were not found in the database");
        return response(["status" => "fail", "message" => "Currency not found"]);
    }

    $old_rate = Rate::where('rate_ext_id', '=', $rate_controller->make_rate_ext_id($currency_from[0]->currency_abbreviation, $currency_to[0]->currency_abbreviation))->first();

    if (isset($old_rate->rate_id)) {
        $log_text = "Rate updated. RATE-ID" . $old_rate->rate_ext_id . ". RATE: 1: " . $request->rate;
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", $log_text);
        $rate_controller->update_rate($old_rate->rate_id, $old_rate->rate_ext_id, $old_rate->currency_from_id, $old_rate->currency_to_id, $request->rate, auth()->user()->admin_id);
        return response(["status" => "success", "message" => "Rate updated successfully"]);
    } else {
        $log_text = "New rate added. CURRENCY-FROM: " . $currency_from[0]->currency_abbreviation . ". CURRENCY-TO: " . $currency_to[0]->currency_abbreviation . ". RATE: 1: " . $request->rate;
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", $log_text);
        $rate_controller->add_rate($currency_from[0]->currency_id, $currency_from[0]->currency_abbreviation, $currency_to[0]->currency_id, $currency_to[0]->currency_abbreviation, $request->rate, auth()->user()->admin_id);
        return response(["status" => "success", "message" => "Rate added successfully"]);
    }
}



/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS THE LIST OF ALL THE RATES
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function get_all_rates(Request $request)
{
    $log_controller = new LogController();
    $rate_controller = new RateController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }
    
    if (!$request->user()->tokenCan('view-rates')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to view rates");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Fetching all rates failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    $request->validate([
        "page" => "bail|required|integer",
    ]);


    $rates =  $rate_controller->get_all_rates(50);

    return response(["status" => "success", "message" => "Operation successful", "data" => $rates]);
}


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION SEARCHES FOR RATES USING A KEYWORD
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function search_for_rates(Request $request)
{
    $log_controller = new LogController();
    $rate_controller = new RateController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }
    
    if (!$request->user()->tokenCan('view-rates')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Permission denined for trying to view rates");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Rates Admin", "Fetching all rates failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    $request->validate([
        "kw" => "bail|required",
    ]);

    $like_keyword = '%' . $request->kw . '%';

    $where_array = array(
        ['currencies.currency_full_name', 'LIKE', $like_keyword],
    ); 
    $orwhere_array = array(
        ['currencies.currency_abbreviation', 'LIKE', $like_keyword],
    ); 

    $rates = $rate_controller->search_for_rates(50, $where_array, $orwhere_array);
    
    return response(["status" => "success", "message" => "Operation successful", "data" => $rates, "kw" => $request->kw]);
}


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION ADD A NEW BUREAU TO THE DATABASE
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function add_bureau(Request $request)
{
    $log_controller = new LogController();
    $bureau_controller = new BureauController();
    $branch_controller = new BranchController();
    $worker_controller = new WorkerController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }
    
    if (!$request->user()->tokenCan('add-bureau')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Permission denined for trying to add bureau");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Addition of bureau failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    if (!Hash::check($request->admin_pin, auth()->user()->admin_pin)) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Addition of bureau failed because of incorrect pin");
        return response(["status" => "fail", "message" => "Incorrect pin."]);
    }

    $validatedData = $request->validate([
        "bureau_name" => "bail|required|max:200",
        "bureau_hq_gps_address" => "bail|required|max:50",
        "bureau_hq_location" => "bail|required|max:300",
        "bureau_tin" => "bail|required|max:20",
        "bureau_license_no" => "bail|required|max:20",
        "bureau_registration_num" => "bail|required|max:20",
        "bureau_phone_1" => "bail|required|regex:/(0)[0-9]{9}/|min:10|max:10",
        "bureau_phone_2" => "bail|regex:/(0)[0-9]{9}/|min:10|max:10",
        "bureau_email_1" => "bail|email|required|max:100",
        "bureau_email_2" => "bail|email|max:100",
        "worker_surname" => "bail|required|max:55",
        "worker_firstname" => "bail|required|max:55",
        "worker_othernames" => "bail|max:55",
        "worker_gps_address" => "bail|required|max:50",
        "worker_location" => "bail|required|max:300",
        "worker_position" => "bail|required|max:100",
        "worker_phone_number" => "bail|required|regex:/(0)[0-9]{9}/|min:10|max:10",
        "worker_email" => "bail|email|required|max:100",
        "admin_pin" => "bail|required|min:4|max:8"
    ]);

    $validatedData["worker_pin"] = Hash::make(substr($request->bureau_tin,-4));
    $validatedData["password"] = bcrypt($request->bureau_tin);
    $validatedData["worker_flagged"] = false;
    $validatedData["worker_scope"] = "add-currency view-currencies get-one-currency update-currency add-rate view-rates get-one-rate update-rate add-bureau view-bureaus get-one-bureau update-bureau";
    
    $old_bureau = Bureau::where('bureau_tin', '=', $validatedData["bureau_tin"])->first();

    if(isset($old_bureau->bureau_tin)){
        $bureau = $bureau_controller->update_bureau($old_bureau->bureau_id, $validatedData["bureau_name"], $validatedData["bureau_hq_gps_address"],
         $validatedData["bureau_hq_location"], $validatedData["bureau_tin"], $validatedData["bureau_license_no"], $validatedData["bureau_registration_num"],
         $validatedData["bureau_phone_1"], $validatedData["bureau_phone_2"], $validatedData["bureau_email_1"], 
         $validatedData["bureau_email_2"], false, auth()->user()->admin_id);
    } else {
        $bureau = $bureau_controller->save_bureau($validatedData["bureau_name"], $validatedData["bureau_hq_gps_address"],
         $validatedData["bureau_hq_location"], $validatedData["bureau_tin"], $validatedData["bureau_license_no"], $validatedData["bureau_registration_num"],
         $validatedData["bureau_phone_1"], $validatedData["bureau_phone_2"], $validatedData["bureau_email_1"], 
         $validatedData["bureau_email_2"], false, auth()->user()->admin_id);
    }
    
    $old_branch = Branch::where('branch_ext_id', '=', $branch_controller->make_branch_ext_id($validatedData["bureau_hq_gps_address"], $validatedData["bureau_tin"], $validatedData["bureau_phone_1"]))->first();
    
    if(isset($old_branch->branch_id)){
        $branch = $branch_controller->update_branch($old_branch->bureau_id, $validatedData["bureau_hq_gps_address"], $validatedData["bureau_hq_location"],
        $validatedData["bureau_phone_1"], $validatedData["bureau_phone_2"], $validatedData["bureau_email_1"], $validatedData["bureau_email_2"], 
        "admin", auth()->user()->admin_id, false, $bureau->bureau_id);
    } else {
        $branch = $branch_controller->save_branch("Head Office", $validatedData["bureau_tin"], $validatedData["bureau_hq_gps_address"], $validatedData["bureau_hq_location"],
        $validatedData["bureau_phone_1"], $validatedData["bureau_phone_2"], $validatedData["bureau_email_1"], $validatedData["bureau_email_2"], 
        "admin", auth()->user()->admin_id, false, true, $bureau->bureau_id);
    }
    
    $old_worker = Worker::where('worker_ext_id', '=', $worker_controller->make_worker_ext_id($bureau->bureau_id, $branch->branch_id,  $validatedData["worker_phone_number"]))->first();
     
    if(isset($old_worker->worker_id)){
        $worker_controller->update_worker($old_worker->worker_id, $validatedData["worker_surname"], $validatedData["worker_firstname"], $validatedData["worker_othernames"],
        $validatedData["worker_gps_address"], $validatedData["worker_location"], $validatedData["worker_position"], $validatedData["worker_scope"], 
        false, $validatedData["worker_phone_number"], $validatedData["worker_email"], $validatedData["worker_pin"], $validatedData["password"], 
        "admin", auth()->user()->admin_id, $branch->branch_id, $bureau->bureau_id);
    } else {
        $worker_controller->save_worker($validatedData["worker_surname"], $validatedData["worker_firstname"], $validatedData["worker_othernames"],
        $validatedData["worker_gps_address"], $validatedData["worker_location"], $validatedData["worker_position"], $validatedData["worker_scope"], 
        false, true, $validatedData["worker_phone_number"], $validatedData["worker_email"], $validatedData["worker_pin"], $validatedData["password"], 
        "admin", auth()->user()->admin_id, $branch->branch_id, $bureau->bureau_id);
    }

    return response(["status" => "success", "message" => "Bureau added/updated successfully"]);
    
}

/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS THE LIST OF ALL THE BUREAUS
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function get_all_bureaus(Request $request)
{
    $log_controller = new LogController();
    $bureau_controller = new BureauController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }
    
    if (!$request->user()->tokenCan('view-bureaus')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Permission denined for trying to view rates");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Fetching all bureaus failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    $request->validate([
        "page" => "bail|required|integer",
    ]);


    $bureaus =  $bureau_controller->get_all_bureaus(50);
        
    for ($i=0; $i < count($bureaus); $i++) { 

        $this_branch = DB::table('branches')
        ->where("bureau_id", "=", $bureaus[$i]->bureau_id)
        ->count();
        $bureaus[$i]->num_of_branches = $this_branch;
    }

    return response(["status" => "success", "message" => "Operation successful", "data" => $bureaus]);
}

/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS SEARCHES FOR A LIST OF BUREAUS
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function search_for_bureaus(Request $request)
{
    $log_controller = new LogController();
    $bureau_controller = new BureauController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }
    
    if (!$request->user()->tokenCan('view-bureaus')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Permission denined for trying to search for bureaus");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Searching for bureaus failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }


    $request->validate([
        "kw" => "bail|required",
    ]);

    $like_keyword = '%' . $request->kw . '%';

    $where_array = array(
        ['workers.worker_was_first', '=', true],
        ['bureaus.bureau_name', 'LIKE', $like_keyword],
    ); 

    $bureaus = $bureau_controller->search_for_bureaus(1, $where_array, []);

    for ($i=0; $i < count($bureaus); $i++) { 

        $this_branch = DB::table('branches')
        ->where("bureau_id", "=", $bureaus[$i]->bureau_id)
        ->count();
        $bureaus[$i]->num_of_branches = $this_branch;
    }
    
    return response(["status" => "success", "message" => "Operation successful", "data" => $bureaus, "kw" => $request->kw]);
}

/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS ONE BUREAU
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function get_one_bureau(Request $request)
{

    $log_controller = new LogController();
    $bureau_controller = new BureauController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (!$request->user()->tokenCan('get-one-bureau')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Permission denined for trying to view one bureaus");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    $request->validate([
        "bureau_id" => "bail|required|integer",
    ]);

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Bureaus Admin", "Getting one bureau failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }


    $where_array = array(
        ['workers.worker_was_first', '=', true],
        ['bureaus.bureau_id', '=', $request->bureau_id],
    ); 


    $this_bureau = $bureau_controller->get_bureau([], $where_array);


    return response(["status" => "success", "message" => "Operation successful", "data" => $this_bureau]);
        
}

/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION CHANGES A WORKERS PASSWORD
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function change_password(Request $request)
{
    $log_controller = new LogController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Security|Admin", "Change password failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }
    
    $request->validate([
        "admin_phone_number" => "bail|required|regex:/(0)[0-9]{9}/|min:10|max:10",
        "current_password" => "bail|required|min:8|max:30",
        "password" => "bail|required|confirmed|min:8|max:30",
        "admin_pin" => "bail|required|min:4|max:8",
    ]);

    if (!Hash::check(request()->current_password, auth()->user()->password)) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Security|Admin", "Change password failed because of incorrect current password");
        return response(["status" => "fail", "message" => "Incorrect password."]);
    }

    if (!Hash::check($request->admin_pin, auth()->user()->admin_pin)) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Security|Admin", "Change password failed because of incorrect pin");
        return response(["status" => "fail", "message" => "Incorrect pin."]);
    }

    $admin = Administrator::where('admin_phone_number', auth()->user()->admin_phone_number)->first();


    if ($admin != null && $admin->admin_phone_number == $request->admin_phone_number) {
        $admin->password =  bcrypt($request->password);
        $admin->save();
        $userTokens =  auth()->user()->tokens;
        foreach($userTokens as $token) {
            $token->revoke();   
        }
        $log_controller->save_log("administrator", $request->admin_phone_number, "Security|Admin", "Password changed");
        return response(["status" => "success", "message" => "Password changed successfully."]);
    } else {
        return response(["status" => "fail", "message" => "Failed to validate operation."]);
    }
}

public function add_admin(Request $request)
{
    $log_controller = new LogController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (!$request->user()->tokenCan('add-admin')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Permission denined for trying to add administrator");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Adding administrator failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    if (!Hash::check($request->admin_pin, auth()->user()->admin_pin)) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Security|Admin", "Addming administrator failed because of incorrect pin");
        return response(["status" => "fail", "message" => "Incorrect pin."]);
    }

    $validatedData = $request->validate([
        "admin_surname" => "bail|required|max:55",
        "admin_firstname" => "bail|required|max:55",
        "admin_othernames" => "bail|max:55",
        "admin_phone_number" => "bail|required|regex:/(0)[0-9]{9}/|min:10|max:10",
        "admin_email" => "bail|email|required|max:100",
        "add_currency" => "bail|nullable|regex:(add-currency)",
        "view_currencies" => "bail|nullable|regex:(view-currencies)",
        "get_one_currency" => "bail|nullable|regex:(get-one-currency)",
        "update_currency" => "bail|nullable|regex:(update-currency)",
        "add_rate" => "bail|nullable|regex:(add-rate)",
        "view_rates" => "bail|nullable|regex:(view-rates)",
        "get_one_rate" => "bail|nullable|regex:(get-one-rate)",
        "update_rate" => "bail|nullable|regex:(update-rate)",
        "add_bureau" => "bail|nullable|regex:(add-bureau)",
        "view_bureaus" => "bail|nullable|regex:(view-bureaus)",
        "get_one_bureau" => "bail|nullable|regex:(get-one-bureau)",
        "update_bureau" => "bail|nullable|regex:(update-bureau)",
        "add_admin" => "bail|nullable|regex:(add-admin)",
        "view_admins" => "bail|nullable|regex:(view-admins)",
        "edit_admin" => "bail|nullable|regex:(edit-admin)",
        "view_reports" => "bail|nullable|regex:(view-reports)",
        "admin_pin" => "bail|required|min:4|max:8",
    ]);



    $admin = Administrator::where('admin_phone_number', $request->admin_phone_number)->first();
    $admin2 = Administrator::where('admin_email', $request->admin_email)->first();

    if ($admin != null && $admin->admin_phone_number == $request->admin_phone_number) {
        return response(["status" => "fail", "message" => "The phone number is registered to another administrator."]);
    } else if ($admin2 != null && $admin2->admin_email == $request->admin_email) {
        return response(["status" => "fail", "message" => "The email address is registered to another administrator."]);
    } else {
        $validatedData["admin_pin"] = Hash::make(substr($request->admin_phone_number,-4));
        $validatedData["password"] = bcrypt($request->admin_phone_number);
        $validatedData["admin_flagged"] = false;
    
        $validatedData["admin_scope"] = "";

        if(trim($request->add_currency) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"] . $request->add_currency;
        }
        if(trim($request->view_currencies) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_currencies;
        }
        if(trim($request->get_one_currency) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->get_one_currency;
        }
        if(trim($request->update_currency) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->update_currency;
        }
        if(trim($request->add_rate) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->add_rate;
        }
        if(trim($request->view_rates) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_rates;
        }
        if(trim($request->get_one_rate) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->get_one_rate;
        }
        if(trim($request->update_rate) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->update_rate;
        }
        if(trim($request->add_bureau) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->add_bureau;
        }
        if(trim($request->view_bureaus) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_bureaus;
        }
        if(trim($request->get_one_bureau) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->get_one_bureau;
        }
        if(trim($request->update_bureau) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->update_bureau;
        }
        if(trim($request->add_admin) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->add_admin;
        }
        if(trim($request->view_admins) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_admins;
        }
        if(trim($request->edit_admin) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->edit_admin;
        }
        if(trim($request->view_reports) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_reports;
        }
    
    
        $administrator = Administrator::create($validatedData);
        return response(["status" => "success", "message" => "Administrator added successsfully."]);
    }


}


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS THE LIST OF ALL THE BUREAUS
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function get_all_admins(Request $request)
{
    $log_controller = new LogController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }
    
    if (!$request->user()->tokenCan('view-admins')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Permission denined for trying to view administrators");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Fetching administrators failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    $request->validate([
        "page" => "bail|required|integer",
    ]);



    $admins = DB::table('administrators')
    ->select('administrators.*')
    ->simplePaginate(50);

    for ($i=0; $i < count($admins); $i++) { 

        $this_admin = DB::table('administrators')
        ->where("admin_id", "=", $admins[$i]->creator_admin_id)
        ->get();
        
        $admins[$i]->creator_name = $this_admin[0]->admin_firstname . " " . $this_admin[0]->admin_surname;
    }

    return response(["status" => "success", "message" => "Operation successful", "data" => $admins]);
}


/*
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| THIS FUNCTION GETS ONE ADMIN
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
|
*/
public function get_one_admin(Request $request)
{

    $log_controller = new LogController();
    $admin_controller = new AdminController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (!$request->user()->tokenCan('view-admins')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Permission denined for trying to view one admin");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    $request->validate([
        "admin_id" => "bail|required|integer",
    ]);

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Getting one admin failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }


    $where_array = array(
        ['administrator.admin_id', '=', $request->admin_id],
    ); 

    $this_admin = DB::table('administrators')
    ->where("admin_id", "=", $request->admin_id)
    ->get();
    


    return response(["status" => "success", "message" => "Operation successful", "data" => $this_admin]);
        
}


public function edit_admin(Request $request)
{
    $log_controller = new LogController();

    if (!Auth::guard('api')->check()) {
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (!$request->user()->tokenCan('edit-admin')) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Permission denined for trying to update administrator");
        return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
    }

    if (auth()->user()->admin_flagged) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Updating administrator failed because admin is flagged");
        $request->user()->token()->revoke();
        return response(["status" => "fail", "message" => "Account access restricted"]);
    }

    if (!Hash::check($request->admin_pin, auth()->user()->admin_pin)) {
        $log_controller->save_log("administrator", auth()->user()->admin_id, "Administrators|Admin", "Addming administrator failed because of incorrect pin");
        return response(["status" => "fail", "message" => "Incorrect pin."]);
    }

    $validatedData = $request->validate([
        "admin_id" => "bail|required|integer",
        "admin_surname" => "bail|required|max:55",
        "admin_firstname" => "bail|required|max:55",
        "admin_othernames" => "bail|max:55",
        "admin_flagged" => "bail|required|integer",
        "add_currency" => "bail|nullable|regex:(add-currency)",
        "view_currencies" => "bail|nullable|regex:(view-currencies)",
        "get_one_currency" => "bail|nullable|regex:(get-one-currency)",
        "update_currency" => "bail|nullable|regex:(update-currency)",
        "add_rate" => "bail|nullable|regex:(add-rate)",
        "view_rates" => "bail|nullable|regex:(view-rates)",
        "get_one_rate" => "bail|nullable|regex:(get-one-rate)",
        "update_rate" => "bail|nullable|regex:(update-rate)",
        "add_bureau" => "bail|nullable|regex:(add-bureau)",
        "view_bureaus" => "bail|nullable|regex:(view-bureaus)",
        "get_one_bureau" => "bail|nullable|regex:(get-one-bureau)",
        "update_bureau" => "bail|nullable|regex:(update-bureau)",
        "add_admin" => "bail|nullable|regex:(add-admin)",
        "view_admins" => "bail|nullable|regex:(view-admins)",
        "edit_admin" => "bail|nullable|regex:(edit-admin)",
        "view_reports" => "bail|nullable|regex:(view-reports)",
        "admin_pin" => "bail|required|min:4|max:8",
    ]);
    

    $where_array = array(
        ['administrators.admin_id', '=', $request->admin_id]
    ); 


    $admin = Administrator::where($where_array)->first();

    if ($admin != null && $admin->admin_id == $request->admin_id) {
    
        $validatedData["admin_scope"] = "";

        if(trim($request->add_currency) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"] . $request->add_currency;
        }
        if(trim($request->view_currencies) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_currencies;
        }
        if(trim($request->get_one_currency) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->get_one_currency;
        }
        if(trim($request->update_currency) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->update_currency;
        }
        if(trim($request->add_rate) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->add_rate;
        }
        if(trim($request->view_rates) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_rates;
        }
        if(trim($request->get_one_rate) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->get_one_rate;
        }
        if(trim($request->update_rate) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->update_rate;
        }
        if(trim($request->add_bureau) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->add_bureau;
        }
        if(trim($request->view_bureaus) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_bureaus;
        }
        if(trim($request->get_one_bureau) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->get_one_bureau;
        }
        if(trim($request->update_bureau) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->update_bureau;
        }
        if(trim($request->add_admin) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->add_admin;
        }
        if(trim($request->view_admins) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_admins;
        }
        if(trim($request->edit_admin) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->edit_admin;
        }
        if(trim($request->view_reports) != ""){
            $validatedData["admin_scope"] = $validatedData["admin_scope"]  .  " " .  $request->view_reports;
        }
    
        $admin = Administrator::find($request->admin_id);
        $admin->admin_surname = $request->admin_surname; 
        $admin->admin_firstname = $request->admin_firstname;
        $admin->admin_othernames = $request->admin_othernames;
        $admin->admin_scope = $validatedData["admin_scope"];
        $admin->admin_flagged = $request->admin_flagged;
        $admin->save();
        return response(["status" => "success", "message" => "Administrator updated successsfully."]);
    } else {
        return response(["status" => "fail", "message" => "Administrator not found"]);
    }


}


}
