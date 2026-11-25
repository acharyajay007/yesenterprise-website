<?php

namespace App\Services;

use App\Mail\SubmissionReceived;
use App\Models\Submission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class DeliverSubmission
{
    public function deliver(Submission $submission): bool
    {
        return Cache::lock('submission-delivery-'.$submission->id, 30)->block(5, function () use ($submission): bool {
            $submission->refresh();
            if ($submission->delivery_status === 'delivered') {
                return true;
            }
            $recipient = config('website.enquiry_recipient');
            if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                Log::error('Submission email recipient is not configured.', ['submission_id' => $submission->id]);
                $submission->update(['delivery_status' => 'pending', 'delivery_error' => 'Configure WEBSITE_ENQUIRY_RECIPIENT before retrying.']);

                return false;
            }
            try {
                Mail::to($recipient)->send(new SubmissionReceived($submission));
            } catch (TransportExceptionInterface $exception) {
                report($exception);
                $submission->update(['delivery_status' => 'pending', 'delivery_error' => 'SMTP delivery failed. See server logs and retry.']);

                return false;
            }
            $submission->update(['delivery_status' => 'delivered', 'delivery_error' => null, 'delivered_at' => now()]);

            return true;
        });
    }
}
