<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReviewsController;
use App\Http\Controllers\ManageReviewsController;
use App\Models\Event;
use App\Models\Booking;

use App\Http\Middleware\SubOrganizer;
use Classiebit\Eventmie\Middleware\Authenticate;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/



Route::get('/', function () {
    if(!file_exists(storage_path()."/installed")) {
        return redirect('/license');
    }

    return view('welcome');
});

Route::get('/license', 'App\Http\Controllers\LicenseController@index');
Route::get('/52cab7070ba5124895a63a3703f66893232', function() {
    return redirect('/install');
});

Route::bind('event', function ($value) {
    return \App\Models\Event::orWhere(['slug' =>  $value, 'short_url' => $value])->firstOrFail();
});

Route::get('/invoice/download/bookings/{booking}', 'App\Http\Controllers\Eventmie\DownloadsController@downloadInvoice')->name('invoice');

Route::post('/paystack/webhook', '\App\Http\Controllers\Eventmie\BookingsController@handlePaystackWebhook')
    ->name('paystack.webhook')
    ->withoutMiddleware([
        \App\Http\Middleware\SubOrganizer::class,
        \App\Http\Middleware\Authenticate::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ]);
    
/* set local timezone */
Route::post('/set/local_timezone', function (\Illuminate\Http\Request $request) {
   
    session(['local_timezone' => $request->local_timezone]);

    return response()->json(['success' => 'success'], 200);

})->name('eventmie.local_timezone');

Route::group([
    'prefix' => config('eventmie.route.prefix'),
], function()  {

    /* Voyager */
    Route::group([
        'namespace' => 'App\Http\Controllers\Eventmie\Voyager',
        'prefix' => config('eventmie.route.prefix').'/'.config('eventmie.route.admin_prefix'),
    ], function ()  {
        
        $controller     = 'BookingsController';
        
        /* Override Voyager bulk bookings Routes */
        Route::get('/bookings/bulk', "$controller@bulk_bookings")->name('voyager.bookings.bulk_bookings');  

        /* Override Voyager bulk bookings Routes */
        Route::get('/bookings/bulk/edit/{id}', "$controller@bulk_bookings_edit")->name('voyager.bookings.bulk_edit');  

        /* Override Voyager bulk bookings Routes */
        Route::put('/bookings/bulk/update/{id}', "$controller@bulk_bookings_update")->name('voyager.bookings.bulk_update');  
        
        /* Override Voyager bulk bookings Routes */
        Route::get('/bookings/bulk/delete/{id}', "$controller@bulk_bookings_delete")->name('voyager.bookings.bulk_delete'); 
        
        /* Override Voyager bulk bookings Routes */
        Route::get('/bookings/bulk/show/{id}', "$controller@bulk_bookings_show")->name('voyager.bookings.bulk_show'); 

        /* Override Voyager bulk bookings Routes */
        Route::get('/bookings/bulk/zip/{ticket_id}/{bulk_code}', "\App\Http\Controllers\Eventmie\DownloadsController@create_bulk_zip")->name('voyager.bookings.bulk_zip');
        
        /* Override Voyager bulk bookings Routes */
        Route::get('/bookings/bulk/export/{ticket_id}/{bulk_code}', "$controller@bulk_export_attendees")->name('voyager.bookings.bulk_export');

        
        
    });
}); 

Route::group([
    'middleware' => ['sub-organizer'],
], function()  {

    // reviews
    Route::resource('reviews', ReviewsController::class);
    Route::get('/manage_reviews', [ManageReviewsController::class, 'index'])->name('manage_reviews.index');
    Route::post('/manage_reviews', [ManageReviewsController::class, 'update'])->name('manage_reviews.update');

    /* Glists */
    Route::prefix('/myglists')->group(function () {
        $controller = 'App\Http\Controllers\GlistsController';
    
        
        Route::get('/', "$controller@index")->name('myglists_index');

        Route::post('/create/glist', "$controller@create_glist")->name('create_glist');
        
        // for dropdown myglists where orgnizer add guest and choose glist from dropdwon
        Route::post('/dropdown', "$controller@get_myglist")->name('get_myglist');

        // for pagination gilist where show all glist
        Route::get('/pagination', "$controller@pagination_myglists")->name('pagination_myglist');

        Route::post('/add/glist', "$controller@add_to_glist")->name('add_to_glist');

        Route::get('/export/guest/email/{glist}', "$controller@export_emails")->name('export_emails');

        Route::post('/delete/glist', "$controller@delete_glist")->name('delete_glist');

        Route::post('/send/bluk/email', "$controller@send_bluk_email")->name('send_bluk_email');

        Route::post('/send/bluk/email/all', "$controller@send_bluk_email_to_all")->name('send_bluk_email_to_all');
    
    });

    /* Guests */
    Route::prefix('/myguests')->group(function () {
        $controller = 'App\Http\Controllers\GuestsController';
        Route::get('/{glist}', "$controller@index")->name('myguests_index');
        Route::get('/', "$controller@get_myguests")->name('get_myguests');
        Route::post('/add/guest', "$controller@add_guest")->name('add_guest');
        Route::post('/delete/guest', "$controller@delete_guest")->name('delete_guest');
        Route::post('/edit/guest', "$controller@edit_guest")->name('edit_guest');
        
    });

    /* Separate sub_organizers */
    Route::prefix('/sub_organizers')->group(function () {
        $controller = 'App\Http\Controllers\SubOrganizerController';
        
        Route::get('/', "$controller@index")->name('sub_organizer.index');
        Route::get('/get_sub_organizers', "$controller@get_sub_organizers")->name('get_sub_organizers');

        Route::post('/edit_sub_organizer', "$controller@edit_sub_organizer")->name('edit_sub_organizer');

    });


    if(file_exists(storage_path()."/installed")) {
        Eventmie::routes();
    }

    Route::get('email/verify',  'App\Http\Controllers\Eventmie\Auth\VerificationController@show')->name('verification.notice')->withoutMiddleware([SubOrganizer::class]);
    Route::middleware([Authenticate::class])->get('email/verify/{id}',  'App\Http\Controllers\Eventmie\Auth\VerificationController@verify')->name('verification.verify')->withoutMiddleware([SubOrganizer::class]);
    Route::get('email/resend',  'App\Http\Controllers\Eventmie\Auth\VerificationController@resend')->name('verification.resend')->withoutMiddleware([SubOrganizer::class]);
 
 
    /* Clone Event */
    Route::prefix('/clone')->group(function () {
        $controller = 'App\Http\Controllers\CloneEventController';
        Route::get('/events/{event}', "$controller@clone_event")->name('clone_event');
    
    });

    Route::group([
        'prefix' => config('eventmie.route.prefix'),
    ], function()  {

        /* My Events (organiser) */
        Route::prefix('/myevents')->group(function () {
            
            $controller = 'App\Http\Controllers\Eventmie\MyEventsController';
            
            // make sub organizers by organizers
            Route::post('/save/sub/organizers', "$controller@save_sub_organizers")->name('save_sub_organizers');
            
            //create users by organizers
            Route::post('/organizer/create/user', "$controller@organizer_create_user")->name('organizer_create_user');

            
            //get sub-organizers users by organizers
            Route::post('get/sub-organizers', "$controller@get_organizer_users")->name('get_organizer_users');

            //delete seatchart
            Route::post('delete/seatchart', "$controller@delete_seatchart")->name('delete_seatchart');

            
        });

        /* Voyager Events Controller*/

        /* Voyager */
        Route::group([
            'namespace' => 'App\Http\Controllers\Eventmie\Voyager',
            'prefix' => config('eventmie.route.prefix').'/'.config('eventmie.route.admin_prefix'),
        ], function ()  {
            $controller     = 'EventsController';
            
            /* Override Voyager Events Routes */
            Route::get('/events', "$controller@index")->name('voyager.events.index');  
            
        });

        /* Voyager UserController */
        Route::group([
            'namespace' => 'App\Http\Controllers\Eventmie\Voyager',
            'prefix' => config('eventmie.route.prefix').'/'.config('eventmie.route.admin_prefix'),
        ], function ()  {
            $controller     = 'VoyagerUserController';
            
            /* Override Voyager Events Routes */
            Route::post('/users', "$controller@store")->name('voyager.users.store');
            Route::put('/users/{id}', "$controller@update")->name('voyager.users.update');  
            
        });

        
    });    

    /*Guest Controller For Checkout Guest*/
    Route::group([
        'prefix' => 'guest',
        'as'    => 'guest.',
        'middleware' => ['guest', 'common'],
    ], function () {
        $controller = 'App\Http\Controllers\GuestController';
        
        Route::Post('/register', "$controller@registerGuest")->name('register');

        
    });




    /*Organiser events */
    Route::group([
        'prefix' => 'organiser',
        
    ], function () {
        $controller = 'App\Http\Controllers\OrganiserController';
        
        Route::get('/{name?}', "$controller@show")->name('organiser_show');
    });

    /*Organiser events */
    Route::group([
        'prefix' => 'promocodes',
        
    ], function () {
        $controller = 'App\Http\Controllers\PromocodesController';
        
        Route::get('/get', "$controller@get_promocodes")->name('get_promocodes');

        Route::get('/get/ticket/{ticketd_id}', "$controller@get_ticket_promocodes")->name('get_ticket_promocodes');

        Route::post('/apply/', "$controller@apply_promocodes")->name('apply_promocodes');

    });



    /* Private Event */
    Route::prefix('/private')->group(function () {
        $controller = 'App\Http\Controllers\PrivateEventController';
        Route::post('/events', "$controller@save_password")->name('private_event');
        Route::post('/verify_event_password', "$controller@verify_event_password")->name('verify_event_password');
    });

    /* Stripe Direct Checkout */
    Route::prefix('/stripe')->group(function () {
        $controller = 'App\Http\Controllers\StripeDirectController';
    
        Route::get('/response',"$controller@stripeResponse")->name('stripe_response');

        Route::get('/checkout', "$controller@redirectStripeCheckout")->name('stripe_checkout');

        //--- redirect after extra auhentication stripe 3d after3DAuthentication
        Route::get('/extra/authentication/{id}',"$controller@after3DAuthentication")->name('direct_after3DAuthentication');
    });

    /* Connect Stripe Account to Direct Checkout */
    Route::prefix('/connet')->group(function () {
        $controller = 'App\Http\Controllers\StripeConnectController';
    
        Route::get('/stripe', "$controller@createStripeAccount")->name('connect_stripe');

        Route::get('/stripe/response', "$controller@stripeAccountResponse")->name('response_connect_stripe');

    });

    /* Create Attendee On Checkout Page */
    Route::prefix('/attendee')->group(function () {
        $controller = 'App\Http\Controllers\AttendeeController';
    
        Route::post('/add/attendee', "$controller@add_attendee")->name('add_attendee');
    
    });

    /* My Bookings (customers) */
    Route::prefix('/mybookings')->group(function () {
        $controller = '\App\Http\Controllers\Eventmie\MyBookingsController';

        // API
        Route::get('/customer/event', "$controller@get_customer_events")->name('customer_events');
    });

    /* Download Ticket */
    Route::prefix('/download')->group(function ()  {
        $controller = '\App\Http\Controllers\Eventmie\DownloadsController';
        
        Route::post('/ticket/', "$controller@getQrCode")->name('get_qrcode');  
    });

    Route::prefix('/seatschart/')->group(function () {
        
        $controller = 'App\Http\Controllers\SeatChartController';
        
        Route::post('upload',"$controller@upload_seatchart")->name('upload_seatchart');

        Route::post('disable_enable_seatchart',"$controller@disable_enable_seatchart")->name('disable_enable_seatchart');
    });
    
    Route::prefix('/seats/')->group(function () {
            
        $controller = 'App\Http\Controllers\SeatsController';
        
        Route::post('save',"$controller@save_seats")->name('save_seats');
    
        Route::post('delete',"$controller@delete_seat")->name('delete_seat');
        
        Route::post('disable',"$controller@disable_seat")->name('disable_seat');
        Route::post('enable',"$controller@enable_seat")->name('enable_seat');
        
        
    });

    /* Pos sub-organizers */
    Route::group([
        'prefix' => 'pos-bookings',
        'as'    => 'pos.',
        'middleware' => ['pos'],
    ], function () {
        $controller = 'App\Http\Controllers\PosController';
        
        Route::get('/index', "$controller@index")->name('index');
    
        Route::get('/booking/{id}', "$controller@show")->name('show');  

        // API
        Route::get('/api/bookings', "$controller@bookings")->name('bookings');
        Route::post('/api/edit_bookings', "$controller@edit_bookings")->name('edit_bookings');
        Route::get('/api/events', "$controller@events")->name('events'); 
        
    });

    /* Pos sub-organizers */
    Route::group([
        'prefix' => 'scanner-bookings',
        'as'    => 'scanner.',
        'middleware' => ['scanner'],
    ], function () {
        $controller = 'App\Http\Controllers\ScannerController';
        
        Route::get('/index', "$controller@index")->name('index');
    
        Route::get('/booking/{id}', "$controller@show")->name('show');  

        // API
        Route::get('/api/bookings', "$controller@bookings")->name('bookings');
        Route::post('/api/edit_bookings', "$controller@edit_bookings")->name('edit_bookings');
        Route::get('/api/events', "$controller@events")->name('events'); 
        
    });



    //--- redirect after extra auhentication stripe 3d after3DAuthentication
    Route::get('extra/authentication/{id}','\App\Http\Controllers\Eventmie\BookingsController@after3DAuthentication')->name('after3DAuthentication');

    Route::get('/pay/bitpay_response', '\App\Http\Controllers\Eventmie\BookingsController@bitpayPaymentResponse')->name('bitpay_response');

        
    //paystack routes start
    Route::post('/payment/paystack', '\App\Http\Controllers\Eventmie\BookingsController@redirectToGateway')->name('payment_paystack');
    Route::get('/paystack/payment/callback', '\App\Http\Controllers\Eventmie\BookingsController@handleGatewayCallback')->name('paystack.callback');
    //paystack routes end
});