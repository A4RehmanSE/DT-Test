<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionStartRemindNotification extends Notification
{
    use Queueable;

    private $jobId;
    private $data;
    private $msgText;

    public function __construct($jobId, $data, $msgText)
    {
        $this->jobId = $jobId;
        $this->data = $data;
        $this->msgText = $msgText;
    }

    public function via($notifiable)
    {
        return ['mail', 'database']; // Add additional channels as needed (e.g., 'push')
    }

    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject('Session Start Reminder')
            ->line($this->msgText);
    }

    public function toArray($notifiable)
    {
        return [
            'notification_type' => $this->data['notification_type'],
            'job_id' => $this->jobId,
            'message' => $this->msgText,
        ];
    }
}
