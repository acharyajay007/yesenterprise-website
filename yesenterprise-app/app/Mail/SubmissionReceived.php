<?php

namespace App\Mail;

use App\Models\Submission;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SubmissionReceived extends Mailable
{
    public function __construct(public Submission $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Yes Enterprise: new '.$this->submission->type.' #'.$this->submission->id);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.submission');
    }
}
