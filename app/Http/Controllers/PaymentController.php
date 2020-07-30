<?php  
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Validator, Input, Redirect; 
use App\Models\Course;
use App\Models\Transaction;
use App\Models\Credit;
use App\Models\Config;
use App\Models\CourseTaken;
use App\Models\Instructor;
use App\Models\LessonProgress;


class PaymentController extends Controller {

	public function __construct()
	{
		
	}
	
	function getSuccess()
	{
		$gateway = \Omnipay::gateway('paypal');

		//get the transaction id from session, so as to update the status and order details
		$transaction = Transaction::find(\Session::get('transaction_id'));

		if($transaction->amount!=0){
		//get values from db and pass it to paypal
		$express_checkout = Config::get_options('settingPayment');

		$gateway->setUsername($express_checkout['username']);
		$gateway->setPassword($express_checkout['password']);
		$gateway->setSignature($express_checkout['signature']);
		$gateway->setTestMode($express_checkout['test_mode']);

		$response = $gateway->completePurchase([
		'amount'    => floatval($transaction->amount),
		'returnUrl' => url('payment/success'),
		'cancelUrl' => url('payment/failure'),
		])->send();

		$response_data = $response->getData();
		}else{
			$response_data = array(
					"TOKEN" => 'success',
					"status" => "succeeded",
					"Timestamp"=>time(),
					'ACK' =>'Success',
			);
		}

		$lesson_progress_ids = json_decode(\Session::get('lesson_progress_ids'));

		if(isset($response_data['ACK'])){
			//process only if the acknowledgement is success
			if($response_data['ACK'] == 'Success')
			{
				$save_transaction['id'] = \Session::get('transaction_id');
				$save_transaction['order_details'] = json_encode($response_data);
				$save_transaction['status'] = 'completed';
				$transaction_id = $this->save_transaction($save_transaction);
			
				foreach($lesson_progress_ids as $lesson_progress_id) {
					$lesson_progress = LessonProgress::find($lesson_progress_id);
					$lesson_progress->status = 'incomplete';
					$lesson_progress->save();
				}

				\Session::forget('transaction_id');

				return view('site/course/success')->with('title', 'Lesson')->with('status', 'success')->with('transId', $transaction_id);
			}else{
				return view('site/course/success')->with('status', 'failed')->with('transId', \Session::get('transaction_id'))->with('title', 'Lesson');
			}
		}else{
			return view('site/course/success')->with('status', 'failed')->with('transId', \Session::get('transaction_id'))->with('title', 'Lesson');
		}
	}

	function getFailure(Request $request)
	{
		$save_transaction['id'] = \Session::get('transaction_id');
		$save_transaction['status'] = 'failed';
		$save_transaction['order_details'] = json_encode(array('token'=>$request->input('token')));
		$transaction_id = $this->save_transaction($save_transaction);

		\Session::forget('transaction_id');

		return view('site/course/success')->with('status', 'failed')->with('transId', $transaction_id)->with('title', 'Lesson');
	}

	function paymentForm(Request $request)
	{
		// get all values from form
		$payment_method = $request->input('payment_method');
		$paypal_amount = $amount = $request->input('lesson_amount');
		$schedule = json_decode($request->input('lesson_schedule'));
		$instructor_id = $request->input('instructor_id');
		$lesson_count = $request->input('lesson_count');
		$gateway = \Omnipay::gateway('paypal');

			$paypal = Config::get_options('settingPayment');
			
			//get values from db and pass it to paypal
			$gateway->setUsername($paypal['username']);
			$gateway->setPassword($paypal['password']);
			$gateway->setSignature($paypal['signature']);

			if($paypal['test_mode'] == 'true')
			{
				$gateway->setTestMode(true);
			}

			$lesson_progress_ids = array();
			foreach($schedule as $event) {
				//save the lesson progress in DB
				$lesson_progress = new LessonProgress;
				$lesson_progress->instructor_id = $instructor_id;
				$lesson_progress->student_id = \Auth::user()->id;
				$lesson_progress->start_datetime = $event->start;
				$lesson_progress->end_datetime = $event->end;
				$lesson_progress->status = 'pending';
				$lesson_progress->save();
				$lesson_progress_ids[] = $lesson_progress->id;
			}
			
			//save the transaction details in DB
			$transaction = new Transaction;
			$transaction->user_id = \Auth::user()->id;
			$transaction->amount = floatval($amount);
			$transaction->status = 'pending';
			$transaction->payment_method = $payment_method;

			$transaction->save();

			\Session::put('lesson_progress_ids', json_encode($lesson_progress_ids));
			\Session::put('transaction_id', $transaction->id);
			\Session::save();
			
			if($amount==0){
				return Redirect::to('payment/success');
			}

			$response = $gateway->purchase([
						'amount'    => floatval($paypal_amount),
						'returnUrl' => url('payment/success'),
						'cancelUrl' => url('payment/failure'),
						])->send();

			if ($response->isRedirect()) 
			{
            	// redirect to offsite payment gateway
           		 $response->redirect();
        	} 
        	else 
        	{
            	// payment failed: display message to customer
            	return Redirect::to('payment/form')->withErrors(['payment_error', true]);
        	}
	}

	function save_transaction($data)
	{
		//check if the status is completed
		$completed = in_array('completed', $data) ? true : false;
		
		//check if there is transaction id, if so find it or else create a new one
		$transaction = array_key_exists('id', $data) ? Transaction::find($data['id']) : new Transaction;
		//insert all the values in object
		foreach ($data as $key => $value) 
		{
			$transaction->$key = $value;
		}
		$transaction->save();


		//process the invoice generation(get transaction details and save it in invoice table), if the status is completed
		if($completed)
		{
			//save credits
			//$this->save_credits($transaction->id);
		}
		return $transaction->id;
	}

	function save_credits($transaction_id)
	{
		//get transaction details
		$transaction = Transaction::find($transaction_id);

		//get commision percentage from db
		
		$commision_percentage = Config::get_option('settingGeneral', 'admin_commission');
		//calculate the credits
		$amount = $transaction->amount;
		$admin_credit = ($amount * $commision_percentage)/100;
		$instructor_credit = $amount - $admin_credit;

		//get instructor id for the course id
		$course = Course::find($transaction->course_id);
		$instructor_id = $course->instructor_id;

		//save credit for instructor
		$credit = new Credit;
		$credit->transaction_id = $transaction_id;
		$credit->instructor_id = $instructor_id;
		$credit->user_id = $transaction->user_id;
		$credit->is_admin = 0;
		$credit->credits_for = 1;
		$credit->credit = $instructor_credit;
		$credit->created_at = time();

		$credit->save();

        //update the total credits
        $instructor = Instructor::find($instructor_id)->increment('total_credits', $instructor_credit);
        
		//save credit for instructor
		$credit = new Credit;
		$credit->transaction_id = $transaction_id;
		$credit->instructor_id = 0;
		$credit->course_id = $transaction->course_id;
		$credit->user_id = $transaction->user_id;
		$credit->is_admin = 1;
		$credit->credits_for = 2;
		$credit->credit = $admin_credit;
		$credit->save();
	}

}