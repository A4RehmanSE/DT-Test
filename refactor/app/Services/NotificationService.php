<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class NotificationService implements NotificationServiceInterface
{
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        $msg_text = ($job->customer_physical_type === 'yes') ?
            'Detta är en påminnelse om att du har en ' . $language . ' tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!' :
            'Detta är en påminnelse om att du har en ' . $language . ' tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!';

        // Send push notification logic here
        $this->sendPushNotification($user, $job->id, $data, $msg_text);
        
        // Log event
        Log::info('sendSessionStartRemindNotification', ['job' => $job->id]);
    }
    
    private function sendPushNotification($user, $jobId, $data, $msgText)
    {
        // push notification logic here if needed.
        // Example: Sending push notification using Laravel's notification system
        $user->notify(new SessionStartRemindNotification($jobId, $data, $msgText));
    }
}
