<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use DTApi\Models\Translator;
use Carbon\Carbon;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Helpers\TeHelper;

final class BookingHelper
{

    /**
     * Check if the translator has changed and return the result.
     *
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    public static function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];

        // Check if the current translator exists or if new translator data is provided
        if (
            !is_null($current_translator) ||
            (isset($data['translator']) && $data['translator'] != 0) ||
            $data['translator_email'] != ''
        ) {
            // Check if the translator has changed
            if (!is_null($current_translator) && (
                    (isset($data['translator']) && $current_translator->user_id != $data['translator']) ||
                    $data['translator_email'] != ''
                ) && (isset($data['translator']) && $data['translator'] != 0)
            ) {
                // Retrieve the new translator's ID based on the provided email
                if ($data['translator_email'] != '') {
                    $data['translator'] = DB::table('users')->where('email', $data['translator_email'])->value('id');
                }

                // Create a new translator record
                $new_translator = Translator::create([
                    'user_id' => $data['translator'],
                    'job_id' => $job->id
                ]);

                // Mark the current translator as canceled
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();

                // Store the old and new translator email in the log data
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];

                $translatorChanged = true;
            } elseif (
                is_null($current_translator) &&
                isset($data['translator']) &&
                ($data['translator'] != 0 || $data['translator_email'] != '')
            ) {
                // Retrieve the new translator's ID based on the provided email
                if ($data['translator_email'] != '') {
                    $data['translator'] = DB::table('users')->where('email', $data['translator_email'])->value('id');
                }

                // Create a new translator record
                $new_translator = Translator::create([
                    'user_id' => $data['translator'],
                    'job_id' => $job->id
                ]);

                // Store the new translator email in the log data
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];

                $translatorChanged = true;
            }

            // Return the result with the translator change information and log data
            if ($translatorChanged) {
                return [
                    'translatorChanged' => $translatorChanged,
                    'new_translator' => $new_translator,
                    'log_data' => $log_data
                ];
            }
        }

        // Return the result with no translator change
        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * Check if the due date has changed and return the result.
     *
     * @param $old_due
     * @param $new_due
     * @return array
    */
    public static function changeDue($old_due, $new_due)
    {
        $dateChanged = $old_due != $new_due;
        
        if ($dateChanged) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            
            return [
                'dateChanged' => $dateChanged,
                'log_data' => $log_data
            ];
        }

        return [
            'dateChanged' => $dateChanged
        ];
    }

    /**
     * Convert number of minutes to hour and minute variant
     *
     * @param int    $time    The number of minutes
     * @param string $format  The format for the output (default: '%02dh %02dmin')
     *
     * @return string  The converted time in the specified format
     */
    public static function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) { // If the time is less than 60 minutes, return the time in minutes format
            return $time . 'min';
        } else if ($time === 60) { // If the time is exactly 60 minutes, return '1h'
            return '1h';
        }

        // Calculate the number of hours and minutes
        $hours = floor($time / 60);
        $minutes = ($time % 60);

        // Format the output using the provided format and return the result
        return sprintf($format, $hours, $minutes);
    }

    /**
     * Function to check if the push notification needs to be delayed
     *
     * @param int $user_id The user ID
     * @return bool True if push needs to be delayed, false otherwise
     */
    public static function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }
        
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        
        return ($not_get_nighttime == 'yes');
    }

    /**
     * Checks if it is necessary to send a push notification
     *
     * @param int $user_id The user ID
     * @return bool Whether to send the push notification or not
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        return $not_get_notification !== 'yes';
    }

    /**
     * Get potential translators for a job
     *
     * @param Job $job The job object
     * @return Collection The collection of potential translators
     */
    public function getPotentialTranslators(Job $job): Collection
    {
        $job_type = $job->job_type;

        if ($job_type === 'paid') {
            $translator_type = 'professional';
        } else if ($job_type === 'rws') {
            $translator_type = 'rwstranslator';
        } else if ($job_type === 'unpaid') {
            $translator_type = 'volunteer';
        }

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            if ($job->certified === 'yes' || $job->certified === 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified === 'law' || $job->certified === 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } else if ($job->certified === 'health' || $job->certified === 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified === 'normal' || $job->certified === 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } else if ($job->certified === null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $blacklist);

        return $users;
    }

    /**
     * Generate user_tags string from users array for creating OneSignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = [];

        foreach ($users as $oneUser) {
            $user_tags[] = [
                'key' => 'email',
                'relation' => '=',
                'value' => strtolower($oneUser->email),
            ];
        }

        return json_encode($user_tags);
    }

    public static function getJobType($translator_type)
    {
        if ($translator_type === 'professional') {
            return 'paid';
        } elseif ($translator_type === 'rwstranslator') {
            return 'rws';
        } elseif ($translator_type === 'volunteer') {
            return 'unpaid';
        }

        return 'unpaid'; // Default job type
    }

}