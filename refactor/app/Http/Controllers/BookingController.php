<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use App\Http\Requests\JobRequestValidator;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Get the list of jobs based on the user ID or the user type.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function index(Request $request)
    {
        if ($user_id = $request->get('user_id')) {
            // Get jobs for a specific user ID
            $response = $this->repository->getUsersJobs($user_id);
        } elseif ($request->__authenticatedUser->user_type == env('ADMIN_ROLE_ID') || $request->__authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID')) {
            // Get all jobs for admin or superadmin users
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * Get the job details for a specific ID.
     *
     * @param $id The job ID.
     * @return mixed The response.
     */
    public function show($id)
    {
        // Get the job with related translator user
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * Store a new job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function store(JobRequestValidator $request)
    {
        // Validate the request data
        $data = $request->validated(); 

        // Store the job
        $response = $this->repository->store($request->__authenticatedUser, $data);

        return response($response);
    }

    /**
     * Update a job with the given ID.
     *
     * @param $id The job ID.
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;

        // Update the job
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return response($response);
    }

    /**
     * Send an immediate job email.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();

        // Store the job email
        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

    /**
     * Get the job history for a user.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function getHistory(Request $request)
    {
        if ($user_id = $request->get('user_id')) {
            // Get job history for a specific user ID
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    /**
     * Accept a job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // Accept the job
        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    /**
     * Accept a job with the given ID.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

        // Accept the job with the ID
        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * Cancel a job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // Cancel the job
        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * End a job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        // End the job
        $response = $this->repository->endJob($data);

        return response($response);

    }

    /**
     * Mark a job as customer not called.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->all();

        // Mark the job as customer not called
        $response = $this->repository->customerNotCall($data);

        return response($response);

    }

    /**
     * Get potential jobs for a user.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // Get potential jobs for the user
        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

    /**
     * Update the distance feed for a job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->only(['distance', 'time', 'jobid', 'session_time', 'flagged', 'admincomment', 'manually_handled', 'by_admin']);

        $distance = isset($data['distance']) ? $data['distance'] : "";
        $time = isset($data['time']) ? $data['time'] : "";
        $jobid = isset($data['jobid']) ? $data['jobid'] : "";
        $session = isset($data['session_time']) ? $data['session_time'] : "";
        $flagged = isset($data['flagged']) && $data['flagged'] == 'true' ? 'yes' : 'no';
        $manually_handled = isset($data['manually_handled']) && $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $by_admin = isset($data['by_admin']) && $data['by_admin'] == 'true' ? 'yes' : 'no';
        $admincomment = isset($data['admincomment']) ? $data['admincomment'] : "";

        if ($time || $distance) {
            Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::where('id', '=', $jobid)->update(['admin_comments' => $admincomment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manually_handled, 'by_admin' => $by_admin]);
        }

        return response('Record updated!');
    }


    /**
     * Reopen a job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);

        return response($response);
    }

    /**
     * Resend notifications for a job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Resend SMS notifications for a job.
     *
     * @param Request $request The request object.
     * @return mixed The response.
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}
