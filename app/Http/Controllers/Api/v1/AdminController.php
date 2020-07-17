<?php

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use App\Mail\admin\PassCodeMail;
use App\Models\v1\Administrator;
use App\Models\v1\Passcode;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Api\v1\LogController;
use App\Models\v1\Currency;

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

    /**
     * THIS FUNCTION REGISTES AN ADMIN AND PROVIDES THEM WITH AN ACCESS TOKEN
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
            "password" => "bail|required|confirmed|min:8|max:30"
        ]);

        $validatedData["admin_pin"] = bcrypt($request->admin_pin);
        $validatedData["password"] = bcrypt($request->password);
        $validatedData["admin_flagged"] = false;


        $administrator = Administrator::create($validatedData);

        $accessToken = $administrator->createToken("authToken")->accessToken;

        return response(["administrator" => $administrator, "access_token" => $accessToken]);
    }

    /**
     * THIS FUNCTION PROVIDES A REGISTERED ADMIN WITH AN ACCESS TOKEN
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

        if(auth()->user()->admin_flagged){
            $log_controller->save_log("administrator", $request->admin_phone_number, "Login Admin", "1st-layer login failed because admin is flagged");
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $accessToken = auth()->user()->createToken("authToken")->accessToken;

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

    /**
     * THIS FUNCTION REVOKES AN ADMIN'S ACCESS TOKEN
     */
    public function logout(Request $request)
    {
        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }
        $request->user()->token()->revoke();
        return response(["status" => "success", "message" => "Logged out successfully"]);
    }



    /**
     * THIS FUNCTION RESENDS THE PASSCODE USED FOR THE SECOND LAYER LOGIN VERIFICATION
     */
    public function resend_passcode(Request $request)
    {
        $log_controller = new LogController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        if(auth()->user()->admin_flagged){
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Login Admin", "Resend passcode failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $passcode = Passcode::where([
            'user_id' => auth()->user()->admin_id,
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


    /**
     * THIS FUNCTION VERIFIES THE PASSCODE ENTERED
     */
    public function verify_passcode(Request $request)
    {
        $log_controller = new LogController();
        $passcode_controller = new PasscodeController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        if(auth()->user()->admin_flagged){
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Login Admin", "Passcode verification failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $request->validate([
            "passcode" => "bail|required|max:5"
        ]);

        $passcode = Passcode::where([
            'user_id' => auth()->user()->admin_id,
            'passcode' => $request->passcode,
            'used' => false
        ])
        ->orderBy('passcode_id', 'desc')
        ->take(1)
        ->get();

        if (isset($passcode[0]["user_id"]) && $passcode[0]["user_id"] == auth()->user()->admin_id) {
            $passcode = Passcode::find($passcode[0]["passcode_id"]);
            $passcode->used = true;
            $passcode->save();
            $passcode_controller->update_passcode($passcode[0]["passcode_id"], $passcode[0]["user_type"], $passcode[0]["user_id"], $passcode[0]["passcode"], true);
            return response(["status" => "success", "message" => "Verification successful"]);
        } else {
            return response(["status" => "fail", "message" => "Verification failed. Try with the correct passcode and if this continues, restart login."]);
        }
    }


    /**
     * THIS FUNCTION ADD A NEW CURRENCY TO THE DATABASE
     */
    public function add_currency(Request $request)
    {
        $log_controller = new LogController();
        $currency_controller = new CurrencyController();

        if (!Auth::guard('api')->check()) {
            return response(["status" => "fail", "message" => "Permission Denied. Please log out and login again"]);
        }

        if(auth()->user()->admin_flagged){
            $log_controller->save_log("administrator", auth()->user()->admin_id, "Login Admin", "Passcode verification failed because admin is flagged");
            $request->user()->token()->revoke();
            return response(["status" => "fail", "message" => "Account access restricted"]);
        }

        $request->validate([
            "currency_full_name" => "bail|required|max:100",
            "currency_abbreviation" => "bail|required|max:3",
            "currency_symbol" => "bail|required|max:20",
        ]);

        if($currency_controller->add_currency($request->currency_full_name, $request->currency_abbreviation, $request->currency_symbol)){
            return response(["status" => "success", "message" => "Currency added successfuly"]);
        } else {
            return response(["status" => "fail", "message" => "Operation failed."]);
        }



    }
    
}
