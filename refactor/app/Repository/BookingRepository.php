<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use App\Helpers\BookingHelper;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use App\Services\NotificationServiceInterface;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;
    private $notificationService;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer, NotificationServiceInterface $notificationService)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->notificationService = $notificationService;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get jobs for a user.
     *
     * @param int $user_id The user ID.
     * @return array An array containing emergency jobs, normal jobs, current user, and user type.
     */
    public function getUsersJobs($user_id)
    {
        // Find the user based on the given user ID
        $cuser = User::find($user_id);
        $usertype = ''; // Initialize the user type
        $emergencyJobs = []; // Initialize an array to hold emergency jobs
        $normalJobs = []; // Initialize an array to hold normal jobs

        if ($cuser && $cuser->is('customer')) {
            // If the user exists and is a customer
            // Get jobs for the customer user type
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
            
            $usertype = 'customer'; // Set the user type as 'customer'
        } elseif ($cuser && $cuser->is('translator')) {
            // If the user exists and is a translator
            // Get jobs for the translator user type
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            
            $usertype = 'translator'; // Set the user type as 'translator'
        }
        
        if ($jobs) {
            // If jobs exist
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    // If the job is marked as immediate
                    $emergencyJobs[] = $jobitem; // Add it to the emergency jobs array
                } else {
                    $normalJobs[] = $jobitem; // Otherwise, add it to the normal jobs array
                }
            }
            
            // Check user's involvement for each normal job
            $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                // Call the checkParticularJob method to check the user's involvement
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all(); // Sort the normal jobs by due date
        }

        // Return the results as an array
        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype
        ];
    }

    /**
     * Get the job history for a user.
     *
     * @param int $user_id The user ID.
     * @param \Illuminate\Http\Request $request The request object.
     * @return array An array containing emergency jobs, normal jobs, jobs, current user, user type, number of pages, and current page number.
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $pagenum = $request->get('page') ? $request->get('page') : "1"; // Set the page number
        
        // Find the user based on the given user ID
        $cuser = User::find($user_id);
        $usertype = ''; // Initialize the user type
        $jobs = ''; // Initialize the jobs

        if ($cuser && $cuser->is('customer')) {
            // If the user exists and is a customer
            // Get jobs for the customer user type with related models
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);
            
            $usertype = 'customer'; // Set the user type as 'customer'

        } elseif ($cuser && $cuser->is('translator')) {
            // If the user exists and is a translator
            // Get translator jobs with historic status and the specified page number
            $jobs = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $usertype = 'translator'; // Set the user type as 'translator'
            
        }

        $numpages = $jobs ? ceil($jobs->total() / 15) : 0; // Calculate the number of pages
         // Return the result jobs, current user, user type, number of pages, and current page number
         return [
            'jobs' => $jobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
            'numpages' => $numpages,
            'pagenum' => $pagenum
        ];
    }


    /**
     * Store a newly created job.
     *
     * @param $user
     * @param JobRequestValidator $request
     * @return mixed
     */
    public function store($user, $validatedData)
    {
        // Get the authenticated user
        $cuser = $user;

        // Set the default immediate time
        $immediatetime = 5;

        // Get the consumer type
        $consumer_type = $user->userMeta->consumer_type;

        // Set default data as an empty array
        $data = [];

        // Check if the user is a customer
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {

            // Assign validated data to local variables
            $data['from_language_id'] = $validatedData['from_language_id'];
            $data['immediate'] = $validatedData['immediate'];

            // Handle non-immediate jobs
            if ($validatedData['immediate'] == 'no') {
                $data['due_date'] = $validatedData['due_date'];
                $data['due_time'] = $validatedData['due_time'];
                $data['customer_phone_type'] = isset($validatedData['customer_phone_type']) ? 'yes' : 'no';
                $data['duration'] = $validatedData['duration'];
            } else {
                $data['duration'] = $validatedData['duration'];
            }

            // Set customer phone type
            $data['customer_phone_type'] = isset($validatedData['customer_phone_type']) ? 'yes' : 'no';

            // Set customer physical type
            $data['customer_physical_type'] = isset($validatedData['customer_physical_type']) ? 'yes' : 'no';
            $response['customer_physical_type'] = $data['customer_physical_type'];

            // Handle immediate jobs
            if ($validatedData['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $validatedData['due_date'] . " " . $validatedData['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in the past";
                    return $response;
                }
            }

            // Set job gender and certified options
            if (in_array('male', $validatedData['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $validatedData['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $validatedData['job_for'])) {
                $data['certified'] = 'normal';
            } else if (in_array('certified', $validatedData['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $validatedData['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $validatedData['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $validatedData['job_for']) && in_array('certified', $validatedData['job_for'])) {
                $data['certified'] = 'both';
            } else if (in_array('normal', $validatedData['job_for']) && in_array('certified_in_law', $validatedData['job_for'])) {
                $data['certified'] = 'n_law';
            } else if (in_array('normal', $validatedData['job_for']) && in_array('certified_in_helth', $validatedData['job_for'])) {
                $data['certified'] = 'n_health';
            }

            // Set job type based on consumer type
            if ($consumer_type == 'rwsconsumer')
                $data['job_type'] = 'rws';
            else if ($consumer_type == 'ngo')
                $data['job_type'] = 'unpaid';
            else if ($consumer_type == 'paid')
                $data['job_type'] = 'paid';

            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = isset($validatedData['by_admin']) ? $validatedData['by_admin'] : 'no';

            $job = $cuser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = [];

            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }

            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;

            // Fire job creation event
            // Event::fire(new JobWasCreated($job, $data, '*'));

            // Send notification to suitable translators
            // $this->sendNotificationToSuitableTranslators($job->id, $data, '*');

        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator cannot create a booking";
        }

        return $response;
    }


    /**
     * Store job email and return response
     *
     * @param array $data The job email data
     * @return array The response data
     */
    public function storeJobEmail($data)
    {
        // Extract the user type from the data
        $user_type = $data['user_type'];

        // Find the job based on the provided user_email_job_id
        $job = Job::findOrFail(@$data['user_email_job_id']);

        // Update the job with the user_email and reference values
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';

        // Retrieve the user associated with the job
        $user = $job->user()->get()->first();

        // Update job fields related to address if address is provided in the data
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }

        // Save the job
        $job->save();

        // Determine the email and name based on user_email availability
        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }

        // Compose the email subject
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        // Prepare the data to be sent in the email
        $send_data = [
            'user' => $user,
            'job' => $job
        ];

        // Send the email
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        // Prepare and return the response
        $response = [
            'type' => $user_type,
            'job' => $job,
            'status' => 'success'
        ];

        // Fire the JobWasCreated event
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    /**
     * Convert job object to data array
     *
     * @param mixed $job The job object
     * @return array The job data array
     */
    public function jobToData($job)
    {
        $data = []; // Initialize an empty data array

        // Define the list of attributes to copy from the job object to the data array
        $attributes = [
            'id' => 'job_id',
            'from_language_id',
            'immediate',
            'duration',
            'status',
            'gender',
            'certified',
            'due',
            'job_type',
            'customer_phone_type',
            'customer_physical_type',
            'town' => 'customer_town',
            'user->userMeta->customer_type'
        ];

        // Copy the attributes from the job object to the data array
        foreach ($attributes as $key => $value) {
            if (is_numeric($key)) {
                $data[$value] = $job->$value;
            } else {
                $data[$value] = $job->$key;
            }
        }

        // Extract the due date and time from the 'due' attribute
        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        // Add the due date and time to the data array
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        // Set up the 'job_for' array based on gender and certified attributes
        $data['job_for'] = [];

        if ($job->gender != null) {
            $genderMapping = [
                'male' => 'Man',
                'female' => 'Kvinna'
            ];

            if (isset($genderMapping[$job->gender])) {
                $data['job_for'][] = $genderMapping[$job->gender];
            }
        }

        if ($job->certified != null) {
            $certifiedMapping = [
                'both' => ['Godkänd tolk', 'Auktoriserad'],
                'yes' => ['Auktoriserad'],
                'n_health' => ['Sjukvårdstolk'],
                'law' => ['Rättstolk'],
                'n_law' => ['Rättstolk']
            ];

            if (isset($certifiedMapping[$job->certified])) {
                $data['job_for'] = array_merge($data['job_for'], $certifiedMapping[$job->certified]);
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * End a job and send session-ended emails
     *
     * @param array $post_data The post data containing the job ID and other information
     */
    public function jobEnd($post_data = [])
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];

        // Retrieve the job detail with translatorJobRel relationship
        $job = Job::with('translatorJobRel')->findOrFail($jobId);

        $dueDate = $job->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->first();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }

        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $userId = $post_data['userid'];

        Event::fire(new SessionEnded($job, ($userId == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'lön'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completedDate;
        $tr->completed_by = $userId;
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of a user with their ID
     *
     * @param int $user_id The user ID
     * @return array The potential jobs
     */
    public function getPotentialJobsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        
        $job_type = 'unpaid';
        if ($translator_type == 'professional') {
            $job_type = 'paid'; /* Show all jobs for professionals */
        } elseif ($translator_type == 'rwstranslator') {
            $job_type = 'rws'; /* For rwstranslator only show rws jobs */
        } elseif ($translator_type == 'volunteer') {
            $job_type = 'unpaid'; /* For volunteers only show unpaid jobs */
        }
        
        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);
        
        foreach ($job_ids as $k => $v) {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown) {
                unset($job_ids[$k]);
            }
        }

        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }

    /**
     * Send push notifications to suitable translators for a job
     *
     * @param Job $job The job object
     * @param array $data Additional data for the push notification
     * @param int $exclude_user_id The user ID to exclude from sending the notification
     */
    public function sendNotificationTranslator(Job $job, array $data = [], int $exclude_user_id)
    {
        $suitableTranslators = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $exclude_user_id)
            ->where(function ($query) {
                $query->where('immediate', 'no')
                    ->orWhereNotExists(function ($subquery) {
                        $subquery->select('user_id')
                            ->from('user_meta')
                            ->where('user_meta.user_id', '=', 'users.id')
                            ->where('not_get_emergency', 'yes');
                    });
            })
            ->where(function ($query) use ($job) {
                $query->whereHas('potentialJobs', function ($subquery) use ($job) {
                    $subquery->where('job_id', $job->id);
                })
                ->orWhereDoesntHave('potentialJobs');
            })
            ->get();

        $translator_array = [];
        $delpay_translator_array = [];

        foreach ($suitableTranslators as $translator) {
            if (BookingHelper::isNeedToSendPush($translator->id)) {
                if (BookingHelper::isNeedToDelayPush($translator->id)) {
                    $delpay_translator_array[] = $translator;
                } else {
                    $translator_array[] = $translator;
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = ($data['immediate'] == 'no') ?
            'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'] :
            'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $msg_text = [
            'en' => $msg_contents
        ];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false); // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS notifications to translators and returns the count of translators
     *
     * @param Job $job The job object
     * @return int The count of translators
     */
    public function sendSMSNotificationToTranslator(Job $job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = BookingHelper::convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?? $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // Determine the message based on the job type
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            $message = $physicalJobMessageTemplate; // Physical job
        } else {
            $message = $phoneJobMessageTemplate; // Phone job or both (default to phone job)
        }

        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            // Send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Sends OneSignal push notifications to specific users with user-tags
     *
     * @param array $users The list of users
     * @param int $job_id The job ID
     * @param array $data The data for the push notification
     * @param array $msg_text The message text for the push notification
     * @param bool $is_need_delay Whether the push notification needs to be delayed or not
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') == 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = BookingHelper::getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] === 'suitable_job') {
            if ($data['immediate'] === 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $client = new Client();
        $response = $client->post('https://onesignal.com/api/v1/notifications', [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => $onesignalRestAuthKey],
            'json' => $fields,
            'verify' => false
        ]);

        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response->getBody()]);
    }

    /**
     * Update a job
     *
     * @param int $id The job ID
     * @param array $data The updated data
     * @param User $cuser The current user
     * @return string[] The result of the update
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::findOrFail($id);
        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first();

        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', null)->first();
        }

        $log_data = [];
        $langChanged = false;

        $changeTranslator = BookingHelper::changeTranslator($current_translator, $data, $job);

        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = BookingHelper::changeDue($job->due, $data['due']);

        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);

        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();

            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }

            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }

            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }
    }


    /**
     * Check if the job status has changed and return the result.
     *
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;

        // Check if the status has changed
        if ($old_status != $data['status']) {
            // Based on the old status, handle the status change accordingly
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            // If the status change was successful, store the old and new status in the log data
            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];

                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }

        // Return the result with no status change
        return ['statusChanged' => $statusChanged];
    }

    /**
     * Change the timed-out status of a job
     *
     * @param Job $job The job object
     * @param array $data The updated data
     * @param bool $changedTranslator Flag indicating if the translator has changed
     * @return bool Whether the status was changed or not
     */
    private function changeTimedoutStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        $old_status = $job->status;
        $job->status = $data['status'];

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        if ($data['status'] === 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();

            $job_data = $this->jobToData($job);
            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;

            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*'); // send Push all suitable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            return true;
        }

        return false;
    }


    /**
     * Change the completed status of a job
     *
     * @param Job $job The job object
     * @param array $data The updated data
     * @return bool Whether the status was changed or not
     */
    private function changeCompletedStatus(Job $job, array $data): bool
    {
        $job->status = $data['status'];

        if ($data['status'] === 'timedout') {
            if ($data['admin_comments'] === '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }


    /**
     * Change the started status of a job
     *
     * @param Job $job The job object
     * @param array $data The updated data
     * @return bool Whether the status was changed or not
     */
    private function changeStartedStatus(Job $job, array $data): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] === '') {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] === 'completed') {
            $user = $job->user;
            if ($data['session_time'] === '') {
                return false;
            }
            $interval = $data['session_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $translatorJob = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
            $email = $translatorJob->user->email;
            $name = $translatorJob->user->name;
            $dataEmail = [
                'user'         => $translatorJob,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }

        $job->save();
        return true;
    }

    /**
     * Change the pending status of a job
     *
     * @param Job $job The job object
     * @param array $data The updated data
     * @param bool $changedTranslator Whether the translator was changed or not
     * @return bool Whether the status was changed or not
     */
    private function changePendingStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];

        $user = $job->user;
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            // Call the notification service method
            $this->notificationService->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->notificationService->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        $allowedStatuses = ['timedout'];

        if (in_array($data['status'], $allowedStatuses)) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        $allowedStatuses = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

        if (in_array($data['status'], $allowedStatuses)) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }

                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
                $email = $translator->user->email;
                $name = $translator->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $translator,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }

            $job->save();
            return true;
        }

        return false;
    }


    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;

        $data = [
            'user' => $user,
            'job'  => $job
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $data['user'] = $translator;

        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => "Tyvärr har ingen tolk accepterat er bokning: ({$language}, {$job->duration}min, {$job->due}). Vänligen pröva boka om tiden."
        ];

        if (BookingHelper::isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, BookingHelper::isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for admin job cancellation
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $data['due_date'] = $due_Date[0];
        $data['due_time'] = $due_Date[1];

        $data['job_for'] = [];
        if ($job->gender !== null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified !== null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }


    /**
     * Send session start reminder notification
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $customer_physical_type = $job->customer_physical_type;
        $msg_text = [
            "en" => 'Du har nu fått ' . ($customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen') . ' för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!',
        ];

        if (BookingHelper::isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, BookingHelper::isNeedToDelayPush($user->id));
        }
    }


    /**
     * Accept a job and send confirmation email
     * @param $data
     * @param $user
     * @return array
     */
    public function acceptJob($data, $user)
    {
        $adminEmail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $cUser = $user;

        if (!Job::isTranslatorAlreadyBooked($jobId, $cUser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cUser->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();

                $user = $job->user()->first();
                $email = $job->user_email ?? $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job' => $job
                ];

                $mailer = new AppMailer();
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }

            /* @todo
            Add flash message here.
            */
            $jobs = $this->getPotentialJobs($cUser);
            $response = [
                'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'status' => 'success'
            ];
        } else {
            $response = [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        return $response;
    }


    /**
     * Accept a job with the job ID
     * @param $job_id
     * @param $cuser
     * @return array
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminEmail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->first();
                $mailer = new AppMailer();

                $email = $job->user_email ?? $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job' => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $notificationData = [
                    'notification_type' => 'job_accepted',
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                ];
                if (BookingHelper::isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $notificationData, $msg_text, BookingHelper::isNeedToDelayPush($user->id));
                }

                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }


    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        // 1. Check if cancellation is before 24 hours
        if ($job->due->diffInHours(Carbon::now()) >= 24) {
            $job->status = 'withdrawbefore24';
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            return $response;
        }

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = 'withdrawafter24';
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            // 2. Notify translator and customer if cancellation is within 24 hours
            if ($translator) {
                $data = [
                    'notification_type' => 'job_cancelled'
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];
                if (BookingHelper::isNeedToSendPush($translator->id)) {
                    $users_array = [$translator];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, BookingHelper::isNeedToDelayPush($translator->id));
                }
            }

            // Increase customer's number of bookings if cancellation is within 24 hours
            $customer = $job->user()->first();
            if ($customer) {
                $customer->number_of_bookings++;
                $customer->save();
            }

        } else {
            // 3. Treat cancellation as an executed session
            $customer = $job->user()->first();
            if ($customer) {
                $data = [
                    'notification_type' => 'job_cancelled'
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                ];
                if (BookingHelper::isNeedToSendPush($customer->id)) {
                    $users_array = [$customer];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, BookingHelper::isNeedToDelayPush($customer->id));
                }
            }
            $job->status = 'pending';
            $job->created_at = Carbon::now();
            $job->will_expire_at = TeHelper::willExpireAt($job->due, Carbon::now());
            $job->save();
            Job::deleteTranslatorJobRel($translator->id, $job_id);

            $data = $this->jobToData($job);

            $this->sendNotificationTranslator($job, $data, $translator->id);
            $response['status'] = 'success';
        }

        return $response;
    }


    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $translator_type = $cuser_meta->translator_type;
        $job_type = BookingHelper::getJobType($translator_type);

        $languages = UserLanguages::where('user_id', $cuser->id)->get();
        $userlanguage = $languages->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') {
                unset($job_ids[$k]);
            }

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '') && $job->customer_physical_type === 'yes' && !$checktown) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        // Check if job status is not 'started', return success
        if ($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();

        // Get email for sending the session ended notification
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }

        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        // Fire session ended event
        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();

        $response['status'] = 'success';
        return $response;
    }



    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';
    
        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
    
        return ['status' => 'success'];
    }
    

    /**
     * Get all jobs based on the provided filters and user type.
     *
     * @param Request $request
     * @param null $limit
     * @return mixed
    */
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            // Superadmin role specific filters
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                    return ['count' => $allJobs->count()];
                }
            }

            // Common filters for superadmin and other users
            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                if (is_array($requestdata['id'])) {
                    $allJobs->whereIn('id', $requestdata['id']);
                } else {
                    $allJobs->where('id', $requestdata['id']);
                }
                $requestdata = array_only($requestdata, ['id']);
            }

            // Apply filters
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            // Add more filters...

            // Add specific conditions for each filter_timetype
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                // Apply filters for 'created' time type
                // Add more conditions...
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                // Apply filters for 'due' time type
                // Add more conditions...
            }

            // Add more filters...

            // Apply common ordering and relationships
            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            // Handle limit parameter
            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        } else {
            // Other user specific filters

            // Apply filters...
            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }
            // Add more filters...

            // Apply common ordering and relationships
            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            // Handle limit parameter
            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        }

        return $allJobs;
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

   /**
     * Reopen a job.
     *
     * @param array $request
     * @return array
     */
    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        // Prepare data for the new job
        $data = [
            'created_at' => date('Y-m-d H:i:s'),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => Carbon::now()
        ];

        // Prepare data for reopening the job
        $datareopen = [
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], Carbon::now())
        ];

        if ($job['status'] != 'timedout') {
            // Update the existing job to reopen it
            $affectedRows = Job::where('id', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            // Create a new job based on the existing job to reopen it
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }

        // Update translators associated with the job
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

}