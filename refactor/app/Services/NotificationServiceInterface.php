<?php

namespace App\Services;

interface NotificationServiceInterface
{
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration);
}
